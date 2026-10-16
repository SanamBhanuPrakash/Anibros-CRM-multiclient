<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Actor;
use App\Core\Db;
use App\Core\Tx;
use App\Modules\Leads\Visibility;
use App\Modules\Leads\Vocab;

/**
 * Dashboard numbers, computed from LIVE records only and scoped to what the caller may see.
 *
 * Definitions (so a number can always be reproduced from the lists):
 *  - a lead counts when it is not archived and, unless `includeTest` is set, is not tagged `test`
 *    ("Send test lead" deliveries are tagged `test`; they are kept out of business numbers by default)
 *  - status buckets partition the total: open + contacted + qualified + viewingScheduled + negotiation + converted(won) + lost + disqualified = total
 *  - pipeline value = estimated value of OPEN leads only (open … negotiation), not won/lost/disqualified ones
 *  - sales agents (own scope) see only their own leads, their own tasks and the viewings they run or that belong to their leads
 * Everything runs inside a read-only tenant transaction, so the workspace views apply as well.
 */
final class DashboardService
{
    private const OPEN_STATUSES = ['open', 'contacted', 'qualified', 'viewing_scheduled', 'negotiation'];

    /**
     * Live-lead predicate for alias `l` (its positional parameters are appended to $params). Null when the caller may not read leads at all.
     */
    public static function leadPredicate(Actor $actor, array &$params, bool $includeTest): ?string
    {
        $params[] = $actor->tenantId;
        $vis = Visibility::lead($actor, 'l', $params);
        if ($vis === false) return null;
        return 'l.tenant_id = ? AND l.deleted_at IS NULL AND ' . $vis . ($includeTest ? '' : " AND NOT JSON_CONTAINS(l.tags, '\"test\"')");
    }

    private static function n(mixed $v): int|float
    {
        if ($v === null || $v === '') return 0;
        $f = (float) $v;
        return $f == floor($f) && abs($f) < 1e15 ? (int) $f : $f;
    }

    public static function metrics(Actor $actor, array $o): array
    {
        $actor->assertCan('dashboard:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $o) {
            $lp = [];
            $leadWhere = self::leadPredicate($actor, $lp, $o['includeTest']);
            $leads = ['total' => 0, 'new' => 0, 'contacted' => 0, 'qualified' => 0, 'viewingScheduled' => 0, 'negotiation' => 0, 'converted' => 0, 'lost' => 0, 'disqualified' => 0, 'totalPipelineValue' => 0,
                'wonValue' => 0, 'wonDealValue' => 0, 'wonWithoutDealValue' => 0, 'conversionRate' => '0.0%', 'unassigned' => 0];
            $stages = [];
            $recent = [];
            $outcome = ['lost' => [], 'notAFit' => []];
            if ($leadWhere) {
                $byStatus = $tx->all("SELECT l.status AS s, COUNT(*) AS c, COALESCE(SUM(l.estimated_value), 0) AS v FROM leads l WHERE $leadWhere GROUP BY l.status", $lp);
                $c = static function (string $s) use ($byStatus): int {
                    foreach ($byStatus as $r) if ($r['s'] === $s) return (int) $r['c'];
                    return 0;
                };
                $total = array_sum(array_map(static fn($r) => (int) $r['c'], $byStatus));
                // D3: the revenue figure is the recorded sale of a unit (a completed deal), never an estimate typed on the lead
                $sold = $tx->val("SELECT COALESCE(SUM(r.deal_value), 0) FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id WHERE r.status = 'completed' AND l.status = 'won' AND $leadWhere", $lp);
                $unrecorded = $tx->val("SELECT COUNT(*) FROM leads l WHERE $leadWhere AND l.status = 'won' AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.lead_id = l.id AND r.tenant_id = l.tenant_id AND r.status = 'completed')", $lp);
                $unassigned = $tx->val("SELECT COUNT(*) FROM leads l WHERE $leadWhere AND l.owner_id IS NULL", $lp);
                $pipelineValue = 0.0;
                $wonValue = 0.0;
                foreach ($byStatus as $r) {
                    if (in_array($r['s'], self::OPEN_STATUSES, true)) $pipelineValue += (float) $r['v'];
                    if ($r['s'] === 'won') $wonValue = (float) $r['v'];
                }
                $leads = [
                    'total' => $total, 'new' => $c('open'), 'contacted' => $c('contacted'), 'qualified' => $c('qualified'), 'viewingScheduled' => $c('viewing_scheduled'), 'negotiation' => $c('negotiation'),
                    'converted' => $c('won'), 'lost' => $c('lost'), 'disqualified' => $c('disqualified'), 'totalPipelineValue' => $pipelineValue, 'wonValue' => $wonValue,
                    'wonDealValue' => (float) $sold, 'wonWithoutDealValue' => (int) $unrecorded,
                    'conversionRate' => sprintf('%.1f%%', $total > 0 ? $c('won') / $total * 100 : 0), 'unassigned' => (int) $unassigned,
                ];
                $sp = [$actor->tenantId];
                $joinPred = self::leadPredicate($actor, $sp, $o['includeTest']);
                $sp[] = $actor->tenantId;
                $rows = $tx->all(
                    "SELECT ps.id, ps.name, ps.stage_key AS stageKey, ps.color, COUNT(l.id) AS leadCount, COALESCE(SUM(l.estimated_value), 0) AS stageValue
                       FROM pipeline_stages ps JOIN pipelines p ON p.id = ps.pipeline_id AND p.tenant_id = ? AND p.is_default = 1 AND p.archived_at IS NULL
                       LEFT JOIN leads l ON l.stage_id = ps.id AND $joinPred
                      WHERE ps.tenant_id = ? AND ps.archived_at IS NULL
                      GROUP BY ps.id, ps.name, ps.stage_key, ps.color, ps.order_index ORDER BY ps.order_index",
                    $sp
                );
                $stages = array_map(static fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'stageKey' => $r['stageKey'], 'color' => $r['color'], 'leadCount' => (int) $r['leadCount'], 'stageValue' => (float) $r['stageValue']], $rows);
                // Why leads were lost / were not a fit. Old leads closed before reason codes existed are grouped as "No reason recorded", never guessed.
                $why = $tx->all("SELECT l.status AS s, l.loss_reason_code AS code, COUNT(*) AS c FROM leads l WHERE $leadWhere AND l.status IN ('lost','disqualified') GROUP BY l.status, l.loss_reason_code ORDER BY c DESC, (l.loss_reason_code IS NULL), l.loss_reason_code", $lp);
                $asReasons = static fn(string $status) => array_values(array_map(
                    static fn($r) => ['code' => $r['code'] ?? 'unspecified', 'label' => Vocab::reasonLabel($r['code']), 'count' => (int) $r['c']],
                    array_filter($why, static fn($r) => $r['s'] === $status)
                ));
                $outcome = ['lost' => $asReasons('lost'), 'notAFit' => $asReasons('disqualified')];
                $recent = $tx->all(
                    "SELECT l.id, l.status AS stage, l.source AS lead_source, l.created_at, c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email, c.phone AS contact_phone
                       FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE $leadWhere ORDER BY l.created_at DESC, l.id DESC LIMIT 10",
                    $lp
                );
            }
            return [
                'leads' => $leads, 'stages' => $stages, 'viewings' => self::viewingCounts($tx, $actor), 'tasks' => self::taskCounts($tx, $actor), 'properties' => self::propertyCounts($tx, $actor),
                'recentLeads' => $recent, 'outcomeReasons' => $outcome, 'includesTestLeads' => $o['includeTest'],
            ];
        });
    }

    private static function viewingCounts(Tx $tx, Actor $actor): array
    {
        $scope = $actor->scopeIfAllowed('viewings:read');
        if (!$scope) return ['total' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
        $params = [$actor->tenantId];
        $own = '';
        if ($scope === 'own') { $own = ' AND (v.agent_id = ? OR l.owner_id = ?)'; array_push($params, $actor->userId, $actor->userId); }
        $r = $tx->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(v.status IN ('scheduled','confirmed')), 0) AS scheduled, COALESCE(SUM(v.status = 'completed'), 0) AS completed, COALESCE(SUM(v.status = 'cancelled'), 0) AS cancelled
               FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id WHERE v.tenant_id = ?$own",
            $params
        );
        return ['total' => (int) $r['total'], 'scheduled' => (int) $r['scheduled'], 'completed' => (int) $r['completed'], 'cancelled' => (int) $r['cancelled']];
    }

    private static function taskCounts(Tx $tx, Actor $actor): array
    {
        $scope = $actor->scopeIfAllowed('tasks:read');
        if (!$scope) return ['total' => 0, 'pending' => 0, 'completed' => 0, 'overdue' => 0];
        $params = [$actor->tenantId];
        $own = '';
        if ($scope === 'own') { $own = ' AND t.assigned_to = ?'; $params[] = $actor->userId; }
        $r = $tx->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(t.status IN ('pending','in_progress')), 0) AS pending, COALESCE(SUM(t.status = 'completed'), 0) AS completed,
                    COALESCE(SUM(t.status IN ('pending','in_progress') AND t.due_date < NOW(3)), 0) AS overdue
               FROM tasks t WHERE t.tenant_id = ? AND t.deleted_at IS NULL$own",
            $params
        );
        return ['total' => (int) $r['total'], 'pending' => (int) $r['pending'], 'completed' => (int) $r['completed'], 'overdue' => (int) $r['overdue']];
    }

    private static function propertyCounts(Tx $tx, Actor $actor): array
    {
        if (!$actor->scopeIfAllowed('properties:read')) return ['total' => 0, 'available' => 0, 'underOffer' => 0, 'reserved' => 0, 'sold' => 0, 'rented' => 0];
        $r = $tx->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS available, COALESCE(SUM(status = 'under_offer'), 0) AS under_offer, COALESCE(SUM(status = 'reserved'), 0) AS reserved,
                    COALESCE(SUM(status = 'sold'), 0) AS sold, COALESCE(SUM(status = 'rented'), 0) AS rented FROM properties WHERE tenant_id = ? AND deleted_at IS NULL",
            [$actor->tenantId]
        );
        return ['total' => (int) $r['total'], 'available' => (int) $r['available'], 'underOffer' => (int) $r['under_offer'], 'reserved' => (int) $r['reserved'], 'sold' => (int) $r['sold'], 'rented' => (int) $r['rented']];
    }

    /**
     * Where leads came from. `source` is the lead's recorded source; "other" means no source was recorded and is reported as UNTRACKED,
     * not as an attributed channel. Campaign rows come only from campaign names that were actually recorded on leads.
     */
    public static function attribution(Actor $actor, array $o): array
    {
        $actor->assertCan('attribution:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $o) {
            $p = [];
            $where = self::leadPredicate($actor, $p, $o['includeTest']);
            if (!$where) return ['total_leads' => 0, 'total_attributed' => 0, 'untracked_leads' => 0, 'includes_test_leads' => $o['includeTest'], 'source_breakdown' => [], 'campaign_performance' => [], 'stage_by_source' => []];
            $src = $tx->all(
                "SELECT l.source AS source, COUNT(*) AS lead_count, COALESCE(SUM(l.status IN ('qualified','viewing_scheduled','negotiation','won')), 0) AS qualified_count, COALESCE(SUM(l.status = 'won'), 0) AS won_count
                   FROM leads l WHERE $where GROUP BY l.source ORDER BY lead_count DESC, l.source",
                $p
            );
            $camp = $tx->all(
                "SELECT TRIM(l.campaign) AS campaign_name, l.source AS source, COUNT(*) AS lead_count, COALESCE(SUM(l.status = 'won'), 0) AS won_count
                   FROM leads l WHERE $where AND l.campaign IS NOT NULL AND TRIM(l.campaign) <> '' GROUP BY TRIM(l.campaign), l.source ORDER BY lead_count DESC, campaign_name LIMIT 50",
                $p
            );
            $stage = $tx->all("SELECT l.source AS source, l.status AS stage, COUNT(*) AS cnt FROM leads l WHERE $where GROUP BY l.source, l.status ORDER BY cnt DESC", $p);
            $int = static fn(array $rows, array $keys) => array_map(static function ($r) use ($keys) { foreach ($keys as $k) $r[$k] = (int) $r[$k]; return $r; }, $rows);
            $total = array_sum(array_map(static fn($r) => (int) $r['lead_count'], $src));
            $untracked = 0;
            foreach ($src as $r) if ($r['source'] === 'other') $untracked = (int) $r['lead_count'];
            return [
                'total_leads' => $total, 'total_attributed' => $total - $untracked, 'untracked_leads' => $untracked, 'includes_test_leads' => $o['includeTest'],
                'source_breakdown' => $int($src, ['lead_count', 'qualified_count', 'won_count']), 'campaign_performance' => $int($camp, ['lead_count', 'won_count']),
                'stage_by_source' => array_map(static fn($r) => ['source' => $r['source'], 'stage' => $r['stage'], 'count' => (int) $r['cnt']], $stage),
            ];
        });
    }
}
