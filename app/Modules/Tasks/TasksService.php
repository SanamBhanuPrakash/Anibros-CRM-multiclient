<?php
declare(strict_types=1);

namespace App\Modules\Tasks;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Notify;
use App\Core\Pagination;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Contacts\Normalize;
use App\Modules\Leads\Visibility;

final class TasksService
{
    public const KINDS = ['todo', 'call', 'email', 'meeting', 'follow_up'];
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const OPEN = ['pending', 'in_progress'];
    private const FAR = '9999-12-31 23:59:59.999';
    private const RANK = "(CASE t.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END)";

    private const COLUMNS = "t.id, t.title, t.description, t.kind, t.priority, t.status, t.due_date AS dueDate, t.remind_before_minutes AS remindBeforeMinutes, t.recurrence,
        t.assigned_to AS assignedTo, au.name AS assigneeName, t.created_by AS createdBy, t.lead_id AS leadId, lc.display_name AS leadName, t.contact_id AS contactId, tc.display_name AS contactName,
        t.completed_at AS completedAt, (t.due_date < NOW(3) AND t.status IN ('pending','in_progress')) AS isOverdue, t.version, t.created_at AS createdAt, t.updated_at AS updatedAt";
    private const FROM = "tasks t LEFT JOIN users au ON au.id = t.assigned_to
        LEFT JOIN leads l ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
        LEFT JOIN contacts lc ON lc.id = l.contact_id AND lc.tenant_id = l.tenant_id
        LEFT JOIN contacts tc ON tc.id = t.contact_id AND tc.tenant_id = t.tenant_id";

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function dto(array $r): array
    {
        $r['recurrence'] = $r['recurrence'] !== null ? Json::dec($r['recurrence'], null) : null;
        $r['isOverdue'] = (bool) $r['isOverdue'];
        foreach (['remindBeforeMinutes', 'version'] as $k) if ($r[$k] !== null) $r[$k] = (int) $r[$k];
        return $r;
    }

    /** Own-scope: tasks assigned to me or that I created (a task I made for someone else must not vanish from my view). */
    private static function visibility(Actor $actor, string $alias, array &$params): string|false
    {
        $scope = $actor->scopeIfAllowed('tasks:read');
        if (!$scope) return false;
        if ($scope === 'all') return 'TRUE';
        $params[] = $actor->userId;
        $params[] = $actor->userId;
        return "($alias.assigned_to = ? OR $alias.created_by = ?)";
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('tasks:read');
        $sig = "tasks:{$q['sort']}:{$q['order']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        $rows = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $cursor) {
            $tz = self::timezone($tx, $actor->tenantId);
            $params = [$actor->tenantId];
            $where = ['t.tenant_id = ?', 't.deleted_at IS NULL'];
            $vparams = [];
            $vis = self::visibility($actor, 't', $vparams);
            if ($vis === false) throw Errors::forbidden();
            $where[] = $vis;
            array_push($params, ...$vparams);
            $status = $q['status'] ?? 'open';
            if ($status === 'open') $where[] = "t.status IN ('pending','in_progress')";
            elseif ($status === 'done') $where[] = "t.status IN ('completed','cancelled')";
            $assignee = $q['assignee'] ?? null;
            if ($assignee === 'me') { $where[] = 't.assigned_to = ?'; $params[] = $actor->userId; }
            elseif ($assignee === 'unassigned') $where[] = 't.assigned_to IS NULL';
            elseif ($assignee && $assignee !== 'any') { $where[] = 't.assigned_to = ?'; $params[] = $assignee; }
            if (!empty($q['priority'])) { $where[] = 't.priority = ?'; $params[] = $q['priority']; }
            if (!empty($q['kind'])) { $where[] = 't.kind = ?'; $params[] = $q['kind']; }
            if (!empty($q['leadId'])) { $where[] = 't.lead_id = ?'; $params[] = $q['leadId']; }
            if (!empty($q['contactId'])) { $where[] = 't.contact_id = ?'; $params[] = $q['contactId']; }
            if (!empty($q['due'])) {
                $sod = Recurrence::startOfDay(Time::now(), $tz);
                if ($q['due'] === 'overdue') $where[] = "t.due_date < NOW(3) AND t.status IN ('pending','in_progress')";
                elseif ($q['due'] === 'none') $where[] = 't.due_date IS NULL';
                else {
                    $where[] = 't.due_date >= ? AND t.due_date < ?';
                    $params[] = $sod;
                    $params[] = Recurrence::add($sod, $tz, 'daily', $q['due'] === 'today' ? 1 : 7);
                }
            }
            if (!empty($q['dueFrom'])) { $where[] = 't.due_date >= ?'; $params[] = $q['dueFrom']; }
            if (!empty($q['dueTo'])) { $where[] = 't.due_date < ?'; $params[] = $q['dueTo']; }
            if (isset($q['q']) && trim($q['q']) !== '') {
                $like = '%' . Normalize::escapeLike(mb_substr(trim($q['q']), 0, 100)) . '%';
                $where[] = '(t.title LIKE ? OR lc.display_name LIKE ?)';
                array_push($params, $like, $like);
            }
            $keyExpr = ['due' => "COALESCE(t.due_date, '" . self::FAR . "')", 'created' => 't.created_at', 'priority' => self::RANK][$q['sort']];
            $keyType = $q['sort'] === 'priority' ? 'num' : 'ts';
            $pred = Pagination::predicate($cursor, $params, $keyExpr, 't.id', $q['order'], $keyType);
            if ($pred) $where[] = $pred;
            $dir = $q['order'] === 'asc' ? 'ASC' : 'DESC';
            $params[] = $q['limit'] + 1;
            return $tx->all('SELECT ' . self::COLUMNS . ", $keyExpr AS _k FROM " . self::FROM . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $keyExpr $dir, t.id $dir LIMIT ?", $params);
        });
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
        return ['items' => array_map(static function ($r) { unset($r['_k']); return self::dto($r); }, $pg['items']), 'page' => $pg['page']];
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('tasks:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $params = [$actor->tenantId, $id];
            $vis = self::visibility($actor, 't', $params);
            if ($vis === false) throw Errors::forbidden();
            $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE t.tenant_id = ? AND t.id = ? AND t.deleted_at IS NULL AND $vis", $params);
            if (!$r) throw Errors::notFound('Task');
            return self::dto($r);
        });
    }

    /** Badge numbers for "my day" and (for managers) the team: overdue, due today, next 7 days. */
    public static function summary(Actor $actor): array
    {
        $actor->assertCan('tasks:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $tz = self::timezone($tx, $actor->tenantId);
            $sod = Recurrence::startOfDay(Time::now(), $tz);
            $tomorrow = Recurrence::add($sod, $tz, 'daily', 1);
            $week = Recurrence::add($sod, $tz, 'daily', 7);
            $me = $actor->userId;
            $params = [$me, $me, $tomorrow, $me, $tomorrow, $week, $me, $actor->tenantId];
            $vis = self::visibility($actor, 't', $params);
            if ($vis === false) throw Errors::forbidden();
            $r = $tx->one(
                "SELECT
                   COALESCE(SUM(t.assigned_to = ? AND t.due_date < NOW(3)), 0) AS mineOverdue,
                   COALESCE(SUM(t.assigned_to = ? AND t.due_date >= NOW(3) AND t.due_date < ?), 0) AS mineToday,
                   COALESCE(SUM(t.assigned_to = ? AND t.due_date >= ? AND t.due_date < ?), 0) AS mineUpcoming,
                   COALESCE(SUM(t.assigned_to = ?), 0) AS mineOpen,
                   COALESCE(SUM(t.due_date < NOW(3)), 0) AS teamOverdue,
                   COALESCE(SUM(t.assigned_to IS NULL), 0) AS unassigned,
                   COUNT(*) AS teamOpen
                 FROM tasks t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.status IN ('pending','in_progress') AND $vis",
                $params
            );
            return [
                'mine' => ['overdue' => (int) $r['mineOverdue'], 'today' => (int) $r['mineToday'], 'upcoming' => (int) $r['mineUpcoming'], 'open' => (int) $r['mineOpen']],
                'team' => $actor->scopeIfAllowed('tasks:read') === 'all' ? ['overdue' => (int) $r['teamOverdue'], 'unassigned' => (int) $r['unassigned'], 'open' => (int) $r['teamOpen']] : null,
            ];
        });
    }

    // ── writes ────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('tasks:create');
        return Db::tenant($actor)->tx(static fn(Tx $tx) => self::createInTx($tx, $actor, $in)['task']);
    }

    /**
     * Task creation inside the caller's transaction (the API, and the automation engine). `dedupeKey` makes creation exactly-once: a second call with
     * the same key returns the first task and creates nothing. `silent` skips the "New task for you" notification when the caller sends its own.
     *
     * @return array{task:array,created:bool}
     */
    public static function createInTx(Tx $tx, Actor $actor, array $in, array $opts = []): array
    {
        $actor->assertCan('tasks:create');
        $assignee = self::resolveAssignee($tx, $actor, array_key_exists('assignedTo', $in) ? $in['assignedTo'] : false, $actor->userId);
        self::assertLinks($tx, $actor, $in['leadId'] ?? null, $in['contactId'] ?? null);
        $due = $in['dueDate'] ?? null;
        $mins = $in['remindBeforeMinutes'] ?? null;
        $remind = self::remindAt($due, $mins);
        $id = $tx->insertIgnore('tasks', [
            'tenant_id' => $actor->tenantId, 'title' => trim($in['title']), 'description' => !empty($in['description']) ? trim($in['description']) : null, 'kind' => $in['kind'] ?? 'todo',
            'priority' => $in['priority'] ?? 'medium', 'due_date' => $due, 'remind_before_minutes' => $mins, 'remind_at' => $remind,
            'recurrence' => !empty($in['recurrence']) ? Json::enc($in['recurrence']) : null, 'assigned_to' => $assignee, 'lead_id' => $in['leadId'] ?? null,
            'contact_id' => $in['contactId'] ?? null, 'created_by' => $actor->userId, 'dedupe_key' => $opts['dedupeKey'] ?? null,
        ]);
        if (!$id) {
            $existing = $tx->val('SELECT id FROM tasks WHERE tenant_id = ? AND dedupe_key = ?', [$actor->tenantId, $opts['dedupeKey'] ?? null]);
            return ['task' => self::fetch($tx, $actor->tenantId, (string) $existing), 'created' => false];
        }
        $dto = self::fetch($tx, $actor->tenantId, $id);
        if ($dto['leadId']) {
            self::logLeadActivity($tx, $actor, $dto['leadId'], 'task_created', 'Task added: ' . $dto['title'], ['taskId' => $id]);
            self::syncLeadFollowup($tx, $actor->tenantId, $dto['leadId']);
        }
        if (empty($opts['silent'])) self::notifyAssignment($tx, $actor, $dto);
        Audit::log($tx, 'task.create', 'task', $id, null, ['title' => $dto['title'], 'assignedTo' => $dto['assignedTo'], 'leadId' => $dto['leadId']]);
        Events::emit($tx, 'task.created', $id, ['assignedTo' => $dto['assignedTo'], 'leadId' => $dto['leadId']]);
        return ['task' => $dto, 'created' => true];
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('tasks:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $row = self::lock($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) throw Errors::preconditionFailed();
            if (!in_array($row['status'], self::OPEN, true)) throw Errors::conflict('This task is finished. Reopen it to change it.');
            $before = self::fetch($tx, $actor->tenantId, $id);
            $set = ['version' => new Raw('version + 1')];
            if (array_key_exists('title', $patch)) $set['title'] = trim($patch['title']);
            if (array_key_exists('description', $patch)) $set['description'] = !empty($patch['description']) ? trim($patch['description']) : null;
            if (array_key_exists('kind', $patch)) $set['kind'] = $patch['kind'];
            if (array_key_exists('priority', $patch)) $set['priority'] = $patch['priority'];
            if (array_key_exists('status', $patch)) $set['status'] = $patch['status'];
            if (array_key_exists('recurrence', $patch)) $set['recurrence'] = !empty($patch['recurrence']) ? Json::enc($patch['recurrence']) : null;
            if (array_key_exists('assignedTo', $patch)) $set['assigned_to'] = self::resolveAssignee($tx, $actor, $patch['assignedTo'], null);
            if (array_key_exists('leadId', $patch) || array_key_exists('contactId', $patch)) {
                self::assertLinks($tx, $actor, $patch['leadId'] ?? null, $patch['contactId'] ?? null);
                if (array_key_exists('leadId', $patch)) $set['lead_id'] = $patch['leadId'];
                if (array_key_exists('contactId', $patch)) $set['contact_id'] = $patch['contactId'];
            }
            if (array_key_exists('dueDate', $patch) || array_key_exists('remindBeforeMinutes', $patch)) {
                $due = array_key_exists('dueDate', $patch) ? $patch['dueDate'] : ($before['dueDate'] ? Time::parse($before['dueDate']) : null);
                $mins = array_key_exists('remindBeforeMinutes', $patch) ? $patch['remindBeforeMinutes'] : $before['remindBeforeMinutes'];
                $set['due_date'] = $due;
                $set['remind_before_minutes'] = $mins;
                $set['remind_at'] = self::remindAt($due, $mins);
                $set['reminder_sent_at'] = null; // a rescheduled task earns fresh reminders
                if ($due && $due->getTimestamp() > time()) $set['overdue_notified_at'] = null;
            }
            $tx->update('tasks', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            $after = self::fetch($tx, $actor->tenantId, $id);
            foreach (array_unique(array_filter([$before['leadId'], $after['leadId']])) as $leadId) self::syncLeadFollowup($tx, $actor->tenantId, $leadId);
            if ($after['assignedTo'] !== $before['assignedTo']) self::notifyAssignment($tx, $actor, $after);
            Audit::log($tx, 'task.update', 'task', $id, $before, $after);
            Events::emit($tx, 'task.updated', $id, ['version' => $after['version']]);
            return $after;
        });
    }

    /** @return array{task:array,next:?array} */
    public static function complete(Actor $actor, string $id, ?int $expectedVersion = null): array
    {
        $actor->assertCan('tasks:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $expectedVersion) {
            $row = self::lock($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) throw Errors::preconditionFailed();
            return self::completeInTx($tx, $actor, $id, $row);
        });
    }

    private static function completeInTx(Tx $tx, Actor $actor, string $id, array $row): array
    {
        if (!in_array($row['status'], self::OPEN, true)) throw Errors::conflict('This task is already finished');
        $tx->run("UPDATE tasks SET status = 'completed', completed_at = NOW(3), completed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$actor->userId, $id, $actor->tenantId]);
        $task = self::fetch($tx, $actor->tenantId, $id);
        $next = null;
        $rec = $tx->one('SELECT recurrence, recurrence_root_id, due_date, remind_before_minutes FROM tasks WHERE id = ?', [$id]);
        if ($rec['recurrence']) {
            $tz = self::timezone($tx, $actor->tenantId);
            $due = Recurrence::next($rec['due_date'] ? Time::parse($rec['due_date']) : Time::now(), Json::dec($rec['recurrence'], []), $tz);
            $mins = $rec['remind_before_minutes'] !== null ? (int) $rec['remind_before_minutes'] : null;
            $nid = $tx->insert('tasks', [
                'tenant_id' => $actor->tenantId, 'title' => $task['title'], 'description' => $task['description'], 'kind' => $task['kind'], 'priority' => $task['priority'], 'due_date' => $due,
                'remind_before_minutes' => $mins, 'remind_at' => self::remindAt($due, $mins), 'recurrence' => $rec['recurrence'], 'recurrence_root_id' => $rec['recurrence_root_id'] ?: $id,
                'assigned_to' => $task['assignedTo'], 'lead_id' => $task['leadId'], 'contact_id' => $task['contactId'], 'created_by' => $task['createdBy'],
            ]);
            $next = self::fetch($tx, $actor->tenantId, $nid);
            Events::emit($tx, 'task.created', $nid, ['recurringFrom' => $id]);
        }
        if ($task['leadId']) {
            self::logLeadActivity($tx, $actor, $task['leadId'], 'task_completed', 'Task completed: ' . $task['title'], ['taskId' => $id]);
            self::syncLeadFollowup($tx, $actor->tenantId, $task['leadId']);
        }
        Audit::log($tx, 'task.complete', 'task', $id, null, ['nextId' => $next['id'] ?? null]);
        Events::emit($tx, 'task.completed', $id, ['leadId' => $task['leadId']]);
        return ['task' => $task, 'next' => $next];
    }

    public static function reopen(Actor $actor, string $id): array
    {
        $actor->assertCan('tasks:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = self::lock($tx, $actor, $id);
            if (in_array($row['status'], self::OPEN, true)) throw Errors::conflict('This task is already open');
            $tx->run("UPDATE tasks SET status = 'pending', completed_at = NULL, completed_by = NULL, reminder_sent_at = NULL, overdue_notified_at = NULL, version = version + 1 WHERE id = ? AND tenant_id = ?", [$id, $actor->tenantId]);
            $dto = self::fetch($tx, $actor->tenantId, $id);
            if ($dto['leadId']) self::syncLeadFollowup($tx, $actor->tenantId, $dto['leadId']);
            Audit::log($tx, 'task.reopen', 'task', $id);
            Events::emit($tx, 'task.updated', $id, ['reopened' => true]);
            return $dto;
        });
    }

    public static function cancel(Actor $actor, string $id): array
    {
        $actor->assertCan('tasks:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = self::lock($tx, $actor, $id);
            if (!in_array($row['status'], self::OPEN, true)) throw Errors::conflict('This task is already finished');
            $tx->run("UPDATE tasks SET status = 'cancelled', version = version + 1 WHERE id = ? AND tenant_id = ?", [$id, $actor->tenantId]);
            $dto = self::fetch($tx, $actor->tenantId, $id);
            if ($dto['leadId']) self::syncLeadFollowup($tx, $actor->tenantId, $dto['leadId']);
            Audit::log($tx, 'task.cancel', 'task', $id);
            Events::emit($tx, 'task.updated', $id, ['cancelled' => true]);
            return $dto;
        });
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('tasks:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = $tx->one('SELECT lead_id, title FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Task');
            $tx->run('UPDATE tasks SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            if ($row['lead_id']) self::syncLeadFollowup($tx, $actor->tenantId, $row['lead_id']);
            Audit::log($tx, 'task.delete', 'task', $id, ['title' => $row['title']]);
            Events::emit($tx, 'task.deleted', $id);
        });
    }

    /** @param array{ids:string[],action:string,assignedTo?:?string} $in */
    public static function bulk(Actor $actor, array $in): array
    {
        $actor->assertCan('tasks:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $skipped = [];
            $updated = 0;
            foreach (array_values(array_unique($in['ids'])) as $id) {
                $tx->run('SAVEPOINT bulk_task');
                try {
                    $row = self::lock($tx, $actor, $id);
                    if ($in['action'] === 'complete') self::completeInTx($tx, $actor, $id, $row);
                    else {
                        if (!in_array($row['status'], self::OPEN, true)) throw Errors::conflict('This task is already finished');
                        if ($in['action'] === 'cancel') $tx->run("UPDATE tasks SET status = 'cancelled', version = version + 1 WHERE id = ? AND tenant_id = ?", [$id, $actor->tenantId]);
                        else {
                            $to = self::resolveAssignee($tx, $actor, $in['assignedTo'] ?? null, null);
                            $tx->run('UPDATE tasks SET assigned_to = ?, version = version + 1 WHERE id = ? AND tenant_id = ?', [$to, $id, $actor->tenantId]);
                            self::notifyAssignment($tx, $actor, self::fetch($tx, $actor->tenantId, $id));
                        }
                        $lead = $tx->val('SELECT lead_id FROM tasks WHERE id = ?', [$id]);
                        if ($lead) self::syncLeadFollowup($tx, $actor->tenantId, $lead);
                        Audit::log($tx, 'task.' . $in['action'], 'task', $id, null, ['bulk' => true]);
                        Events::emit($tx, 'task.updated', $id, ['bulk' => $in['action']]);
                    }
                    $tx->run('RELEASE SAVEPOINT bulk_task');
                    $updated++;
                } catch (AppError $e) {
                    $tx->run('ROLLBACK TO SAVEPOINT bulk_task');
                    if ($e->status >= 500) throw $e;
                    $skipped[] = ['id' => $id, 'reason' => $e->errors[0]['message'] ?? $e->detail ?? $e->getMessage()];
                }
            }
            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    public static function fetch(Tx $tx, string $tenantId, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE t.id = ? AND t.tenant_id = ? AND t.deleted_at IS NULL', [$id, $tenantId]);
        return $r ? self::dto($r) : null;
    }

    private static function lock(Tx $tx, Actor $actor, string $id): array
    {
        $row = $tx->one('SELECT id, status, version, assigned_to, created_by FROM tasks WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
        if (!$row) throw Errors::notFound('Task');
        // Own-scope users act on tasks assigned to them or created by them; anything else does not exist for them.
        if ($actor->scopeIfAllowed('tasks:update') === 'own' && $row['assigned_to'] !== $actor->userId && $row['created_by'] !== $actor->userId) throw Errors::notFound('Task');
        return $row;
    }

    private static function remindAt(?\DateTimeInterface $due, ?int $minutes): ?\DateTimeImmutable
    {
        if ($minutes === null) return null;
        if (!$due) throw Errors::validation([['path' => 'body.remindBeforeMinutes', 'message' => 'Set a due date to get a reminder']]);
        return \DateTimeImmutable::createFromInterface($due)->modify('-' . $minutes . ' minutes');
    }

    private static function timezone(Tx $tx, string $tenantId): string
    {
        return (string) ($tx->val('SELECT timezone FROM tenants WHERE id = ?', [$tenantId]) ?: 'Asia/Kolkata');
    }

    /** Managers may hand work to anyone; everyone else only keeps it for themselves. $requested === false means "not specified". */
    private static function resolveAssignee(Tx $tx, Actor $actor, string|null|false $requested, ?string $fallback): ?string
    {
        $manager = $actor->kind === 'system' || $actor->scopeIfAllowed('tasks:update') === 'all';
        if ($requested === false) $to = $fallback;
        else {
            if (!$manager && $requested !== $actor->userId) throw Errors::forbidden('Only managers can assign a task to someone else');
            $to = $requested;
        }
        if (!$to) return null;
        if (!$tx->exists("SELECT 1 FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND u.is_active = 1", [$actor->tenantId, $to])) {
            throw Errors::validation([['path' => 'body.assignedTo', 'message' => 'Assignee must be an active member of this workspace']]);
        }
        return $to;
    }

    private static function assertLinks(Tx $tx, Actor $actor, ?string $leadId, ?string $contactId): void
    {
        if ($leadId) {
            $params = [$actor->tenantId, $leadId];
            $vis = Visibility::lead($actor, 'l', $params);
            $ok = $vis !== false && $tx->exists("SELECT 1 FROM leads l WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $params);
            if (!$ok) throw Errors::validation([['path' => 'body.leadId', 'message' => 'Lead not found']]);
        }
        if ($contactId && !$tx->exists('SELECT 1 FROM contacts WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL', [$actor->tenantId, $contactId])) {
            throw Errors::validation([['path' => 'body.contactId', 'message' => 'Contact not found']]);
        }
    }

    private static function notifyAssignment(Tx $tx, Actor $actor, array $task): void
    {
        if (!$task['assignedTo'] || $task['assignedTo'] === $actor->userId) return;
        Notify::send($tx, [
            'userId' => $task['assignedTo'], 'title' => 'New task for you', 'message' => $task['title'], 'type' => 'task_assigned',
            'link' => $task['leadId'] ? '/leads/' . $task['leadId'] : '/tasks', 'entityType' => 'task', 'entityId' => $task['id'], 'dedupeKey' => 'task-assigned:' . $task['id'] . ':' . $task['assignedTo'],
        ]);
    }

    private static function logLeadActivity(Tx $tx, Actor $actor, string $leadId, string $type, string $description, array $metadata): void
    {
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $actor->userId, 'type' => $type, 'description' => $description, 'metadata' => Json::enc($metadata), 'created_at' => new Raw('NOW(3)')]);
    }

    /** A lead's "next follow-up" is derived: the soonest due date among its open tasks (null when there is none). */
    public static function syncLeadFollowup(Tx $tx, string $tenantId, string $leadId): void
    {
        $tx->run(
            "UPDATE leads l SET l.next_followup_at = (SELECT MIN(t.due_date) FROM tasks t WHERE t.lead_id = l.id AND t.tenant_id = l.tenant_id AND t.deleted_at IS NULL AND t.status IN ('pending','in_progress') AND t.due_date IS NOT NULL)
              WHERE l.id = ? AND l.tenant_id = ?",
            [$leadId, $tenantId]
        );
    }

    /**
     * Lead edit form "next follow-up": kept as one open `follow_up` task so there is a single source of truth (the date shows up in the task list,
     * gets reminders, and drives the lead's follow-up filter).
     */
    public static function setLeadFollowup(Tx $tx, Actor $actor, string $leadId, ?\DateTimeInterface $due): void
    {
        $open = $tx->one("SELECT id FROM tasks WHERE tenant_id = ? AND lead_id = ? AND kind = 'follow_up' AND deleted_at IS NULL AND status IN ('pending','in_progress') ORDER BY created_at LIMIT 1 FOR UPDATE", [$actor->tenantId, $leadId]);
        if (!$due) {
            $tx->run("UPDATE tasks SET status = 'cancelled', version = version + 1 WHERE tenant_id = ? AND lead_id = ? AND kind = 'follow_up' AND deleted_at IS NULL AND status IN ('pending','in_progress')", [$actor->tenantId, $leadId]);
        } elseif ($open) {
            $tx->run('UPDATE tasks SET due_date = ?, remind_at = CASE WHEN remind_before_minutes IS NULL THEN NULL ELSE DATE_SUB(?, INTERVAL remind_before_minutes MINUTE) END, reminder_sent_at = NULL, overdue_notified_at = NULL, version = version + 1 WHERE id = ? AND tenant_id = ?', [$due, $due, $open['id'], $actor->tenantId]);
        } else {
            $lead = $tx->one('SELECT l.owner_id, c.display_name FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ?', [$leadId, $actor->tenantId]);
            $tx->insert('tasks', [
                'tenant_id' => $actor->tenantId, 'title' => mb_substr('Follow up with ' . ($lead['display_name'] ?? 'lead'), 0, 200), 'kind' => 'follow_up', 'priority' => 'medium', 'due_date' => $due,
                'remind_before_minutes' => 15, 'remind_at' => \DateTimeImmutable::createFromInterface($due)->modify('-15 minutes'), 'assigned_to' => $lead['owner_id'] ?? $actor->userId, 'lead_id' => $leadId, 'created_by' => $actor->userId,
            ]);
        }
        self::syncLeadFollowup($tx, $actor->tenantId, $leadId);
    }
}
