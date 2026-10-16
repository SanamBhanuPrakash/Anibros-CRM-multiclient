<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Pagination;
use App\Core\Raw;
use App\Core\Tx;
use App\Modules\Leads\Visibility;

/**
 * Where leads came from, and what became of them. Counts are leads created in the range (workspace time zone) and credited to ONE
 * touch each (first touch by default, last touch on request), so source rows add up to the total and to the lead list for the same range.
 * "This shows where buyers came from. It does not prove which ad caused a sale."
 */
final class AttributionService
{
    public static function moneyFor(float|int $spend, array $c): array
    {
        return [
            'spend' => round($spend, 2),
            'costPerLead' => Cohort::per($spend, $c['leads']), 'costPerQualified' => Cohort::per($spend, $c['qualified']), 'costPerVisit' => Cohort::per($spend, $c['visits']),
            'costPerBooking' => Cohort::per($spend, $c['booked']), 'costPerSale' => Cohort::per($spend, $c['won']),
            'roi' => $spend > 0 ? round(($c['revenue'] - $spend) / $spend, 4) : null,
        ];
    }

    private static function zero(): array
    {
        return ['leads' => 0, 'qualified' => 0, 'visits' => 0, 'booked' => 0, 'won' => 0, 'revenue' => 0.0];
    }

    private static function counts(array $r): array
    {
        return ['leads' => Cohort::i($r['leads']), 'qualified' => Cohort::i($r['qualified']), 'visits' => Cohort::i($r['visits']), 'booked' => Cohort::i($r['booked']), 'won' => Cohort::i($r['won']), 'revenue' => Cohort::f($r['revenue'])];
    }

    private static function sumOf(array $rows): array
    {
        $t = self::zero();
        foreach ($rows as $r) foreach (array_keys($t) as $k) $t[$k] += $r[$k];
        return $t;
    }

    public static function assertModel(?string $m): string
    {
        if ($m === null || $m === 'first') return 'first';
        if ($m === 'last') return 'last';
        throw Errors::validation([['path' => 'query.model', 'message' => 'Use first or last']]);
    }

    public static function mappings(Actor $actor): array
    {
        $actor->assertCan('attribution:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => self::mappingsIn($tx, $actor->tenantId));
    }

    private static function mappingsIn(Tx $tx, string $tenantId): array
    {
        $rows = $tx->all('SELECT source, channel FROM channel_mappings WHERE tenant_id = ?', [$tenantId]);
        $by = array_column($rows, 'channel', 'source');
        return array_map(static function ($s) use ($by) {
            $ch = $by[$s] ?? Channels::DEFAULT_CHANNEL[$s];
            return ['source' => $s, 'label' => Channels::sourceLabel($s), 'channel' => $ch, 'channelLabel' => Channels::channelLabel($ch), 'isDefault' => !isset($by[$s]) || $by[$s] === Channels::DEFAULT_CHANNEL[$s]];
        }, Channels::SOURCES);
    }

    /** Re-maps a source to another channel for this workspace. Mapping back to the default removes the override. Past counts re-group at once; nothing is stored per lead. */
    public static function setMapping(Actor $actor, string $source, string $channel): array
    {
        $actor->assertCan('attribution:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $source, $channel) {
            if ($channel === Channels::DEFAULT_CHANNEL[$source]) {
                $tx->run('DELETE FROM channel_mappings WHERE tenant_id = ? AND source = ?', [$actor->tenantId, $source]);
            } else {
                $tx->insert('channel_mappings', ['tenant_id' => $actor->tenantId, 'source' => $source, 'channel' => $channel, 'updated_by' => $actor->userId], ['onDuplicate' => ['channel' => $channel, 'updated_by' => $actor->userId, 'updated_at' => new Raw('NOW(3)')]]);
            }
            Audit::log($tx, 'attribution.channel_mapping', 'tenant', $actor->tenantId, null, ['source' => $source, 'channel' => $channel]);
            return self::mappingsIn($tx, $actor->tenantId);
        });
    }

    private const AGG = 'COUNT(*) AS leads, COALESCE(SUM(qualified), 0) AS qualified, COALESCE(SUM(visit_done), 0) AS visits, COALESCE(SUM(booked), 0) AS booked, COALESCE(SUM(won), 0) AS won, COALESCE(SUM(revenue), 0) AS revenue';

    /** @param array{from?:?string,to?:?string,model:string,includeTest:bool} $q */
    public static function report(Actor $actor, array $q): array
    {
        $actor->assertCan('attribution:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $c = Cohort::build($actor, $range, $q);
            $empty = [
                'range' => Range::pub($range), 'model' => $q['model'], 'includesTestLeads' => $q['includeTest'], 'currency' => $range['currency'], 'totals' => self::zero(),
                'tracking' => ['tracked' => 0, 'untracked' => 0, 'direct' => 0, 'withCampaign' => 0, 'reconstructed' => 0, 'sharePercent' => null],
                'sources' => [], 'channels' => [], 'campaigns' => [], 'money' => null, 'lastLeadAt' => null,
            ];
            if (!$c) return $empty;

            $src = $tx->all('SELECT source, ' . self::AGG . ', COALESCE(SUM(campaign IS NOT NULL), 0) AS with_campaign, COALESCE(SUM(reconstructed), 0) AS reconstructed FROM ' . $c['sql'] . ' f GROUP BY source', $c['params']);
            $camp = $tx->all('SELECT source, MIN(campaign) AS campaign, ' . self::AGG . ' FROM ' . $c['sql'] . ' f WHERE campaign IS NOT NULL GROUP BY source, LOWER(campaign) ORDER BY COUNT(*) DESC, 1, 2 LIMIT 200', $c['params']);
            // "last lead received" ignores the date range but respects what the caller may see
            $lp = [$actor->tenantId];
            $lvis = Visibility::lead($actor, 'l', $lp);
            $lastAt = $lvis === false ? null : $tx->val("SELECT MAX(l.created_at) FROM leads l WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND $lvis", $lp);
            $spend = $tx->all(
                'SELECT s.source, LOWER(COALESCE(s.campaign, \'\')) AS campaign_key, s.campaign,
                        s.amount * ((DATEDIFF(LEAST(s.period_end, CAST(? AS DATE)), GREATEST(s.period_start, CAST(? AS DATE))) + 1) / (DATEDIFF(s.period_end, s.period_start) + 1)) AS allocated
                   FROM marketing_spend s WHERE s.tenant_id = ? AND s.period_start <= CAST(? AS DATE) AND s.period_end >= CAST(? AS DATE)',
                [$range['to'], $range['from'], $actor->tenantId, $range['to'], $range['from']]
            );
            $override = array_column($tx->all('SELECT source, channel FROM channel_mappings WHERE tenant_id = ?', [$actor->tenantId]), 'channel', 'source');
            $channelOf = static fn(string $s): string => $override[$s] ?? Channels::DEFAULT_CHANNEL[$s] ?? 'untracked';

            $spendBySource = [];
            $spendByCampaign = [];
            $totalSpend = 0.0;
            foreach ($spend as $r) {
                $a = (float) $r['allocated'];
                $totalSpend += $a;
                $spendBySource[$r['source']] = ($spendBySource[$r['source']] ?? 0) + $a;
                if ($r['campaign_key'] !== '') {
                    $k = $r['source'] . "\0" . $r['campaign_key'];
                    $spendByCampaign[$k] = ($spendByCampaign[$k] ?? 0) + $a;
                }
            }

            $bySource = [];
            foreach ($src as $r) $bySource[$r['source']] = self::counts($r) + ['withCampaign' => Cohort::i($r['with_campaign']), 'reconstructed' => Cohort::i($r['reconstructed'])];
            // a source with spend but no leads in the range still gets a row, so spend adds up and a wasteful source is not invisible
            foreach (array_keys($spendBySource) as $s) if (!isset($bySource[$s])) $bySource[$s] = self::zero() + ['withCampaign' => 0, 'reconstructed' => 0];

            $sources = [];
            foreach ($bySource as $source => $cts) {
                $ch = $channelOf((string) $source);
                $sp = $spendBySource[$source] ?? null;
                $step = array_intersect_key($cts, self::zero());
                $sources[] = ['source' => (string) $source, 'label' => Channels::sourceLabel((string) $source), 'channel' => $ch, 'channelLabel' => Channels::channelLabel($ch)] + $step + ['spend' => $sp !== null && $sp > 0 ? self::moneyFor($sp, $step) : null];
            }
            usort($sources, static fn($a, $b) => $b['leads'] <=> $a['leads'] ?: strcasecmp($a['label'], $b['label']));

            $campaigns = array_map(static function ($r) use ($spendByCampaign) {
                $cts = self::counts($r);
                $sp = $spendByCampaign[$r['source'] . "\0" . mb_strtolower((string) $r['campaign'])] ?? null;
                return ['source' => $r['source'], 'sourceLabel' => Channels::sourceLabel($r['source']), 'campaign' => $r['campaign']] + $cts + ['spend' => $sp !== null && $sp > 0 ? self::moneyFor($sp, $cts) : null];
            }, $camp);

            $channels = [];
            foreach (Channels::CHANNELS as $ch) {
                $rows = array_values(array_filter($sources, static fn($s) => $s['channel'] === $ch['key']));
                $cts = self::sumOf($rows);
                $sp = array_sum(array_map(static fn($r) => $r['spend']['spend'] ?? 0, $rows));
                $entry = ['channel' => $ch['key'], 'label' => $ch['label']] + $cts + ['spend' => $sp > 0 ? self::moneyFor($sp, $cts) : null];
                if ($entry['leads'] > 0 || $entry['spend']) $channels[] = $entry;
            }

            $totals = self::sumOf($sources);
            $wonWithoutSale = Cohort::i($tx->val('SELECT COUNT(*) FROM ' . $c['sql'] . ' f WHERE won AND revenue = 0', $c['params']));
            $untracked = $bySource['other']['leads'] ?? 0;
            $tracking = [
                'tracked' => $totals['leads'] - $untracked, 'untracked' => $untracked, 'direct' => $bySource['direct']['leads'] ?? 0,
                'withCampaign' => array_sum(array_column($bySource, 'withCampaign')), 'reconstructed' => array_sum(array_column($bySource, 'reconstructed')),
                /** Share of leads that carry a recorded source (everything except "Not recorded"). */
                'sharePercent' => Cohort::ratio($totals['leads'] - $untracked, $totals['leads']),
            ];
            return [
                'range' => Range::pub($range), 'model' => $q['model'], 'includesTestLeads' => $q['includeTest'], 'currency' => $range['currency'],
                'totals' => $totals + ['wonWithoutDealValue' => $wonWithoutSale], 'tracking' => $tracking, 'sources' => $sources, 'channels' => $channels, 'campaigns' => $campaigns,
                'money' => $totalSpend > 0 ? self::moneyFor($totalSpend, $totals) : null, 'lastLeadAt' => $lastAt !== null ? \App\Core\Time::iso($lastAt) : null,
            ];
        });
    }

    /**
     * The leads behind one number: same cohort SQL as `report`, so the list reproduces the count.
     *
     * @return array{items:array,page:array,total:int}
     */
    public static function drill(Actor $actor, array $q): array
    {
        $actor->assertCan('attribution:read');
        $sig = "attribution-drill:{$q['metric']}:{$q['model']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $sig, $cursor) {
            $range = Range::resolve($tx, $actor->tenantId, $q);
            $c = Cohort::build($actor, $range, $q);
            if (!$c) return ['items' => [], 'page' => ['hasMore' => false, 'next' => null], 'total' => 0];
            $where = [$q['metric'] === 'leads' ? '1' : 'f.' . Cohort::STEP_COLUMN[$q['metric']]];
            $extra = [];
            if (!empty($q['source'])) { $where[] = 'f.source = ?'; $extra[] = $q['source']; }
            if (isset($q['campaign']) && $q['campaign'] !== '') { $where[] = 'LOWER(f.campaign) = LOWER(?)'; $extra[] = $q['campaign']; }
            if (!empty($q['noCampaign'])) $where[] = 'f.campaign IS NULL';
            $base = implode(' AND ', $where);
            $total = Cohort::i($tx->val('SELECT COUNT(*) FROM ' . $c['sql'] . " f WHERE $base", array_merge($c['params'], $extra)));
            $params = array_merge($c['params'], $extra);
            $pred = Pagination::predicate($cursor, $params, 'f.created_at', 'f.id', 'desc', 'ts');
            $params[] = $q['limit'] + 1;
            $rows = $tx->all(
                'SELECT f.id, f.created_at AS createdAt, f.created_at AS _k, f.status, f.source, f.campaign, f.revenue, f.reconstructed, s.name AS stageName, c.first_name AS _f, c.last_name AS _l, u.name AS ownerName
                   FROM ' . $c['sql'] . ' f JOIN leads l ON l.id = f.id AND l.tenant_id = f.tenant_id JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id
                   LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id LEFT JOIN users u ON u.id = f.owner_id
                  WHERE ' . $base . ($pred ? " AND $pred" : '') . ' ORDER BY f.created_at DESC, f.id DESC LIMIT ?',
                $params
            );
            $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
            return ['items' => array_map([self::class, 'drillRow'], $pg['items']), 'page' => $pg['page'], 'total' => $total];
        });
    }

    public static function drillRow(array $r): array
    {
        $name = trim(implode(' ', array_filter([$r['_f'], $r['_l']])));
        return [
            'id' => $r['id'], 'name' => $name !== '' ? $name : 'Unnamed lead', 'createdAt' => $r['createdAt'], 'stageName' => $r['stageName'], 'status' => $r['status'], 'source' => $r['source'],
            'sourceLabel' => Channels::sourceLabel((string) $r['source']), 'campaign' => $r['campaign'], 'ownerName' => $r['ownerName'], 'revenue' => Cohort::f($r['revenue']), 'reconstructed' => (bool) $r['reconstructed'],
        ];
    }
}
