<?php
declare(strict_types=1);

namespace App\Modules\Automation;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Events;
use App\Core\Ids;
use App\Core\JobContext;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\Notify;
use App\Core\PermanentJobError;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Leads\LeadsService;
use App\Modules\Tasks\TasksService;

/**
 * Worker side of the fixed automation templates.
 *
 * A cursor over the transactional domain-event outbox enqueues one durable dispatch job per
 * event.  The execution row, assignment, task and notification are all written in one
 * tenant transaction, so a retry can never leave a partially-applied follow-up behind.
 */
final class AutomationEngine
{
    private const CONSUMER = 'automation';
    private const TEMPLATE = 'new_lead_follow_up';

    /** Consume a bounded, ordered event batch. Safe when two cron invocations overlap. */
    public static function dispatchEvents(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        return Db::system()->tx(static function (Tx $tx) use ($limit): int {
            $tx->insertIgnore('event_consumers', ['name' => self::CONSUMER, 'last_event_id' => 0]);
            $cursor = $tx->one('SELECT last_event_id FROM event_consumers WHERE name = ? FOR UPDATE', [self::CONSUMER]);
            $after = (int) ($cursor['last_event_id'] ?? 0);
            $events = $tx->all('SELECT id, tenant_id, type FROM domain_events WHERE id > ? ORDER BY id ASC LIMIT ?', [$after, $limit]);
            if (!$events) return 0;
            foreach ($events as $event) {
                if ($event['type'] !== 'lead.created') continue;
                Jobs::enqueue($tx, [
                    'type' => 'automation.dispatch',
                    'tenantId' => $event['tenant_id'],
                    'payload' => ['eventId' => (string) $event['id']],
                    'dedupeKey' => 'event:' . $event['id'],
                    'maxAttempts' => 6,
                ]);
            }
            $last = $events[count($events) - 1];
            $tx->run('UPDATE event_consumers SET last_event_id = ? WHERE name = ?', [(int) $last['id'], self::CONSUMER]);
            return count($events);
        }, ['retries' => 3]);
    }

    /** @return array<string,mixed> */
    public static function dispatch(array $job, JobContext $ctx): array
    {
        $eventId = (string) ($job['payload']['eventId'] ?? '');
        if (!preg_match('/^[1-9][0-9]*$/', $eventId)) throw new PermanentJobError('automation.dispatch needs an event id');
        $event = $ctx->withTenant(static fn(Tx $tx) => $tx->one(
            'SELECT id, type, aggregate_id, payload, occurred_at FROM domain_events WHERE id = ?', [(int) $eventId]
        ));
        if (!$event || $event['type'] !== 'lead.created') return ['ignored' => 'event is unavailable or unsupported'];

        $rules = $ctx->withTenant(static fn(Tx $tx) => $tx->all(
            "SELECT id FROM automation_rules WHERE status IN ('active', 'paused') AND template = ? AND trigger_type = 'lead_created' AND active_since <= ? ORDER BY created_at, id",
            [self::TEMPLATE, $event['occurred_at']]
        ));
        $outcomes = [];
        foreach ($rules as $rule) $outcomes[] = self::runRule($ctx, $job, $rule['id'], $event);
        return ['rules' => count($rules), 'outcomes' => $outcomes];
    }

    /** @return array<string,mixed> */
    public static function retry(array $job, JobContext $ctx): array
    {
        $id = (string) ($job['payload']['executionId'] ?? '');
        if (!Ids::isUuid($id)) throw new PermanentJobError('automation.retry needs an execution id');
        $execution = $ctx->withTenant(static fn(Tx $tx) => $tx->one(
            'SELECT id, rule_id, trigger_event_id, trigger_entity_id, status FROM automation_executions WHERE id = ? FOR UPDATE', [$id]
        ));
        if (!$execution) throw new PermanentJobError('Automation execution no longer exists');
        if (!in_array($execution['status'], ['pending', 'failed'], true)) return ['ignored' => 'execution is ' . $execution['status']];
        $event = $execution['trigger_event_id'] ? $ctx->withTenant(static fn(Tx $tx) => $tx->one(
            'SELECT id, type, aggregate_id, payload, occurred_at FROM domain_events WHERE id = ?', [(int) $execution['trigger_event_id']]
        )) : null;
        $event ??= ['id' => (string) ($execution['trigger_event_id'] ?? 0), 'type' => 'lead.created', 'aggregate_id' => $execution['trigger_entity_id'], 'payload' => '{}', 'occurred_at' => Time::now()];
        return self::runRule($ctx, $job, $execution['rule_id'], $event);
    }

    /** @return array<string,mixed> */
    private static function runRule(JobContext $ctx, array $job, string $ruleId, array $event): array
    {
        try {
            return $ctx->withTenant(static fn(Tx $tx) => self::execute($tx, $ruleId, $event));
        } catch (\Throwable $e) {
            // Keep truthful, tenant-scoped history even when the queue will retry the job.
            try {
                $ctx->withTenant(static fn(Tx $tx) => self::recordFailure($tx, $ruleId, $event, $e->getMessage(), (int) $job['attempts'], (int) $job['maxAttempts']));
            } catch (\Throwable) {
                // The original failure is the useful one for the queue; a later retry will attempt to record it again.
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private static function execute(Tx $tx, string $ruleId, array $event): array
    {
        $rule = $tx->one('SELECT id, status, version, template FROM automation_rules WHERE id = ? FOR UPDATE', [$ruleId]);
        if (!$rule || $rule['template'] !== self::TEMPLATE) return ['outcome' => 'ignored'];

        $existing = $tx->one('SELECT id, status, attempts FROM automation_executions WHERE rule_id = ? AND trigger_event_id = ? FOR UPDATE', [$ruleId, (int) $event['id']]);
        if ($existing && in_array($existing['status'], ['running', 'completed', 'skipped'], true)) return ['outcome' => 'duplicate', 'executionId' => $existing['id']];
        if ($existing) {
            $executionId = $existing['id'];
            $tx->run("UPDATE automation_executions SET status = 'running', attempts = attempts + 1, started_at = NOW(3), completed_at = NULL, error_message = NULL, rule_version = ? WHERE id = ?", [(int) $rule['version'], $executionId]);
        } else {
            $executionId = $tx->insert('automation_executions', [
                'tenant_id' => $tx->tenantId, 'rule_id' => $ruleId, 'rule_version' => (int) $rule['version'], 'trigger_entity_type' => 'lead',
                'trigger_entity_id' => $event['aggregate_id'], 'trigger_event_id' => (int) $event['id'], 'status' => 'running', 'attempts' => 1,
                'execution_log' => '[]', 'started_at' => new Raw('NOW(3)'),
            ]);
        }

        if ($rule['status'] !== 'active') return self::finish($tx, $executionId, $ruleId, $event['aggregate_id'], 'skipped', 'The automation was paused before this run started.', []);
        $lead = $tx->one(
            'SELECT l.id, l.owner_id, l.created_by, l.created_at, l.source, l.deleted_at, l.contact_id, c.display_name AS contact_name FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ? FOR UPDATE',
            [$event['aggregate_id'], $tx->tenantId]
        );
        if (!$lead || $lead['deleted_at']) return self::finish($tx, $executionId, $ruleId, $event['aggregate_id'], 'skipped', 'The lead was archived or removed before this run started.', []);

        $actor = Actor::system($tx->tenantId);
        $steps = [];
        $payload = Json::obj(Json::dec($event['payload'], []));
        $ownerId = $lead['owner_id'];
        if ($ownerId) self::step($steps, 'owner', 'kept', 'Kept the existing lead owner.');
        elseif (!empty($payload['ownerRequested'])) self::step($steps, 'owner', 'kept', 'The lead was intentionally created without an owner.');
        else {
            $settings = LeadsService::settings($tx, $tx->tenantId);
            $ownerId = LeadsService::routedOwner($tx, $tx->tenantId, $settings, $lead['source'])
                ?? ($settings['assignmentMode'] === 'unassigned' ? null : LeadsService::pickBalancedOwner($tx, $tx->tenantId));
            if ($ownerId) {
                LeadsService::assignInTx($tx, $actor, ['id' => $lead['id'], 'owner_id' => null], $ownerId, 'automation');
                self::step($steps, 'owner', 'done', 'Assigned the lead according to the workspace routing rule.');
            } else self::step($steps, 'owner', 'skipped', 'No active teammate was available, so the lead remains unassigned.');
        }

        $settings ??= LeadsService::settings($tx, $tx->tenantId);
        $minutes = max(1, (int) ($settings['firstResponseMinutes'] ?? 30));
        $created = Time::parse($lead['created_at']) ?? Time::now();
        $due = $created->modify('+' . $minutes . ' minutes');
        if ($due < Time::now()) $due = Time::now();
        $person = trim((string) ($lead['contact_name'] ?? '')) ?: 'the new lead';
        $task = TasksService::createInTx($tx, $actor, [
            'title' => mb_substr('Call ' . $person . ' (new lead)', 0, 200), 'kind' => 'call', 'priority' => 'high', 'dueDate' => $due,
            'remindBeforeMinutes' => $minutes >= 10 ? 5 : null, 'assignedTo' => $ownerId, 'leadId' => $lead['id'], 'contactId' => $lead['contact_id'],
            'description' => 'Created automatically for a new lead from ' . str_replace('_', ' ', $lead['source']) . '.',
        ], ['dedupeKey' => 'automation:' . $ruleId . ':' . $event['id'] . ':task', 'silent' => true]);
        self::step($steps, 'task', $task['created'] ? 'done' : 'kept', ($task['created'] ? 'Created' : 'Found') . ' the first-response call task.');

        if (!$ownerId) self::step($steps, 'notify', 'skipped', 'There is no owner to notify.');
        elseif ($ownerId === $lead['created_by']) self::step($steps, 'notify', 'skipped', 'The owner created this lead, so no notification was needed.');
        else {
            $already = $tx->exists("SELECT 1 FROM notifications WHERE tenant_id = ? AND user_id = ? AND entity_type = 'lead' AND entity_id = ? AND type = 'lead_assigned'", [$tx->tenantId, $ownerId, $lead['id']]);
            if ($already) self::step($steps, 'notify', 'skipped', 'The owner was already notified when the lead arrived.');
            else {
                $notice = Notify::send($tx, [
                    'userId' => $ownerId, 'title' => 'New lead: ' . $person,
                    'message' => 'Call within ' . $minutes . ' minutes (' . str_replace('_', ' ', $lead['source']) . ').', 'type' => 'lead_assigned',
                    'link' => '/leads/' . $lead['id'], 'entityType' => 'lead', 'entityId' => $lead['id'],
                    'dedupeKey' => 'automation:' . $ruleId . ':' . $event['id'] . ':notify',
                ]);
                self::step($steps, 'notify', $notice ? 'done' : 'kept', $notice ? 'Notified the lead owner.' : 'The owner had already been notified.');
            }
        }
        return self::finish($tx, $executionId, $ruleId, $lead['id'], 'completed', implode(' · ', array_column($steps, 'detail')), $steps);
    }

    /** @param array<int,array<string,mixed>> $steps */
    private static function step(array &$steps, string $step, string $status, string $detail): void
    {
        $steps[] = ['step' => $step, 'status' => $status, 'detail' => $detail, 'at' => Time::iso(Time::now())];
    }

    /** @param array<int,array<string,mixed>> $steps @return array<string,mixed> */
    private static function finish(Tx $tx, string $id, string $ruleId, string $leadId, string $status, string $summary, array $steps): array
    {
        $tx->run('UPDATE automation_executions SET status = ?, summary = ?, execution_log = ?, actions_run = ?, completed_at = NOW(3), error_message = NULL WHERE id = ?', [$status, mb_substr($summary, 0, 500), Json::enc($steps), count(array_filter($steps, static fn($s) => $s['status'] === 'done')), $id]);
        Audit::log($tx, 'automation.' . ($status === 'completed' ? 'run' : 'skip'), 'automation_execution', $id, null, ['ruleId' => $ruleId, 'leadId' => $leadId, 'summary' => $summary]);
        Events::emit($tx, 'automation.updated', $id, ['ruleId' => $ruleId, 'status' => $status]);
        return ['outcome' => $status, 'executionId' => $id, 'summary' => $summary];
    }

    private static function recordFailure(Tx $tx, string $ruleId, array $event, string $error, int $attempt, int $maxAttempts): void
    {
        $rule = $tx->one('SELECT version FROM automation_rules WHERE id = ?', [$ruleId]);
        if (!$rule) return;
        $existing = $tx->one('SELECT id, status FROM automation_executions WHERE rule_id = ? AND trigger_event_id = ? FOR UPDATE', [$ruleId, (int) $event['id']]);
        $final = $attempt >= $maxAttempts;
        if ($existing && in_array($existing['status'], ['completed', 'skipped'], true)) return;
        if ($existing) $tx->run('UPDATE automation_executions SET status = ?, attempts = GREATEST(attempts, ?), error_message = ?, completed_at = CASE WHEN ? = 1 THEN NOW(3) ELSE NULL END WHERE id = ?', [$final ? 'failed' : 'pending', max(1, $attempt), mb_substr($error, 0, 2000), $final ? 1 : 0, $existing['id']]);
        else $tx->insert('automation_executions', ['tenant_id' => $tx->tenantId, 'rule_id' => $ruleId, 'rule_version' => (int) $rule['version'], 'trigger_entity_type' => 'lead', 'trigger_entity_id' => $event['aggregate_id'], 'trigger_event_id' => (int) $event['id'], 'status' => $final ? 'failed' : 'pending', 'attempts' => max(1, $attempt), 'error_message' => mb_substr($error, 0, 2000), 'execution_log' => '[]', 'started_at' => new Raw('NOW(3)'), 'completed_at' => $final ? new Raw('NOW(3)') : null]);
        Events::emit($tx, 'automation.updated', $ruleId, ['ruleId' => $ruleId, 'status' => $final ? 'failed' : 'pending']);
    }
}
