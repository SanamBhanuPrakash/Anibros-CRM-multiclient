<?php
declare(strict_types=1);

namespace App\Modules\Home;

use App\Core\Actor;
use App\Core\Db;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Leads\Visibility;

/**
 * Home: "what needs me today". Every number here is a count of the same rows its list shows, scoped to what the caller may see.
 *
 *   myDay       MY work: tasks assigned to me, visits I run or that belong to my leads, my leads still waiting for a first reply.
 *               Everyone has one (managers too); it needs the matching read permission, otherwise that part is absent.
 *   team        managers only (assign-all): unassigned leads, first replies already late, overdue tasks across the team
 *   pipeline    open leads and their estimated value by stage for the leads the caller may see (test leads left out, as in reports)
 *   attention   operators only: lead deliveries that need a human, automation runs that failed
 *   getStarted  owners and admins: the short setup checklist, until it is done
 * "Today" and "this week" follow the WORKSPACE time zone, including daylight-saving days.
 */
final class HomeService
{
    private const LIMIT = 10;
    /** A visit's reminder task is the visit itself, shown once as a visit, never again as a task. */
    private const NOT_REMINDER = 'AND NOT EXISTS (SELECT 1 FROM viewings rv WHERE rv.reminder_task_id = k.id AND rv.tenant_id = k.tenant_id)';

    /**
     * The workspace's calendar day around `$at` (default: now), as instants. A daylight-saving day is 23 or 25 hours long, not 24.
     *
     * @return array{start_today:\DateTimeImmutable,start_tomorrow:\DateTimeImmutable,start_in_8:\DateTimeImmutable,local_date:string}
     */
    public static function dayBounds(string $tz, ?\DateTimeInterface $at = null): array
    {
        try { $zone = new \DateTimeZone($tz ?: 'UTC'); } catch (\Throwable) { $zone = new \DateTimeZone('UTC'); }
        $utc = new \DateTimeZone('UTC');
        $local = ($at ? \DateTimeImmutable::createFromInterface($at) : new \DateTimeImmutable('now'))->setTimezone($zone);
        $midnight = $local->setTime(0, 0, 0);
        return [
            'start_today' => $midnight->setTimezone($utc),
            'start_tomorrow' => $midnight->modify('+1 day')->setTimezone($utc),
            'start_in_8' => $midnight->modify('+8 days')->setTimezone($utc),
            'local_date' => $local->format('Y-m-d'),
        ];
    }

    public static function home(Actor $actor): array
    {
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $tz = (string) ($tx->val('SELECT timezone FROM tenants WHERE id = ?', [$actor->tenantId]) ?: 'UTC');
            $day = self::dayBounds($tz);
            return [
                'today' => $day['local_date'], 'timezone' => $tz, 'myDay' => self::myDay($tx, $actor, $actor->userId, $day), 'team' => self::team($tx, $actor),
                'pipeline' => self::pipeline($tx, $actor), 'attention' => self::attention($tx, $actor), 'getStarted' => self::getStarted($tx, $actor),
            ];
        });
    }

    private static function myDay(Tx $tx, Actor $actor, ?string $me, array $day): array
    {
        $t = $actor->tenantId;
        $out = ['tasks' => null, 'viewings' => null, 'waitingForReply' => null];
        if (!$me) return $out;
        $lim = self::LIMIT;

        if ($actor->scopeIfAllowed('tasks:read')) {
            $base = "FROM tasks k LEFT JOIN leads l ON l.id = k.lead_id AND l.tenant_id = k.tenant_id LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                      WHERE k.tenant_id = ? AND k.deleted_at IS NULL AND k.assigned_to = ? AND k.status IN ('pending','in_progress') " . self::NOT_REMINDER;
            $item = 'SELECT k.id, k.title, k.kind, k.priority, k.due_date AS dueDate, k.lead_id AS leadId, c.display_name AS leadName';
            $tom = Time::db($day['start_tomorrow']);
            $overdue = $tx->all("$item $base AND k.due_date < NOW(3) ORDER BY k.due_date, k.id LIMIT $lim", [$t, $me]);
            $overdueCount = (int) $tx->val("SELECT COUNT(*) $base AND k.due_date < NOW(3)", [$t, $me]);
            $today = $tx->all("$item $base AND k.due_date >= NOW(3) AND k.due_date < ? ORDER BY k.due_date, k.id LIMIT $lim", [$t, $me, $tom]);
            $todayCount = (int) $tx->val("SELECT COUNT(*) $base AND k.due_date >= NOW(3) AND k.due_date < ?", [$t, $me, $tom]);
            $out['tasks'] = ['overdue' => ['count' => $overdueCount, 'items' => $overdue], 'today' => ['count' => $todayCount, 'items' => $today]];
        }

        if ($actor->scopeIfAllowed('viewings:read')) {
            $base = "FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id JOIN properties p ON p.id = v.property_id AND p.tenant_id = v.tenant_id
                      LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                      WHERE v.tenant_id = ? AND l.deleted_at IS NULL AND v.status IN ('scheduled','confirmed') AND (v.agent_id = ? OR l.owner_id = ?)";
            $a = Time::db($day['start_today']);
            $b = Time::db($day['start_tomorrow']);
            $c8 = Time::db($day['start_in_8']);
            $today = $tx->all(
                "SELECT v.id, v.scheduled_at AS scheduledAt, v.duration_minutes AS durationMinutes, v.lead_id AS leadId, c.display_name AS leadName, p.title AS propertyTitle,
                        (DATE_ADD(v.scheduled_at, INTERVAL v.duration_minutes MINUTE) < NOW(3)) AS needsOutcome
                   $base AND v.scheduled_at >= ? AND v.scheduled_at < ? ORDER BY v.scheduled_at, v.id LIMIT $lim",
                [$t, $me, $me, $a, $b]
            );
            $today = array_map(static function ($r) { $r['durationMinutes'] = (int) $r['durationMinutes']; $r['needsOutcome'] = (bool) $r['needsOutcome']; return $r; }, $today);
            $todayCount = (int) $tx->val("SELECT COUNT(*) $base AND v.scheduled_at >= ? AND v.scheduled_at < ?", [$t, $me, $me, $a, $b]);
            $next7 = (int) $tx->val("SELECT COUNT(*) $base AND v.scheduled_at >= ? AND v.scheduled_at < ?", [$t, $me, $me, $b, $c8]);
            $out['viewings'] = ['today' => ['count' => $todayCount, 'items' => $today], 'next7Days' => $next7];
        }

        if ($actor->scopeIfAllowed('leads:read')) {
            $base = "FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                      WHERE l.tenant_id = ? AND l.deleted_at IS NULL AND l.owner_id = ? AND l.closed_at IS NULL AND l.first_response_at IS NULL AND l.first_response_due_at IS NOT NULL AND NOT JSON_CONTAINS(l.tags, '\"test\"')";
            $rows = $tx->all("SELECT l.id AS leadId, c.display_name AS leadName, l.first_response_due_at AS dueAt, (l.first_response_due_at < NOW(3)) AS overdue $base ORDER BY l.first_response_due_at, l.id LIMIT $lim", [$t, $me]);
            $rows = array_map(static function ($r) { $r['overdue'] = (bool) $r['overdue']; return $r; }, $rows);
            $out['waitingForReply'] = ['count' => (int) $tx->val("SELECT COUNT(*) $base", [$t, $me]), 'items' => $rows];
        }
        return $out;
    }

    /** Managers (those who may assign leads to anyone) see the team's problems; nobody else gets this section. */
    private static function team(Tx $tx, Actor $actor): ?array
    {
        if ($actor->scopeIfAllowed('leads:assign') !== 'all' || $actor->scopeIfAllowed('leads:read') !== 'all') return null;
        $t = $actor->tenantId;
        $live = "l.tenant_id = ? AND l.deleted_at IS NULL AND l.closed_at IS NULL AND NOT JSON_CONTAINS(l.tags, '\"test\"')";
        $unassigned = $tx->all("SELECT l.id AS leadId, c.display_name AS leadName, l.created_at AS createdAt FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE $live AND l.owner_id IS NULL ORDER BY l.created_at DESC, l.id LIMIT 5", [$t]);
        $unassignedCount = (int) $tx->val("SELECT COUNT(*) FROM leads l WHERE $live AND l.owner_id IS NULL", [$t]);
        $late = (int) $tx->val("SELECT COUNT(*) FROM leads l WHERE $live AND l.first_response_at IS NULL AND l.first_response_due_at < NOW(3)", [$t]);
        $overdueTasks = $actor->scopeIfAllowed('tasks:read') === 'all'
            ? (int) $tx->val("SELECT COUNT(*) FROM tasks k WHERE k.tenant_id = ? AND k.deleted_at IS NULL AND k.status IN ('pending','in_progress') AND k.due_date < NOW(3) " . self::NOT_REMINDER, [$t])
            : null;
        // visits that have ended and were never recorded: the manager's version of an overdue visit reminder
        $unrecorded = (int) $tx->val("SELECT COUNT(*) FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id WHERE v.tenant_id = ? AND l.deleted_at IS NULL AND v.status IN ('scheduled','confirmed') AND DATE_ADD(v.scheduled_at, INTERVAL v.duration_minutes MINUTE) < NOW(3)", [$t]);
        return ['unassignedLeads' => ['count' => $unassignedCount, 'items' => $unassigned], 'lateFirstReplies' => $late, 'overdueTasks' => $overdueTasks, 'unrecordedVisits' => $unrecorded];
    }

    private static function pipeline(Tx $tx, Actor $actor): ?array
    {
        $vp = [];
        $vis = Visibility::lead($actor, 'l', $vp);
        if ($vis === false) return null;
        $p = $tx->one('SELECT id, name FROM pipelines WHERE tenant_id = ? AND is_default = 1 AND archived_at IS NULL', [$actor->tenantId]);
        if (!$p) return null;
        $rows = $tx->all(
            "SELECT s.id, s.name, s.color, s.probability, COUNT(l.id) AS count, COALESCE(SUM(l.estimated_value), 0) AS value
               FROM pipeline_stages s LEFT JOIN leads l ON l.stage_id = s.id AND l.tenant_id = s.tenant_id AND l.deleted_at IS NULL AND $vis AND NOT JSON_CONTAINS(l.tags, '\"test\"')
              WHERE s.tenant_id = ? AND s.pipeline_id = ? AND s.archived_at IS NULL AND s.maps_to_status NOT IN ('won','lost','disqualified')
              GROUP BY s.id, s.name, s.color, s.probability, s.order_index ORDER BY s.order_index",
            array_merge($vp, [$actor->tenantId, $p['id']])
        );
        $stages = array_map(static fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'probability' => (int) $r['probability'], 'count' => (int) $r['count'], 'value' => (float) $r['value']], $rows);
        $weighted = 0.0;
        foreach ($stages as $s) $weighted += $s['value'] * $s['probability'] / 100;
        return [
            'pipelineId' => $p['id'], 'name' => $p['name'], 'stages' => $stages, 'openLeads' => array_sum(array_column($stages, 'count')), 'openValue' => array_sum(array_column($stages, 'value')),
            /** Estimated value x each stage's chance. The chances are the workspace's own estimates, not measured results. */
            'weightedValue' => $weighted,
        ];
    }

    private static function attention(Tx $tx, Actor $actor): ?array
    {
        if (!$actor->scopeIfAllowed('operations:read')) return null;
        $t = $actor->tenantId;
        $deliveries = (int) $tx->val("SELECT COUNT(*) FROM inbound_events WHERE tenant_id = ? AND status IN ('needs_review','failed') AND received_at > DATE_SUB(NOW(3), INTERVAL 7 DAY)", [$t]);
        $failed = (int) $tx->val("SELECT COUNT(*) FROM automation_executions WHERE tenant_id = ? AND status = 'failed'", [$t]);
        return ['deliveriesNeedingReview' => $deliveries, 'failedAutomationRuns' => $failed];
    }

    private static function getStarted(Tx $tx, Actor $actor): ?array
    {
        if (!$actor->scopeIfAllowed('tenant:update')) return null;
        $t = $actor->tenantId;
        $has = static fn(string $sql): bool => (int) $tx->val($sql, [$t]) > 0;
        $items = [
            ['key' => 'add_lead', 'label' => 'Add your first lead', 'href' => '/leads', 'done' => $has('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND deleted_at IS NULL')],
            ['key' => 'invite_team', 'label' => 'Invite your team', 'href' => '/settings/team', 'done' => (int) $tx->val("SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id = ? AND status = 'active'", [$t]) > 1],
            ['key' => 'add_property', 'label' => 'Add a property', 'href' => '/properties', 'done' => $has('SELECT COUNT(*) FROM properties WHERE tenant_id = ? AND deleted_at IS NULL')],
            ['key' => 'connect_source', 'label' => 'Connect a lead source (Meta, Google or your website)', 'href' => '/integrations/lead-sources', 'done' => $has('SELECT COUNT(*) FROM inbound_endpoints WHERE tenant_id = ? AND deleted_at IS NULL')],
            ['key' => 'turn_on_automation', 'label' => 'Turn on the follow-up automation', 'href' => '/settings/automation', 'done' => $has("SELECT COUNT(*) FROM automation_rules WHERE tenant_id = ? AND template IS NOT NULL AND status = 'active'")],
            ['key' => 'book_visit', 'label' => 'Book a site visit', 'href' => '/tasks?view=calendar', 'done' => $has('SELECT COUNT(*) FROM viewings WHERE tenant_id = ?')],
        ];
        return ['items' => $items, 'complete' => !in_array(false, array_column($items, 'done'), true)];
    }
}
