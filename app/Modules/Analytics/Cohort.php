<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Actor;
use App\Core\Time;
use App\Modules\Leads\Visibility;

/**
 * The shared definition of "which leads count" for Attribution and Reports, so a number and the list behind it come from the same SQL.
 *
 * A lead is in the cohort when it
 *   - is live (not archived),
 *   - was created inside the date range, with the range read as whole days in the WORKSPACE time zone (a daylight-saving day is 23 or 25 hours),
 *   - is visible to the caller (salespeople would see only their own),
 *   - is not a test lead, unless the caller switched "include test leads" on (decision D8: excluded by default, and the switch is visible).
 * Funnel steps are about what the lead EVER reached, taken from its history, so a lead that was qualified and then lost still counts as qualified.
 *
 * The cohort is a derived table (no CTEs or window functions), so it runs on every MySQL / MariaDB a shared host may offer. Use it as
 * `FROM {$c['sql']} f` with `$c['params']` first in the parameter list.
 */
final class Cohort
{
    public const STEP_COLUMN = ['leads' => '1', 'qualified' => 'qualified', 'visits' => 'visit_done', 'booked' => 'booked', 'won' => 'won'];

    /** @return string[] */
    public static function stepKeys(): array
    {
        return array_keys(self::STEP_COLUMN);
    }

    /**
     * @param array{model:string,includeTest:bool,owner?:?string} $o
     * @return array{sql:string,params:array<int,mixed>}|null null when the caller may not read leads at all
     */
    public static function build(Actor $actor, array $range, array $o): ?array
    {
        $params = [$actor->tenantId];
        $vis = Visibility::lead($actor, 'l', $params);
        if ($vis === false) return null;
        $params[] = Time::db($range['start']);
        $params[] = Time::db($range['end']);
        $touchFilter = $o['model'] === 'first' ? "AND a.touch_type = 'first_touch'" : '';
        $test = !empty($o['includeTest']) ? '' : "AND NOT JSON_CONTAINS(l.tags, '\"test\"')";
        $ownerSql = '';
        $owner = $o['owner'] ?? null;
        if ($owner === 'unassigned') $ownerSql = 'AND l.owner_id IS NULL';
        elseif ($owner) { $params[] = $owner; $ownerSql = 'AND l.owner_id = ?'; }
        $sql = "(
          SELECT s.*, (s.qualified_hist OR s.visit_raw OR s.booked_raw OR s.won) AS qualified, (s.visit_raw OR s.booked_raw OR s.won) AS visit_done, (s.booked_raw OR s.won) AS booked
            FROM (
              SELECT c.*,
                     (EXISTS (SELECT 1 FROM lead_stage_history h WHERE h.tenant_id = c.tenant_id AND h.lead_id = c.id AND h.to_status IN ('qualified', 'viewing_scheduled', 'negotiation', 'won'))
                      OR c.status IN ('qualified', 'viewing_scheduled', 'negotiation', 'won')) AS qualified_hist,
                     (EXISTS (SELECT 1 FROM viewings v WHERE v.tenant_id = c.tenant_id AND v.lead_id = c.id AND v.status = 'completed')
                      OR EXISTS (SELECT 1 FROM lead_stage_history h JOIN pipeline_stages ps ON ps.id = h.to_stage_id AND ps.tenant_id = h.tenant_id
                                  WHERE h.tenant_id = c.tenant_id AND h.lead_id = c.id AND ps.stage_key = 'viewing_completed')) AS visit_raw,
                     (EXISTS (SELECT 1 FROM reservations r WHERE r.tenant_id = c.tenant_id AND r.lead_id = c.id AND r.reserved_at IS NOT NULL)
                      OR EXISTS (SELECT 1 FROM lead_stage_history h JOIN pipeline_stages ps ON ps.id = h.to_stage_id AND ps.tenant_id = h.tenant_id
                                  WHERE h.tenant_id = c.tenant_id AND h.lead_id = c.id AND ps.stage_key = 'booking_reserved')) AS booked_raw,
                     (c.status = 'won') AS won,
                     COALESCE((SELECT SUM(r.deal_value) FROM reservations r WHERE r.tenant_id = c.tenant_id AND r.lead_id = c.id AND r.status = 'completed'), 0) AS revenue
                FROM (
                  SELECT l.id, l.tenant_id, l.created_at, l.status, l.owner_id, l.stage_id, l.closed_at, l.first_response_at, l.first_response_due_at, l.loss_reason_code, l.estimated_value,
                         COALESCE(t.source, l.source) AS source,
                         NULLIF(TRIM(COALESCE(t.campaign, l.campaign, '')), '') AS campaign,
                         COALESCE(t.backfilled, 0) AS reconstructed
                    FROM leads l
                    LEFT JOIN marketing_attribution t ON t.id = (
                      SELECT a.id FROM marketing_attribution a WHERE a.tenant_id = l.tenant_id AND a.lead_id = l.id $touchFilter ORDER BY a.occurred_at DESC, a.id DESC LIMIT 1)
                   WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND $vis AND l.created_at >= ? AND l.created_at < ? $test $ownerSql
                ) c
            ) s
        )";
        return ['sql' => $sql, 'params' => $params];
    }

    public static function n(mixed $v): float|int
    {
        if ($v === null || $v === '') return 0;
        $f = (float) $v;
        return $f == floor($f) && abs($f) < 1e15 ? (int) $f : $f;
    }

    public static function i(mixed $v): int
    {
        return (int) round((float) ($v ?? 0));
    }

    public static function f(mixed $v): float
    {
        return (float) ($v ?? 0);
    }

    /** A ratio, or null (shown as "—") when the denominator is zero. Never NaN, never Infinity. */
    public static function ratio(float|int $num, float|int $den): ?float
    {
        return $den > 0 ? round($num / $den, 4) : null;
    }

    public static function per(float|int $amount, float|int $count): ?float
    {
        return $count > 0 ? round($amount / $count, 2) : null;
    }

    /** Median of a list of numbers (null when empty). */
    public static function median(array $values): ?float
    {
        if (!$values) return null;
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }
}
