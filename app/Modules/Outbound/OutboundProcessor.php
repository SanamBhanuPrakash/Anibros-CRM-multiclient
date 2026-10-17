<?php
declare(strict_types=1);

namespace App\Modules\Outbound;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\Ids;
use App\Core\JobContext;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\PermanentJobError;
use App\Core\Time;
use App\Core\Tx;

final class OutboundProcessor
{
    private const CONSUMER = 'outbound';

    /** Cursor consumer fans domain events out to durable, idempotent deliveries. */
    public static function dispatchEvents(int $limit = 200): int
    {
        $limit = max(1, min(500, $limit));
        return Db::system()->tx(static function (Tx $tx) use ($limit): int {
            $tx->insertIgnore('event_consumers', ['name' => self::CONSUMER, 'last_event_id' => 0]);
            $cursor = $tx->one('SELECT last_event_id FROM event_consumers WHERE name = ? FOR UPDATE', [self::CONSUMER]);
            $after = (int) ($cursor['last_event_id'] ?? 0);
            $events = $tx->all('SELECT id, tenant_id, type, occurred_at FROM domain_events WHERE id > ? ORDER BY id ASC LIMIT ?', [$after, $limit]);
            if (!$events) return 0;
            foreach ($events as $event) {
                if (!isset(Catalog::EVENTS[$event['type']])) continue;
                $endpoints = $tx->all("SELECT id, events FROM outbound_endpoints WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND created_at <= ?", [$event['tenant_id'], $event['occurred_at']]);
                foreach ($endpoints as $endpoint) {
                    if (!in_array($event['type'], Json::dec($endpoint['events'], []), true)) continue;
                    $deliveryId = Ids::uuid();
                    $created = $tx->insertIgnore('outbound_deliveries', [
                        'id' => $deliveryId, 'tenant_id' => $event['tenant_id'], 'endpoint_id' => $endpoint['id'],
                        'event_id' => (int) $event['id'], 'event_type' => $event['type'],
                    ]);
                    if (!$created) continue;
                    Jobs::enqueue($tx, [
                        'type' => 'webhook.deliver', 'tenantId' => $event['tenant_id'], 'payload' => ['deliveryId' => $deliveryId],
                        'dedupeKey' => 'wh-del:' . $deliveryId, 'maxAttempts' => Catalog::MAX_ATTEMPTS,
                    ]);
                }
            }
            $last = $events[count($events) - 1];
            $tx->run('UPDATE event_consumers SET last_event_id = ? WHERE name = ?', [(int) $last['id'], self::CONSUMER]);
            return count($events);
        }, ['retries' => 3]);
    }

    public static function handle(array $job, JobContext $ctx): array
    {
        $tenantId = (string) ($job['tenantId'] ?? '');
        $deliveryId = (string) ($job['payload']['deliveryId'] ?? '');
        if (!Ids::isUuid($tenantId) || !Ids::isUuid($deliveryId)) throw new PermanentJobError('Malformed webhook delivery job');
        $prepared = $ctx->withTenant(static function (Tx $tx) use ($tenantId, $deliveryId): array {
            $delivery = $tx->one('SELECT id, endpoint_id, event_id, event_type, status, is_test FROM outbound_deliveries WHERE id = ? AND tenant_id = ? FOR UPDATE', [$deliveryId, $tenantId]);
            if (!$delivery) throw new PermanentJobError('Webhook delivery no longer exists');
            if (in_array($delivery['status'], ['delivered', 'skipped'], true)) return ['skip' => $delivery['status']];
            $endpoint = $tx->one('SELECT id, name, url, secret_enc, status FROM outbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$delivery['endpoint_id'], $tenantId]);
            if (!$endpoint || $endpoint['status'] !== 'active') {
                $tx->update('outbound_deliveries', ['status' => 'skipped', 'error' => 'The webhook was paused, disabled or deleted before this message was sent.'], 'id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
                return ['skip' => 'endpoint inactive'];
            }
            $event = $delivery['event_id'] === null ? null : $tx->one('SELECT id, type, aggregate_type, aggregate_id, payload, occurred_at FROM domain_events WHERE id = ? AND tenant_id = ?', [$delivery['event_id'], $tenantId]);
            if (!$event) {
                $tx->update('outbound_deliveries', ['status' => 'skipped', 'error' => 'The source event is no longer available.'], 'id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
                return ['skip' => 'event unavailable'];
            }
            $data = self::describe($tx, $tenantId, $event);
            $workspace = $tx->one('SELECT id, name FROM tenants WHERE id = ?', [$tenantId]);
            $payload = [
                'id' => $deliveryId, 'event' => $event['type'], 'occurredAt' => Time::iso($event['occurred_at']),
                'workspace' => ['id' => $workspace['id'] ?? $tenantId, 'name' => $workspace['name'] ?? null],
                'change' => Json::obj(Json::dec($event['payload'], [])), 'data' => $data,
            ];
            $tx->update('outbound_deliveries', ['attempts' => new \App\Core\Raw('attempts + 1')], 'id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
            return ['endpoint' => $endpoint, 'event' => $event['type'], 'body' => Json::enc($payload)];
        });
        if (isset($prepared['skip'])) return ['skipped' => $prepared['skip']];
        $result = self::send($prepared['endpoint'], $deliveryId, $prepared['event'], $prepared['body']);
        $terminal = $result['gone'] || (int) $job['attempts'] >= (int) $job['maxAttempts'];
        $ctx->withTenant(static function (Tx $tx) use ($tenantId, $deliveryId, $prepared, $result, $terminal): void {
            $tx->update('outbound_deliveries', [
                'status' => $result['ok'] ? 'delivered' : ($terminal ? 'dead' : 'failed'),
                'response_status' => $result['status'], 'response_ms' => $result['ms'],
                'error' => $result['error'], 'delivered_at' => $result['ok'] ? Time::now() : null,
            ], 'id = ? AND tenant_id = ?', [$deliveryId, $tenantId]);
            self::recordOutcome($tx, $tenantId, $prepared['endpoint']['id'], $result, true);
        });
        if ($result['ok']) return ['delivered' => true, 'status' => $result['status']];
        if ($terminal) return ['dead' => true, 'error' => $result['error']];
        throw new \RuntimeException($result['error'] ?? 'Webhook delivery failed');
    }

    /** @return array{ok:bool,status:?int,ms:?int,error:?string,gone:bool} */
    public static function send(array $endpoint, string $deliveryId, string $event, string $body): array
    {
        $secret = Crypto::decrypt((string) $endpoint['secret_enc'], Catalog::aad((string) $endpoint['id']));
        try {
            $timestamp = (string) time();
            $response = SafeHttp::postJson((string) $endpoint['url'], $body, [
                'User-Agent: Anibros-CRM-Webhooks/1',
                'X-Anibros-Event: ' . $event,
                'X-Anibros-Delivery: ' . $deliveryId,
                'X-Anibros-Signature: ' . self::signature($secret, $body, $timestamp),
            ]);
            $ok = $response['status'] >= 200 && $response['status'] < 300;
            $error = $ok ? null : ($response['status'] >= 300 && $response['status'] < 400
                ? 'The address answered with a redirect (' . $response['status'] . '); redirects are not followed. Use the final address.'
                : 'The other side answered ' . $response['status'] . ($response['body'] !== '' ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $response['body']), 0, 160) : ''));
            return ['ok' => $ok, 'status' => $response['status'], 'ms' => $response['ms'], 'error' => $error, 'gone' => $response['status'] === 410];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'ms' => null, 'error' => mb_substr($e->getMessage(), 0, 300), 'gone' => false];
        }
    }

    public static function signature(string $secret, string $body, ?string $timestamp = null): string
    {
        $timestamp ??= (string) time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function recordOutcome(Tx $tx, string $tenantId, string $endpointId, array $result, bool $countFailure): void
    {
        if ($result['ok']) {
            $tx->run('UPDATE outbound_endpoints SET consecutive_failures = 0, last_delivery_at = NOW(3), last_status = ?, last_error = NULL WHERE id = ? AND tenant_id = ?', [$result['status'], $endpointId, $tenantId]);
            return;
        }
        if (!$countFailure) return;
        $threshold = Catalog::DISABLE_AFTER_FAILURES;
        $reason = $result['gone'] ? 'The receiver said this address is gone (410).' : 'Switched off after ' . $threshold . ' failed attempts in a row. Fix the address, then turn it back on.';
        $tx->run(
            "UPDATE outbound_endpoints SET consecutive_failures = consecutive_failures + 1, last_delivery_at = NOW(3), last_status = ?, last_error = ?,
                    disabled_reason = CASE WHEN status = 'active' AND (? OR consecutive_failures >= ?) THEN ? ELSE disabled_reason END,
                    status = CASE WHEN status = 'active' AND (? OR consecutive_failures >= ?) THEN 'disabled' ELSE status END
              WHERE id = ? AND tenant_id = ?",
            [$result['status'], $result['error'], $result['gone'] ? 1 : 0, $threshold, $reason, $result['gone'] ? 1 : 0, $threshold, $endpointId, $tenantId]
        );
    }

    private static function describe(Tx $tx, string $tenantId, array $event): array
    {
        $type = $event['aggregate_type'];
        $id = $event['aggregate_id'];
        if ($type === 'lead') {
            $r = $tx->one('SELECT l.id, c.display_name AS name, c.email, c.phone, l.source, l.campaign, l.status, s.name AS stage, u.name AS ownerName, l.tags, l.estimated_value AS estimatedValue, l.currency, l.custom_fields AS customFields, l.created_at AS createdAt FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id LEFT JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id LEFT JOIN users u ON u.id = l.owner_id WHERE l.id = ? AND l.tenant_id = ?', [$id, $tenantId]);
            if ($r) {
                $r['tags'] = Json::dec($r['tags'], []);
                $r['customFields'] = Json::obj(Json::dec($r['customFields'], []));
                $r['estimatedValue'] = $r['estimatedValue'] === null ? null : (float) $r['estimatedValue'];
                $r['createdAt'] = Time::iso($r['createdAt']);
            }
            return ['lead' => $r ?? ['id' => $id, 'deleted' => true]];
        }
        if ($type === 'activity') {
            $r = $tx->one('SELECT id, lead_id AS leadId, type, direction, outcome, duration_minutes AS durationMinutes, LEFT(description, 500) AS note, created_at AS createdAt FROM activities WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            if ($r) $r['createdAt'] = Time::iso($r['createdAt']);
            return ['activity' => $r ?? ['id' => $id]];
        }
        if ($type === 'task') {
            $r = $tx->one('SELECT id, lead_id AS leadId, title, status, priority, due_date AS dueDate, completed_at AS completedAt FROM tasks WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            if ($r) {
                $r['dueDate'] = Time::iso($r['dueDate']);
                $r['completedAt'] = Time::iso($r['completedAt']);
            }
            return ['task' => $r ?? ['id' => $id]];
        }
        if ($type === 'contact') {
            $r = $tx->one('SELECT id, display_name AS name, email, phone FROM contacts WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            return ['contact' => $r ?? ['id' => $id]];
        }
        return [];
    }
}
