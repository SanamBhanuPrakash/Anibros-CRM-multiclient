<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Actor;
use App\Core\Db;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Analytics\Channels;
use App\Modules\Analytics\Range;
use App\Modules\Home\HomeService;
use App\Modules\Leads\Visibility;

/**
 * The executive overview: the whole business on one screen. Every number is a count or sum of live records the caller may see, over the
 * same definitions as the list a click leads to, so a figure and its list agree (the tests compare them).
 *
 *  - Leads count when they are live (not archived) and, unless `includeTest`, not tagged `test` (decision D8). "Open" means not closed.
 *  - The date range is whole local days in the workspace time zone and applies to what happened IN the period: leads created, sales recorded,
 *    visits held. Everything else (open pipeline, tasks, stock) is a snapshot of right now and says so.
 *  - Revenue is the sum of RECORDED sales (completed deals, decision D3), never the estimate typed on a lead.
 *  - Sections the caller has no permission for are null, not zero. Salespeople see only their own leads, tasks and visits.
 */
final class DashboardOverviewService
{
    private const OPEN_TASK = "('pending','in_progress')";

    private static function n(mixed $v): int|float
    {
        if ($v === null || $v === '') return 0;
        $f = (float) $v;
        return $f == floor($f) && abs($f) < 1e15 ? (int) $f : $f;
    }

    private static function test(bool $includeTest): string
    {
        return $includeTest ? '' : " AND NOT JSON_CONTAINS(l.tags, '\"test\"')";
    }

    /** @param array{from?:?string,to?:?string,includeTest:bool} $q */
    public static function overview(Actor $actor, array $q): array
    {
        $actor->assertCan('dashboard:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $prevFrom = (new \DateTimeImmutable($range['from'], new \DateTimeZone('UTC')))->modify('-' . $range['days'] . ' days')->format('Y-m-d');
            $prevTo = (new \DateTimeImmutable($range['from'], new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            $prev = Range::resolve($tx, $actor->tenantId, ['from' => $prevFrom, 'to' => $prevTo]);
            $unit = $range['days'] <= 31 ? 'day' : ($range['days'] <= 140 ? 'week' : 'month');
            $leadsPart = self::leads($tx, $actor, $range, $prev, $q['includeTest'], $unit);
            $salesPart = self::sales($tx, $actor, $range, $prev, $q['includeTest'], $unit);
            $tasks = self::tasks($tx, $actor, $range);
            $viewings = self::viewings($tx, $actor, $range);
            $inventory = self::inventory($tx, $actor);
            $team = self::team($tx, $actor, $range, $q['includeTest']);
            $trend = [];
            foreach (self::bucketStarts($range, $unit) as $b) {
                $s = $salesPart['trendSales'][$b['start']] ?? null;
                $trend[] = ['from' => $b['from'], 'to' => $b['to'], 'label' => $b['start'], 'leads' => $leadsPart['trendLeads'][$b['start']] ?? 0, 'sales' => $s['count'] ?? 0, 'dealValue' => $s['value'] ?? 0.0];
            }
            return [
                'range' => ['from' => $range['from'], 'to' => $range['to'], 'days' => $range['days'], 'timezone' => $range['timezone']], 'previous' => ['from' => $prev['from'], 'to' => $prev['to']],
                'bucket' => $unit, 'includesTestLeads' => $q['includeTest'], 'currency' => $range['currency'],
                'leads' => $leadsPart['leads'] ?? null, 'pipeline' => $leadsPart['pipeline'] ?? null, 'sources' => $leadsPart['sources'] ?? null, 'sales' => $salesPart['sales'] ?? null,
                'trend' => $leadsPart || $salesPart ? $trend : [], 'tasks' => $tasks, 'viewings' => $viewings, 'inventory' => $inventory, 'team' => $team,
            ];
        });
    }

    private static function leads(Tx $tx, Actor $actor, array $r, array $p, bool $includeTest, string $unit): ?array
    {
        $probe = [];
        if (Visibility::lead($actor, 'l', $probe) === false) return null;
        $test = self::test($includeTest);
        $db = static fn(\DateTimeImmutable $d) => Time::db($d);
        $s = $db($r['start']); $e = $db($r['end']); $ps = $db($p['start']); $pe = $db($p['end']);
        $vp = [$actor->tenantId];
        $vis = Visibility::lead($actor, 'l', $vp);
        $base = "l.tenant_id = ? AND l.deleted_at IS NULL AND $vis$test";
        $inRange = 'l.created_at >= ? AND l.created_at < ?';
        $totals = $tx->one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(l.closed_at IS NULL), 0) AS open,
                    COALESCE(SUM($inRange), 0) AS created,
                    COALESCE(SUM(l.created_at >= ? AND l.created_at < ?), 0) AS prev_created,
                    COALESCE(SUM($inRange AND l.status = 'won'), 0) AS won_of_created,
                    COALESCE(SUM(l.closed_at IS NULL AND l.owner_id IS NULL), 0) AS unassigned,
                    COALESCE(SUM(l.closed_at IS NULL AND l.first_response_at IS NULL AND l.first_response_due_at < NOW(3)), 0) AS late_replies,
                    COALESCE(SUM(l.closed_at IS NULL AND l.next_followup_at < NOW(3)), 0) AS followups_overdue,
                    COALESCE(SUM(CASE WHEN l.closed_at IS NULL THEN l.estimated_value END), 0) AS open_value
               FROM leads l WHERE $base",
            array_merge([$s, $e, $ps, $pe, $s, $e], $vp)
        );
        $sp = [$actor->tenantId];
        $svis = Visibility::lead($actor, 'l', $sp);
        $baseS = "l.tenant_id = ? AND l.deleted_at IS NULL AND $svis$test";
        $stages = $tx->all(
            "SELECT s.id, s.name, s.color, s.probability, s.maps_to_status AS status, COUNT(l.id) AS count, COALESCE(SUM(l.estimated_value), 0) AS value
               FROM pipelines p JOIN pipeline_stages s ON s.pipeline_id = p.id AND s.tenant_id = p.tenant_id AND s.archived_at IS NULL
               LEFT JOIN leads l ON l.stage_id = s.id AND $baseS
              WHERE p.tenant_id = ? AND p.is_default = 1 AND p.archived_at IS NULL GROUP BY s.id, s.name, s.color, s.probability, s.maps_to_status, s.order_index ORDER BY s.order_index",
            array_merge($sp, [$actor->tenantId])
        );
        $weighted = $tx->val("SELECT COALESCE(SUM(l.estimated_value * s.probability / 100.0), 0) FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id WHERE $baseS AND l.closed_at IS NULL", $sp);
        $other = $tx->one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(l.estimated_value), 0) AS v FROM leads l WHERE $baseS AND l.closed_at IS NULL
                AND l.pipeline_id NOT IN (SELECT id FROM pipelines WHERE tenant_id = ? AND is_default = 1 AND archived_at IS NULL)",
            array_merge($sp, [$actor->tenantId])
        );
        $sources = $tx->all("SELECT l.source AS source, COUNT(*) AS leads, COALESCE(SUM(l.status = 'won'), 0) AS won FROM leads l WHERE $base AND $inRange GROUP BY l.source ORDER BY leads DESC, l.source", array_merge($vp, [$s, $e]));
        // trend buckets are local calendar periods in the workspace time zone: bucketed here, since a shared host may not have time-zone tables in MySQL
        $zone = new \DateTimeZone($r['timezone']);
        $trendLeads = [];
        foreach ($tx->col("SELECT l.created_at FROM leads l WHERE $base AND $inRange", array_merge($vp, [$s, $e])) as $at) {
            $k = self::bucketKey(Time::parse($at)->setTimezone($zone), $unit);
            $trendLeads[$k] = ($trendLeads[$k] ?? 0) + 1;
        }
        $created = (int) $totals['created'];
        $wonOfCreated = (int) $totals['won_of_created'];
        return [
            'leads' => [
                'total' => (int) $totals['total'], 'open' => (int) $totals['open'], 'created' => $created, 'previousCreated' => (int) $totals['prev_created'], 'wonOfCreated' => $wonOfCreated,
                /** Of the leads created in the period, the share that is now won; null when none were created. */
                'conversionRate' => $created > 0 ? round($wonOfCreated / $created, 4) : null, 'unassigned' => (int) $totals['unassigned'],
                'lateFirstReplies' => (int) $totals['late_replies'], 'followupsOverdue' => (int) $totals['followups_overdue'],
            ],
            'pipeline' => [
                'openValue' => (float) $totals['open_value'], 'weightedValue' => round((float) $weighted, 2),
                'stages' => array_map(static fn($x) => [
                    'id' => $x['id'], 'name' => $x['name'], 'color' => $x['color'], 'probability' => self::n($x['probability']), 'status' => $x['status'],
                    'isClosed' => in_array($x['status'], ['won', 'lost', 'disqualified'], true), 'count' => (int) $x['count'], 'value' => (float) $x['value'],
                ], $stages),
                'otherPipelines' => ['count' => (int) $other['c'], 'value' => (float) $other['v']],
            ],
            'sources' => array_map(static fn($x) => ['source' => $x['source'], 'label' => Channels::SOURCE_LABEL[$x['source']] ?? $x['source'], 'leads' => (int) $x['leads'], 'won' => (int) $x['won']], $sources),
            'trendLeads' => $trendLeads,
        ];
    }

    private static function sales(Tx $tx, Actor $actor, array $r, array $p, bool $includeTest, string $unit): ?array
    {
        if (!$actor->scopeIfAllowed('reservations:read')) return null;
        $vp = [];
        $vis = Visibility::lead($actor, 'l', $vp);
        if ($vis === false) return null;
        $test = self::test($includeTest);
        $s = Time::db($r['start']); $e = Time::db($r['end']); $ps = Time::db($p['start']); $pe = Time::db($p['end']);
        $done = "r.status = 'completed' AND r.completed_at >= ? AND r.completed_at < ?";
        $row = $tx->one(
            "SELECT COALESCE(SUM($done), 0) AS sales, COALESCE(SUM(CASE WHEN $done THEN r.deal_value END), 0) AS value,
                    COALESCE(SUM(r.status = 'completed' AND r.completed_at >= ? AND r.completed_at < ?), 0) AS prev_sales,
                    COALESCE(SUM(CASE WHEN r.status = 'completed' AND r.completed_at >= ? AND r.completed_at < ? THEN r.deal_value END), 0) AS prev_value,
                    COALESCE(SUM(r.status = 'reserved'), 0) AS reserved, COALESCE(SUM(r.status = 'offered'), 0) AS offers,
                    COALESCE(SUM(r.status = 'reserved' AND r.hold_until < NOW(3)), 0) AS holds_overdue
               FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND l.deleted_at IS NULL AND $vis$test",
            array_merge([$s, $e, $s, $e, $ps, $pe, $ps, $pe, $actor->tenantId], $vp)
        );
        $zone = new \DateTimeZone($r['timezone']);
        $trend = [];
        $rows = $tx->all(
            "SELECT r.completed_at AS at, r.deal_value AS v FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id
              WHERE r.tenant_id = ? AND l.deleted_at IS NULL AND $vis$test AND r.status = 'completed' AND r.completed_at >= ? AND r.completed_at < ?",
            array_merge([$actor->tenantId], $vp, [$s, $e])
        );
        foreach ($rows as $x) {
            $k = self::bucketKey(Time::parse($x['at'])->setTimezone($zone), $unit);
            $trend[$k]['count'] = ($trend[$k]['count'] ?? 0) + 1;
            $trend[$k]['value'] = ($trend[$k]['value'] ?? 0.0) + (float) $x['v'];
        }
        $sales = (int) $row['sales'];
        $value = (float) $row['value'];
        return [
            'sales' => [
                'count' => $sales, 'dealValue' => $value, 'averageDealValue' => $sales > 0 ? (int) round($value / $sales) : null, 'previousCount' => (int) $row['prev_sales'],
                'previousDealValue' => (float) $row['prev_value'], 'reserved' => (int) $row['reserved'], 'openOffers' => (int) $row['offers'], 'holdsOverdue' => (int) $row['holds_overdue'],
            ],
            'trendSales' => $trend,
        ];
    }

    private static function tasks(Tx $tx, Actor $actor, array $r): ?array
    {
        $scope = $actor->scopeIfAllowed('tasks:read');
        if (!$scope) return null;
        $params = [];
        $day = HomeService::dayBounds($r['timezone']);
        array_push($params, Time::db($day['start_today']), Time::db($day['start_tomorrow']), $actor->tenantId);
        $own = '';
        if ($scope === 'own') { $own = ' AND t.assigned_to = ?'; $params[] = $actor->userId; }
        $open = self::OPEN_TASK;
        $x = $tx->one(
            "SELECT COALESCE(SUM(t.status IN $open), 0) AS open, COALESCE(SUM(t.status IN $open AND t.due_date < NOW(3)), 0) AS overdue,
                    COALESCE(SUM(t.status IN $open AND t.due_date >= ? AND t.due_date < ?), 0) AS today, COALESCE(SUM(t.status IN $open AND t.assigned_to IS NULL), 0) AS unassigned
               FROM tasks t WHERE t.tenant_id = ? AND t.deleted_at IS NULL$own",
            $params
        );
        return ['scope' => $scope, 'open' => (int) $x['open'], 'overdue' => (int) $x['overdue'], 'dueToday' => (int) $x['today'], 'unassigned' => (int) $x['unassigned']];
    }

    private static function viewings(Tx $tx, Actor $actor, array $r): ?array
    {
        $scope = $actor->scopeIfAllowed('viewings:read');
        if (!$scope) return null;
        $s = Time::db($r['start']);
        $e = Time::db($r['end']);
        $params = [$s, $e, $s, $e, $s, $e, $actor->tenantId];
        $own = '';
        if ($scope === 'own') { $own = ' AND (v.agent_id = ? OR l.owner_id = ?)'; array_push($params, $actor->userId, $actor->userId); }
        $x = $tx->one(
            "SELECT COALESCE(SUM(v.status IN ('scheduled','confirmed') AND v.scheduled_at >= NOW(3)), 0) AS upcoming,
                    COALESCE(SUM(v.status IN ('scheduled','confirmed') AND v.scheduled_at >= NOW(3) AND v.scheduled_at < DATE_ADD(NOW(3), INTERVAL 7 DAY)), 0) AS next7,
                    COALESCE(SUM(v.status = 'completed' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS completed,
                    COALESCE(SUM(v.status = 'cancelled' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS cancelled,
                    COALESCE(SUM(v.status = 'no_show' AND v.scheduled_at >= ? AND v.scheduled_at < ?), 0) AS no_show,
                    COALESCE(SUM(v.status IN ('scheduled','confirmed') AND DATE_ADD(v.scheduled_at, INTERVAL v.duration_minutes MINUTE) < NOW(3)), 0) AS unrecorded
               FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id WHERE v.tenant_id = ? AND l.deleted_at IS NULL$own",
            $params
        );
        return ['upcoming' => (int) $x['upcoming'], 'next7Days' => (int) $x['next7'], 'completed' => (int) $x['completed'], 'cancelled' => (int) $x['cancelled'], 'noShow' => (int) $x['no_show'], 'unrecorded' => (int) $x['unrecorded']];
    }

    private static function inventory(Tx $tx, Actor $actor): ?array
    {
        if (!$actor->scopeIfAllowed('properties:read')) return null;
        $x = $tx->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available, COALESCE(SUM(status = 'under_offer'), 0) AS under_offer, COALESCE(SUM(status = 'reserved'), 0) AS reserved,
                    COALESCE(SUM(status = 'sold'), 0) AS sold, COALESCE(SUM(status = 'rented'), 0) AS rented, COALESCE(SUM(status = 'inactive'), 0) AS inactive,
                    COALESCE(SUM(CASE WHEN status = 'available' THEN price END), 0) AS available_value
               FROM properties WHERE tenant_id = ? AND deleted_at IS NULL",
            [$actor->tenantId]
        );
        return [
            'total' => (int) $x['total'],
            'byStatus' => ['available' => (int) $x['available'], 'under_offer' => (int) $x['under_offer'], 'reserved' => (int) $x['reserved'], 'sold' => (int) $x['sold'], 'rented' => (int) $x['rented'], 'inactive' => (int) $x['inactive']],
            'availableValue' => (float) $x['available_value'],
        ];
    }

    /** Managers only (they see every lead): one row per person who owns leads or has had a sale recorded in the period. */
    private static function team(Tx $tx, Actor $actor, array $r, bool $includeTest): ?array
    {
        if ($actor->kind !== 'system' && ($actor->scopeIfAllowed('leads:read') !== 'all' || $actor->scopeIfAllowed('leads:assign') !== 'all')) return null;
        $test = self::test($includeTest);
        $s = Time::db($r['start']);
        $e = Time::db($r['end']);
        $leads = $tx->all(
            "SELECT l.owner_id AS id, COALESCE(SUM(l.closed_at IS NULL), 0) AS open, COALESCE(SUM(l.created_at >= ? AND l.created_at < ?), 0) AS created
               FROM leads l WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND l.owner_id IS NOT NULL$test GROUP BY l.owner_id",
            [$s, $e, $actor->tenantId]
        );
        $sales = $actor->scopeIfAllowed('reservations:read') ? $tx->all(
            "SELECT l.owner_id AS id, COUNT(*) AS sales, COALESCE(SUM(rv.deal_value), 0) AS value FROM reservations rv JOIN leads l ON l.id = rv.lead_id AND l.tenant_id = rv.tenant_id
              WHERE rv.tenant_id = ? AND rv.status = 'completed' AND rv.completed_at >= ? AND rv.completed_at < ? AND l.deleted_at IS NULL AND l.owner_id IS NOT NULL$test GROUP BY l.owner_id",
            [$actor->tenantId, $s, $e]
        ) : [];
        $tasks = $actor->scopeIfAllowed('tasks:read') ? $tx->all(
            "SELECT t.assigned_to AS id, COUNT(*) AS overdue FROM tasks t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.status IN " . self::OPEN_TASK . ' AND t.due_date < NOW(3) AND t.assigned_to IS NOT NULL GROUP BY t.assigned_to',
            [$actor->tenantId]
        ) : [];
        $ids = array_values(array_unique(array_merge(array_column($leads, 'id'), array_column($sales, 'id'), array_column($tasks, 'id'))));
        if (!$ids) return [];
        $names = array_column($tx->all('SELECT id, name FROM users WHERE id IN (?)', [$ids]), 'name', 'id');
        $L = array_column($leads, null, 'id');
        $S = array_column($sales, null, 'id');
        $T = array_column($tasks, null, 'id');
        $out = array_map(static fn($id) => [
            'userId' => $id, 'name' => $names[$id] ?? 'Former member', 'openLeads' => (int) ($L[$id]['open'] ?? 0), 'leadsCreated' => (int) ($L[$id]['created'] ?? 0),
            'sales' => (int) ($S[$id]['sales'] ?? 0), 'dealValue' => (float) ($S[$id]['value'] ?? 0), 'overdueTasks' => (int) ($T[$id]['overdue'] ?? 0),
        ], $ids);
        usort($out, static fn($a, $b) => $b['dealValue'] <=> $a['dealValue'] ?: $b['openLeads'] <=> $a['openLeads'] ?: strcasecmp($a['name'], $b['name']));
        return array_slice($out, 0, 25);
    }

    /** The calendar bucket (local date of its first day) a local instant falls in: the day, the Monday of its week, or the first of its month. */
    private static function bucketKey(\DateTimeImmutable $local, string $unit): string
    {
        if ($unit === 'week') return $local->modify('-' . ((int) $local->format('N') - 1) . ' days')->format('Y-m-d');
        if ($unit === 'month') return $local->format('Y-m-01');
        return $local->format('Y-m-d');
    }

    /** Bucket boundaries as local dates: days, Monday-based weeks, or calendar months, clamped to the range so each bucket maps to a list filter. */
    private static function bucketStarts(array $range, string $unit): array
    {
        $utc = new \DateTimeZone('UTC');
        $first = new \DateTimeImmutable($range['from'], $utc);
        $last = new \DateTimeImmutable($range['to'], $utc);
        $cursor = $first;
        if ($unit === 'week') $cursor = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        if ($unit === 'month') $cursor = $first->modify('first day of this month');
        $out = [];
        while ($cursor <= $last && count($out) < 400) {
            $next = match ($unit) { 'day' => $cursor->modify('+1 day'), 'week' => $cursor->modify('+7 days'), default => $cursor->modify('first day of next month') };
            $from = $cursor < $first ? $first : $cursor;
            $toIncl = $next->modify('-1 day');
            $to = $toIncl > $last ? $last : $toIncl;
            $out[] = ['start' => $cursor->format('Y-m-d'), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
            $cursor = $next;
        }
        return $out;
    }
}
