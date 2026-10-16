<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Actor;
use App\Core\Csv;
use App\Core\Db;
use App\Core\Pagination;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Leads\Vocab;
use App\Modules\Leads\Visibility;

/**
 * Reports: business performance, grouped by the question being asked. Every count is over the SAME cohort definition as the Attribution page
 * and the Dashboard (live leads created in the range, workspace time zone, test leads left out unless asked, only what the caller may see),
 * so a figure and the list behind it always agree, and the funnel steps are nested (each one a subset of the one before it).
 * Estimates and completed sales are never mixed: pipeline value is an estimate typed on leads; revenue is the sum of recorded sales.
 */
final class ReportsService
{
    public const FUNNEL = [
        ['key' => 'leads', 'label' => 'Leads created', 'help' => 'Leads created in the period (live, not archived).'],
        ['key' => 'qualified', 'label' => 'Qualified or further', 'help' => 'Leads that ever reached Qualified, a site visit, a booking or a sale. A lead that was qualified and then lost still counts.'],
        ['key' => 'visits', 'label' => 'Reached a site visit', 'help' => 'Leads with a completed site visit, or that went on to a booking or a sale.'],
        ['key' => 'booked', 'label' => 'Reached a booking', 'help' => 'Leads that had a property reserved, or went on to a sale. Booking is a milestone, not revenue.'],
        ['key' => 'won', 'label' => 'Won', 'help' => 'Leads currently in a Won stage.'],
    ];

    /** Where a lead is now, in five plain groups (the pipeline's own stages vary by industry; their meaning does not). */
    public const FLOW_STAGES = [
        ['key' => 'new', 'label' => 'New'], ['key' => 'talking', 'label' => 'In conversation'], ['key' => 'qualified', 'label' => 'Qualified'],
        ['key' => 'booked', 'label' => 'Booked or negotiating'], ['key' => 'won', 'label' => 'Won'], ['key' => 'closed', 'label' => 'Lost or not a fit'],
    ];
    private const STATUS_GROUP = ['open' => 'new', 'contacted' => 'talking', 'qualified' => 'qualified', 'viewing_scheduled' => 'booked', 'negotiation' => 'booked', 'won' => 'won'];

    /** First-reply speed buckets: `under` is the exclusive upper bound in minutes (null = no bound). */
    public const REPLY_BUCKETS = [
        ['key' => 'm5', 'label' => 'Under 5 min', 'under' => 5], ['key' => 'm15', 'label' => '5–15 min', 'under' => 15], ['key' => 'm30', 'label' => '15–30 min', 'under' => 30],
        ['key' => 'h1', 'label' => '30–60 min', 'under' => 60], ['key' => 'h4', 'label' => '1–4 hours', 'under' => 240], ['key' => 'h24', 'label' => '4–24 hours', 'under' => 1440],
        ['key' => 'late', 'label' => 'Over a day', 'under' => null],
    ];

    private static function emptyGrid(): array
    {
        return array_fill(0, 7, array_fill(0, 24, 0));
    }

    private static function testClause(bool $includeTest, string $alias = 'l'): string
    {
        return $includeTest ? '' : " AND NOT JSON_CONTAINS($alias.tags, '\"test\"')";
    }

    public static function overview(Actor $actor, array $q): array
    {
        $actor->assertCan('reports:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $base = ['range' => ['from' => $range['from'], 'to' => $range['to'], 'days' => $range['days'], 'timezone' => $range['timezone']], 'includesTestLeads' => $q['includeTest'], 'currency' => $range['currency']];
            $c = Cohort::build($actor, $range, ['model' => 'first', 'includeTest' => $q['includeTest']]);
            $cohort = $c ? self::cohort($tx, $c) : null;
            $pipeline = $c ? self::pipeline($tx, $actor, $range, $q['includeTest']) : null;
            $engagement = self::engagement($tx, $actor, $range, $c);
            $inventory = self::inventory($tx, $actor);
            $team = $c ? self::team($tx, $actor, $c) : null;
            return $base + ['funnel' => $cohort['funnel'] ?? null, 'conversion' => $cohort['conversion'] ?? null, 'pipeline' => $pipeline, 'engagement' => $engagement, 'inventory' => $inventory, 'team' => $team];
        });
    }

    /**
     * The shape of the period at a glance, for the infographic views: where leads came from and where they are now (source → stage group),
     * the hour-of-week they arrive, how fast the first reply was, and a daily series. Same cohort as every other report (live leads created
     * in the range, workspace time zone, only what the caller may see), so each figure agrees with the list behind it.
     */
    public static function flow(Actor $actor, array $q): array
    {
        $actor->assertCan('reports:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $base = ['range' => ['from' => $range['from'], 'to' => $range['to'], 'days' => $range['days'], 'timezone' => $range['timezone']], 'includesTestLeads' => $q['includeTest']];
            $c = Cohort::build($actor, $range, ['model' => 'first', 'includeTest' => $q['includeTest']]);
            $buckets = static fn(array $counts) => array_map(static fn($b) => ['key' => $b['key'], 'label' => $b['label'], 'count' => $counts[$b['key']] ?? 0], self::REPLY_BUCKETS);
            if (!$c) {
                return $base + [
                    'total' => 0, 'sources' => [], 'stages' => array_map(static fn($s) => $s + ['total' => 0], self::FLOW_STAGES), 'links' => [],
                    'arrivals' => ['grid' => self::emptyGrid(), 'total' => 0, 'peak' => null], 'replyTimes' => ['buckets' => $buckets([]), 'unanswered' => 0, 'median' => null, 'answered' => 0], 'daily' => [],
                ];
            }
            $rows = $tx->all('SELECT f.source, f.status, f.created_at AS createdAt, f.first_response_at AS firstResponseAt FROM ' . $c['sql'] . ' f', $c['params']);
            $zone = new \DateTimeZone($range['timezone']);
            $linkCount = [];
            $bySource = [];
            $byStage = [];
            $grid = self::emptyGrid();
            $peak = null;
            $arrTotal = 0;
            $replyCounts = [];
            $mins = [];
            $dailyMap = [];
            foreach ($rows as $r) {
                $stage = self::STATUS_GROUP[$r['status']] ?? 'closed';
                $linkCount[$r['source'] . "\0" . $stage] = ($linkCount[$r['source'] . "\0" . $stage] ?? 0) + 1;
                $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
                $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
                $created = Time::parse($r['createdAt']);
                $local = $created->setTimezone($zone);
                $dow = (int) $local->format('N') - 1;
                $hr = (int) $local->format('G');
                $grid[$dow][$hr]++;
                $arrTotal++;
                if (!$peak || $grid[$dow][$hr] > $peak['count']) $peak = ['day' => $dow, 'hour' => $hr, 'count' => $grid[$dow][$hr]];
                $day = $local->format('Y-m-d');
                $dailyMap[$day]['leads'] = ($dailyMap[$day]['leads'] ?? 0) + 1;
                if ($r['status'] === 'won') $dailyMap[$day]['won'] = ($dailyMap[$day]['won'] ?? 0) + 1;
                if ($r['firstResponseAt'] === null) {
                    $replyCounts['none'] = ($replyCounts['none'] ?? 0) + 1;
                } else {
                    $m = max(0.0, (Time::parse($r['firstResponseAt'])->getTimestamp() - $created->getTimestamp()) / 60);
                    $mins[] = $m;
                    $key = 'late';
                    foreach (self::REPLY_BUCKETS as $b) if ($b['under'] !== null && $m < $b['under']) { $key = $b['key']; break; }
                    $replyCounts[$key] = ($replyCounts[$key] ?? 0) + 1;
                }
            }
            // The peak is the busiest hour of the week; ties keep the first one found (earliest day, then hour) like the grid scan would.
            if ($peak) {
                $best = null;
                foreach ($grid as $d => $hours) foreach ($hours as $h => $cnt) if ($cnt > 0 && (!$best || $cnt > $best['count'])) $best = ['day' => $d, 'hour' => $h, 'count' => $cnt];
                $peak = $best;
            }
            $answered = 0;
            foreach (self::REPLY_BUCKETS as $b) $answered += $replyCounts[$b['key']] ?? 0;
            $median = Cohort::median($mins);
            // Every day of the range appears, so a quiet day is a visible gap rather than a missing point.
            $series = [];
            $d = new \DateTimeImmutable($range['from'] . ' 00:00:00', new \DateTimeZone('UTC'));
            for ($i = 0; $i < $range['days']; $i++, $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                $series[] = ['day' => $k, 'leads' => $dailyMap[$k]['leads'] ?? 0, 'won' => $dailyMap[$k]['won'] ?? 0];
            }
            arsort($bySource);
            $sources = [];
            foreach ($bySource as $source => $total) $sources[] = ['source' => (string) $source, 'label' => Channels::sourceLabel((string) $source), 'total' => $total];
            $links = [];
            foreach ($linkCount as $k => $count) {
                [$source, $stage] = explode("\0", (string) $k);
                $links[] = ['source' => $source, 'label' => Channels::sourceLabel($source), 'stage' => $stage, 'count' => $count];
            }
            return $base + [
                'total' => array_sum($byStage), 'sources' => $sources,
                'stages' => array_map(static fn($s) => $s + ['total' => $byStage[$s['key']] ?? 0], self::FLOW_STAGES), 'links' => $links,
                'arrivals' => ['grid' => $grid, 'total' => $arrTotal, 'peak' => $peak],
                'replyTimes' => ['buckets' => $buckets($replyCounts), 'unanswered' => $replyCounts['none'] ?? 0, 'median' => $median === null ? null : round($median, 1), 'answered' => $answered],
                'daily' => $series,
            ];
        });
    }

    private static function cohort(Tx $tx, array $c): array
    {
        $r = $tx->one(
            "SELECT COUNT(*) AS leads, COALESCE(SUM(qualified), 0) AS qualified, COALESCE(SUM(visit_done), 0) AS visits, COALESCE(SUM(booked), 0) AS booked, COALESCE(SUM(won), 0) AS won,
                    COALESCE(SUM(revenue), 0) AS revenue, COALESCE(SUM(won AND revenue = 0), 0) AS won_without_value,
                    COALESCE(SUM(status = 'lost'), 0) AS lost, COALESCE(SUM(status = 'disqualified'), 0) AS not_fit, COALESCE(SUM(closed_at IS NULL), 0) AS still_open,
                    AVG(CASE WHEN won THEN TIMESTAMPDIFF(SECOND, created_at, closed_at) / 86400.0 END) AS days_to_win, COALESCE(SUM(revenue > 0), 0) AS sales
               FROM " . $c['sql'] . ' f',
            $c['params']
        );
        $counts = ['leads' => Cohort::i($r['leads']), 'qualified' => Cohort::i($r['qualified']), 'visits' => Cohort::i($r['visits']), 'booked' => Cohort::i($r['booked']), 'won' => Cohort::i($r['won'])];
        $steps = [];
        foreach (self::FUNNEL as $i => $s) {
            $steps[] = [
                'key' => $s['key'], 'label' => $s['label'], 'help' => $s['help'], 'count' => $counts[$s['key']],
                /** Share of the previous step; null (shown as "—") when the previous step is empty. */
                'ofPrevious' => $i === 0 ? null : Cohort::ratio($counts[$s['key']], $counts[self::FUNNEL[$i - 1]['key']]),
                'ofLeads' => $i === 0 ? null : Cohort::ratio($counts[$s['key']], $counts['leads']),
            ];
        }
        $why = $tx->all(
            "SELECT status, loss_reason_code AS code, COUNT(*) AS c FROM " . $c['sql'] . " f WHERE status IN ('lost', 'disqualified') GROUP BY status, loss_reason_code ORDER BY c DESC, (loss_reason_code IS NULL), loss_reason_code",
            $c['params']
        );
        $reasons = static fn(string $status) => array_values(array_map(
            static fn($w) => ['code' => $w['code'] ?? 'unspecified', 'label' => Vocab::reasonLabel($w['code']), 'count' => Cohort::i($w['c'])],
            array_filter($why, static fn($w) => $w['status'] === $status)
        ));
        $revenue = Cohort::f($r['revenue']);
        $sales = Cohort::i($r['sales']);
        return [
            'funnel' => [
                'steps' => $steps,
                /** Recorded sales (completed deals) among these leads: the only revenue figure. */
                'revenue' => $revenue, 'sales' => $sales, 'averageDealValue' => $sales > 0 ? (int) round($revenue / $sales) : null,
                'wonWithoutDealValue' => Cohort::i($r['won_without_value']),
                'averageDaysToWin' => $r['days_to_win'] === null ? null : round((float) $r['days_to_win'], 1),
            ],
            'conversion' => [
                'winRate' => Cohort::ratio($counts['won'], $counts['leads']), 'won' => $counts['won'], 'lost' => Cohort::i($r['lost']), 'notAFit' => Cohort::i($r['not_fit']), 'stillOpen' => Cohort::i($r['still_open']),
                'lostReasons' => $reasons('lost'), 'notAFitReasons' => $reasons('disqualified'),
            ],
        ];
    }

    /** Right now (not period-bound): where open leads are, and how long leads typically stay in each stage. */
    private static function pipeline(Tx $tx, Actor $actor, array $range, bool $includeTest): ?array
    {
        $vp = [];
        $vis = Visibility::lead($actor, 'l', $vp);
        if ($vis === false) return null;
        $test = self::testClause($includeTest);
        $stages = $tx->all(
            "SELECT s.id, s.name, s.color, s.probability, s.maps_to_status AS status, COUNT(l.id) AS count, COALESCE(SUM(l.estimated_value), 0) AS value
               FROM pipelines pl JOIN pipeline_stages s ON s.pipeline_id = pl.id AND s.tenant_id = pl.tenant_id AND s.archived_at IS NULL
               LEFT JOIN leads l ON l.stage_id = s.id AND l.tenant_id = ? AND l.deleted_at IS NULL AND $vis$test
              WHERE pl.tenant_id = ? AND pl.is_default = 1 AND pl.archived_at IS NULL
              GROUP BY s.id, s.name, s.color, s.probability, s.maps_to_status, s.order_index ORDER BY s.order_index",
            array_merge([$actor->tenantId], $vp, [$actor->tenantId])
        );
        $other = $tx->one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(l.estimated_value), 0) AS v FROM leads l
              WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND $vis$test AND l.closed_at IS NULL
                AND l.pipeline_id NOT IN (SELECT id FROM pipelines WHERE tenant_id = ? AND is_default = 1 AND archived_at IS NULL)",
            array_merge([$actor->tenantId], $vp, [$actor->tenantId])
        );
        // time in stage: for every stage a lead LEFT during the period, how long it had been there (from the history)
        $dp = [];
        $dvis = Visibility::lead($actor, 'l', $dp);
        $moves = $tx->all(
            "SELECT m.to_stage_id AS id, TIMESTAMPDIFF(SECOND, m.entered, m.left_at) / 86400.0 AS days FROM (
                SELECT h.lead_id, h.tenant_id, h.to_stage_id, h.created_at AS entered,
                       (SELECT MIN(h2.created_at) FROM lead_stage_history h2 WHERE h2.tenant_id = h.tenant_id AND h2.lead_id = h.lead_id
                           AND (h2.created_at > h.created_at OR (h2.created_at = h.created_at AND h2.id > h.id))) AS left_at
                  FROM lead_stage_history h WHERE h.tenant_id = ?
             ) m JOIN leads l ON l.id = m.lead_id AND l.tenant_id = m.tenant_id
              WHERE m.left_at IS NOT NULL AND m.left_at >= ? AND m.left_at < ? AND l.deleted_at IS NULL AND $dvis$test",
            array_merge([$actor->tenantId, Time::db($range['start']), Time::db($range['end'])], $dp)
        );
        $byStage = [];
        foreach ($moves as $m) $byStage[$m['id']][] = (float) $m['days'];
        $rows = [];
        foreach ($stages as $s) {
            $d = $byStage[$s['id']] ?? null;
            $rows[] = [
                'id' => $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'probability' => Cohort::n($s['probability']), 'isClosed' => in_array($s['status'], ['won', 'lost', 'disqualified'], true),
                'count' => Cohort::i($s['count']), 'value' => Cohort::f($s['value']), 'observations' => $d ? count($d) : 0,
                'averageDays' => $d ? round(array_sum($d) / count($d), 1) : null, 'medianDays' => $d ? round((float) Cohort::median($d), 1) : null,
            ];
        }
        $open = array_values(array_filter($rows, static fn($r) => !$r['isClosed']));
        $weighted = 0.0;
        foreach ($open as $r) $weighted += $r['value'] * $r['probability'] / 100;
        return [
            'openLeads' => array_sum(array_column($open, 'count')) + Cohort::i($other['c']), 'openValue' => array_sum(array_column($open, 'value')) + Cohort::f($other['v']),
            'weightedValue' => round($weighted, 2), 'stages' => $rows, 'otherPipelines' => ['count' => Cohort::i($other['c']), 'value' => Cohort::f($other['v'])],
        ];
    }

    private static function engagement(Tx $tx, Actor $actor, array $range, ?array $c): array
    {
        $reply = null;
        if ($c) {
            $r = $tx->one(
                'SELECT COUNT(*) AS total, COALESCE(SUM(first_response_at IS NOT NULL), 0) AS replied,
                        COALESCE(SUM(first_response_at IS NOT NULL AND first_response_due_at IS NOT NULL AND first_response_at <= first_response_due_at), 0) AS in_time,
                        COALESCE(SUM(first_response_due_at IS NOT NULL), 0) AS with_deadline
                   FROM ' . $c['sql'] . ' f',
                $c['params']
            );
            $secs = $tx->col('SELECT TIMESTAMPDIFF(SECOND, created_at, first_response_at) FROM ' . $c['sql'] . ' f WHERE first_response_at IS NOT NULL', $c['params']);
            $median = Cohort::median(array_map(static fn($s) => (float) $s / 60.0, $secs));
            $reply = [
                'leads' => Cohort::i($r['total']), 'replied' => Cohort::i($r['replied']),
                /** Of the leads that had a deadline, the share replied to in time; null when none had one. */
                'inTimeRate' => Cohort::ratio(Cohort::i($r['in_time']), Cohort::i($r['with_deadline'])), 'medianMinutes' => $median === null ? null : (int) round($median),
            ];
        }
        $visits = null;
        $vScope = $actor->scopeIfAllowed('viewings:read');
        if ($vScope) {
            $s = Time::db($range['start']);
            $e = Time::db($range['end']);
            $vp = [$s, $e, $s, $e, $s, $e, $actor->tenantId];
            $own = '';
            if ($vScope === 'own') { $own = ' AND (v.agent_id = ? OR l.owner_id = ?)'; array_push($vp, $actor->userId, $actor->userId); }
            $x = $tx->one(
                "SELECT COALESCE(SUM(v.status = 'completed' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS held,
                        COALESCE(SUM(v.status = 'cancelled' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS cancelled,
                        COALESCE(SUM(v.status = 'no_show' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS no_show,
                        COALESCE(SUM(v.status IN ('scheduled','confirmed') AND v.scheduled_at >= NOW(3)), 0) AS upcoming,
                        COALESCE(SUM(v.status IN ('scheduled','confirmed') AND DATE_ADD(v.scheduled_at, INTERVAL v.duration_minutes MINUTE) < NOW(3)), 0) AS unrecorded
                   FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id WHERE v.tenant_id = ? AND l.deleted_at IS NULL$own",
                $vp
            );
            $visits = ['held' => Cohort::i($x['held']), 'cancelled' => Cohort::i($x['cancelled']), 'noShow' => Cohort::i($x['no_show']), 'upcoming' => Cohort::i($x['upcoming']), 'unrecorded' => Cohort::i($x['unrecorded'])];
        }
        $follow = null;
        $lp = [$actor->tenantId];
        $lvis = Visibility::lead($actor, 'l', $lp);
        $tScope = $actor->scopeIfAllowed('tasks:read');
        if ($lvis !== false) {
            $f = $tx->one(
                "SELECT COALESCE(SUM(l.closed_at IS NULL AND l.next_followup_at < NOW(3)), 0) AS overdue, COALESCE(SUM(l.closed_at IS NULL AND l.next_followup_at IS NULL), 0) AS none_planned
                   FROM leads l WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND $lvis",
                $lp
            );
            $tasksOverdue = null;
            if ($tScope) {
                $tp = [$actor->tenantId];
                $own = '';
                if ($tScope === 'own') { $own = ' AND t.assigned_to = ?'; $tp[] = $actor->userId; }
                $tasksOverdue = Cohort::i($tx->val("SELECT COUNT(*) FROM tasks t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.status IN ('pending','in_progress') AND t.due_date < NOW(3)$own", $tp));
            }
            $follow = ['leadsOverdue' => Cohort::i($f['overdue']), 'leadsWithNoFollowUp' => Cohort::i($f['none_planned']), 'tasksOverdue' => $tasksOverdue];
        }
        return ['visits' => $visits, 'followUps' => $follow, 'firstReply' => $reply];
    }

    private static function inventory(Tx $tx, Actor $actor): ?array
    {
        if (!$actor->scopeIfAllowed('properties:read')) return null;
        $s = $tx->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available, COALESCE(SUM(status = 'under_offer'), 0) AS under_offer, COALESCE(SUM(status = 'reserved'), 0) AS reserved,
                    COALESCE(SUM(status = 'sold'), 0) AS sold, COALESCE(SUM(status = 'rented'), 0) AS rented, COALESCE(SUM(status = 'inactive'), 0) AS inactive,
                    COALESCE(SUM(CASE WHEN status = 'available' THEN price END), 0) AS available_value
               FROM properties WHERE tenant_id = ? AND deleted_at IS NULL",
            [$actor->tenantId]
        );
        return [
            'total' => Cohort::i($s['total']),
            'byStatus' => ['available' => Cohort::i($s['available']), 'under_offer' => Cohort::i($s['under_offer']), 'reserved' => Cohort::i($s['reserved']), 'sold' => Cohort::i($s['sold']), 'rented' => Cohort::i($s['rented']), 'inactive' => Cohort::i($s['inactive'])],
            'availableValue' => Cohort::f($s['available_value']),
        ];
    }

    /** Managers only: the same funnel, by lead owner. */
    private static function team(Tx $tx, Actor $actor, array $c): ?array
    {
        if ($actor->kind !== 'system' && ($actor->scopeIfAllowed('leads:read') !== 'all' || $actor->scopeIfAllowed('leads:assign') !== 'all')) return null;
        $rows = $tx->all(
            'SELECT f.owner_id AS id, COUNT(*) AS leads, COALESCE(SUM(qualified), 0) AS qualified, COALESCE(SUM(visit_done), 0) AS visits, COALESCE(SUM(booked), 0) AS booked,
                    COALESCE(SUM(won), 0) AS won, COALESCE(SUM(revenue), 0) AS revenue
               FROM ' . $c['sql'] . ' f GROUP BY f.owner_id',
            $c['params']
        );
        $replies = $tx->all('SELECT f.owner_id AS id, TIMESTAMPDIFF(SECOND, f.created_at, f.first_response_at) AS secs FROM ' . $c['sql'] . ' f WHERE f.first_response_at IS NOT NULL', $c['params']);
        $by = [];
        foreach ($replies as $r) $by[(string) $r['id']][] = (float) $r['secs'] / 60.0;
        $ids = array_values(array_filter(array_map(static fn($r) => $r['id'], $rows)));
        $names = $ids ? array_column($tx->all('SELECT id, name FROM users WHERE id IN (?)', [$ids]), 'name', 'id') : [];
        $out = array_map(static function ($r) use ($names, $by) {
            $med = Cohort::median($by[(string) $r['id']] ?? []);
            $leads = Cohort::i($r['leads']);
            $won = Cohort::i($r['won']);
            return [
                'userId' => $r['id'], 'name' => $r['id'] ? ($names[$r['id']] ?? 'Former member') : 'Unassigned', 'leads' => $leads, 'qualified' => Cohort::i($r['qualified']),
                'visits' => Cohort::i($r['visits']), 'booked' => Cohort::i($r['booked']), 'won' => $won, 'revenue' => Cohort::f($r['revenue']), 'winRate' => Cohort::ratio($won, $leads),
                'medianFirstReplyMinutes' => $med === null ? null : (int) round($med),
            ];
        }, $rows);
        usort($out, static fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['leads'] <=> $a['leads'] ?: strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * The leads behind one funnel figure (optionally one owner): same cohort SQL, so the list reproduces the count.
     *
     * @return array{items:array,page:array,total:int}
     */
    public static function leads(Actor $actor, array $q): array
    {
        $actor->assertCan('reports:read');
        $sig = "reports-drill:{$q['metric']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $sig, $cursor) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $c = Cohort::build($actor, $range, ['model' => 'first', 'includeTest' => $q['includeTest'], 'owner' => $q['owner'] ?? null]);
            if (!$c) return ['items' => [], 'page' => ['hasMore' => false, 'next' => null], 'total' => 0];
            $filter = $q['metric'] === 'leads' ? '1' : 'f.' . Cohort::STEP_COLUMN[$q['metric']];
            $total = Cohort::i($tx->val('SELECT COUNT(*) FROM ' . $c['sql'] . " f WHERE $filter", $c['params']));
            $params = $c['params'];
            $pred = Pagination::predicate($cursor, $params, 'f.created_at', 'f.id', 'desc', 'ts');
            $params[] = $q['limit'] + 1;
            $rows = $tx->all(
                'SELECT f.id, f.created_at AS createdAt, f.created_at AS _k, f.status, f.source, f.campaign, f.revenue, f.reconstructed, s.name AS stageName, c.first_name AS _f, c.last_name AS _l, u.name AS ownerName
                   FROM ' . $c['sql'] . ' f JOIN leads l ON l.id = f.id AND l.tenant_id = f.tenant_id JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id
                   LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id LEFT JOIN users u ON u.id = f.owner_id
                  WHERE ' . $filter . ($pred ? " AND $pred" : '') . ' ORDER BY f.created_at DESC, f.id DESC LIMIT ?',
                $params
            );
            $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
            return ['items' => array_map([AttributionService::class, 'drillRow'], $pg['items']), 'page' => $pg['page'], 'total' => $total];
        });
    }

    /** The report as a spreadsheet: a summary, the funnel, the pipeline, the closed-lead reasons and the team. Cells that could be read as formulas are neutralised. */
    public static function exportCsv(Actor $actor, array $q): array
    {
        $actor->assertCan('reports:export');
        $o = self::overview($actor, $q);
        $rows = [];
        $add = static function (...$r) use (&$rows) { $rows[] = $r; };
        $pct = static fn(?float $v) => $v === null ? '' : (round($v * 1000) / 10) . '%';
        $add('Report', 'Period', $o['range']['from'] . ' to ' . $o['range']['to'], $o['range']['timezone']);
        $add('Report', 'Test leads', $o['includesTestLeads'] ? 'included' : 'left out', '');
        if ($o['funnel']) {
            foreach ($o['funnel']['steps'] as $s) $add('Funnel', $s['label'], $s['count'], $s['ofPrevious'] === null ? '' : $pct($s['ofPrevious']) . ' of the previous step');
            $add('Sales', 'Recorded sales', $o['funnel']['sales'], 'completed deals among these leads');
            $add('Sales', "Won deal value ({$o['currency']})", $o['funnel']['revenue'], 'sum of recorded sales, not estimates');
            $add('Sales', 'Won leads without a recorded sale', $o['funnel']['wonWithoutDealValue'], 'counted as won, no value invented');
        }
        if ($o['conversion']) {
            $add('Conversion', 'Win rate', $pct($o['conversion']['winRate']), 'won ÷ leads created in the period');
            foreach ($o['conversion']['lostReasons'] as $r) $add('Lost reasons', $r['label'], $r['count'], '');
            foreach ($o['conversion']['notAFitReasons'] as $r) $add('Not a fit reasons', $r['label'], $r['count'], '');
        }
        if ($o['pipeline']) {
            foreach ($o['pipeline']['stages'] as $s) $add('Pipeline (now)', $s['name'], $s['count'], 'estimated value ' . $s['value'] . ($s['medianDays'] !== null ? "; median {$s['medianDays']} days in stage" : ''));
            $add('Pipeline (now)', 'Open value (estimate)', $o['pipeline']['openValue'], 'typed on leads; not revenue');
        }
        if ($o['team']) foreach ($o['team'] as $t) $add('Team', $t['name'], $t['revenue'], "{$t['leads']} leads, {$t['qualified']} qualified, {$t['visits']} reached a visit, {$t['booked']} booked, {$t['won']} won");
        return ['csv' => Csv::write(['Section', 'Item', 'Value', 'Note'], $rows), 'rows' => count($rows)];
    }
}
