<?php
declare(strict_types=1);

namespace App\Modules\Outbound;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\Json;
use App\Core\Jobs;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Time;
use App\Core\Tx;

final class OutboundService
{
    public static function events(Actor $actor): array
    {
        $actor->assertCan('integrations:read');
        $out = [];
        foreach (Catalog::EVENTS as $event => $description) $out[] = compact('event', 'description');
        return $out;
    }

    public static function list(Actor $actor): array
    {
        $actor->assertCan('integrations:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor): array {
            $rows = $tx->all('SELECT id, name, url, events, status, disabled_reason, secret_hint, last_delivery_at, last_status, last_error, created_at FROM outbound_endpoints WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY created_at DESC', [$actor->tenantId]);
            $stats = $tx->all("SELECT endpoint_id, status, COUNT(*) AS n FROM outbound_deliveries WHERE tenant_id = ? AND created_at > DATE_SUB(NOW(3), INTERVAL 7 DAY) GROUP BY endpoint_id, status", [$actor->tenantId]);
            $byEndpoint = [];
            foreach ($stats as $s) $byEndpoint[$s['endpoint_id']][$s['status']] = (int) $s['n'];
            return array_map(static fn($r) => self::dto($r, $byEndpoint[$r['id']] ?? []), $rows);
        });
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('integrations:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => self::fetch($tx, $actor->tenantId, $id));
    }

    public static function create(Actor $actor, array $input): array
    {
        $actor->assertCan('integrations:manage');
        self::validateUrl($input['url']);
        $id = Ids::uuid();
        $secret = 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $events = array_values(array_unique($input['events']));
        $webhook = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $secret, $events, $input): array {
            $count = (int) $tx->val('SELECT COUNT(*) FROM outbound_endpoints WHERE tenant_id = ? AND deleted_at IS NULL', [$actor->tenantId]);
            if ($count >= Catalog::MAX_ENDPOINTS) throw Errors::conflict('A workspace can have at most ' . Catalog::MAX_ENDPOINTS . ' webhooks');
            $tx->insert('outbound_endpoints', [
                'id' => $id, 'tenant_id' => $actor->tenantId, 'name' => trim($input['name']), 'url' => trim($input['url']),
                'secret_enc' => Crypto::encrypt($secret, Catalog::aad($id)), 'secret_hint' => substr($secret, -4),
                'events' => Json::enc($events), 'created_by' => $actor->userId,
            ]);
            Audit::log($tx, 'webhook.create', 'webhook', $id, null, ['name' => trim($input['name']), 'url' => self::origin($input['url']), 'events' => $events]);
            Events::emit($tx, 'integration.updated', $id, ['change' => 'webhook_created']);
            return self::fetch($tx, $actor->tenantId, $id);
        });
        return ['webhook' => $webhook, 'secret' => $secret];
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $actor->assertCan('integrations:manage');
        if (isset($patch['url'])) self::validateUrl($patch['url']);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch): array {
            $cur = $tx->one('SELECT status FROM outbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Webhook');
            $set = [];
            foreach (['name', 'url', 'status'] as $key) if (array_key_exists($key, $patch)) $set[$key] = $key === 'name' || $key === 'url' ? trim($patch[$key]) : $patch[$key];
            if (array_key_exists('events', $patch)) $set['events'] = Json::enc(array_values(array_unique($patch['events'])));
            if (($patch['status'] ?? null) === 'active') {
                $set['consecutive_failures'] = 0;
                $set['disabled_reason'] = null;
            }
            if ($set) $tx->update('outbound_endpoints', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'webhook.update', 'webhook', $id, null, ['fields' => array_keys($patch)]);
            Events::emit($tx, 'integration.updated', $id, ['change' => 'webhook_updated']);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    public static function rotateSecret(Actor $actor, string $id): array
    {
        $actor->assertCan('integrations:manage');
        $secret = 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $webhook = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $secret): array {
            if (!$tx->update('outbound_endpoints', ['secret_enc' => Crypto::encrypt($secret, Catalog::aad($id)), 'secret_hint' => substr($secret, -4)], 'id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId])) throw Errors::notFound('Webhook');
            Audit::log($tx, 'webhook.rotate_secret', 'webhook', $id);
            return self::fetch($tx, $actor->tenantId, $id);
        });
        return ['webhook' => $webhook, 'secret' => $secret];
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('integrations:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id): void {
            $row = $tx->one('SELECT name FROM outbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Webhook');
            $tx->update('outbound_endpoints', ['deleted_at' => Time::now(), 'status' => 'paused'], 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'webhook.delete', 'webhook', $id, ['name' => $row['name']]);
        });
    }

    public static function deliveries(Actor $actor, array $query): array
    {
        $actor->assertCan('integrations:manage');
        $signature = 'outbound-deliveries';
        $cursor = Pagination::decode($query['cursor'] ?? null, $signature);
        $rows = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $query, $cursor): array {
            $where = ['d.tenant_id = ?'];
            $params = [$actor->tenantId];
            if (!empty($query['endpointId'])) { $where[] = 'd.endpoint_id = ?'; $params[] = $query['endpointId']; }
            if (!empty($query['status'])) { $where[] = 'd.status = ?'; $params[] = $query['status']; }
            $pred = Pagination::predicate($cursor, $params, 'd.created_at', 'd.id', 'desc', 'ts');
            if ($pred) $where[] = $pred;
            $params[] = $query['limit'] + 1;
            return $tx->all('SELECT d.id, d.endpoint_id, e.name AS endpoint_name, d.event_type, d.is_test, d.status, d.attempts, d.response_status, d.response_ms, d.error, d.created_at, d.created_at AS _k, d.delivered_at FROM outbound_deliveries d JOIN outbound_endpoints e ON e.id = d.endpoint_id AND e.tenant_id = d.tenant_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ?', $params);
        });
        $page = Pagination::page($rows, $query['limit'], $signature, static fn($r) => $r['_k']);
        $items = array_map(static fn($r) => [
            'id' => $r['id'], 'endpointId' => $r['endpoint_id'], 'endpointName' => $r['endpoint_name'], 'event' => $r['event_type'],
            'isTest' => (bool) $r['is_test'], 'status' => $r['status'], 'attempts' => (int) $r['attempts'],
            'responseStatus' => $r['response_status'] === null ? null : (int) $r['response_status'],
            'responseMs' => $r['response_ms'] === null ? null : (int) $r['response_ms'], 'error' => $r['error'],
            'createdAt' => Time::iso($r['created_at']) ?? (string) $r['created_at'], 'deliveredAt' => Time::iso($r['delivered_at']),
        ], $page['items']);
        return ['items' => $items, 'page' => $page['page']];
    }

    public static function retry(Actor $actor, string $deliveryId): void
    {
        $actor->assertCan('integrations:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $deliveryId): void {
            $n = $tx->update('outbound_deliveries', ['status' => 'pending', 'attempts' => 0, 'error' => null, 'response_status' => null, 'response_ms' => null], "id = ? AND tenant_id = ? AND status IN ('failed','dead') AND is_test = 0", [$deliveryId, $actor->tenantId]);
            if (!$n) throw Errors::conflict('Only a failed, non-test webhook delivery can be retried');
            Jobs::enqueue($tx, ['type' => 'webhook.deliver', 'payload' => ['deliveryId' => $deliveryId], 'dedupeKey' => 'wh-retry:' . $deliveryId . ':' . Ids::hex(6), 'maxAttempts' => Catalog::MAX_ATTEMPTS]);
        });
    }

    public static function sendTest(Actor $actor, string $id): array
    {
        $actor->assertCan('integrations:manage');
        $endpoint = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->one('SELECT id, name, url, secret_enc, status FROM outbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId]));
        if (!$endpoint) throw Errors::notFound('Webhook');
        $deliveryId = Ids::uuid();
        $body = Json::enc(['id' => $deliveryId, 'event' => 'ping', 'occurredAt' => Time::iso(Time::now()), 'workspace' => ['id' => $actor->tenantId], 'change' => new \stdClass(), 'data' => ['message' => 'This is a test message from Anibros CRM. If you can read it and the signature checks out, you are all set.']]);
        $result = OutboundProcessor::send($endpoint, $deliveryId, 'ping', $body);
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $deliveryId, $result): void {
            $tx->insert('outbound_deliveries', [
                'id' => $deliveryId, 'tenant_id' => $actor->tenantId, 'endpoint_id' => $id, 'event_type' => 'ping', 'is_test' => 1,
                'status' => $result['ok'] ? 'delivered' : 'failed', 'attempts' => 1, 'response_status' => $result['status'],
                'response_ms' => $result['ms'], 'error' => $result['error'], 'delivered_at' => $result['ok'] ? Time::now() : null,
            ]);
            if ($result['ok']) OutboundProcessor::recordOutcome($tx, $actor->tenantId, $id, $result, false);
        });
        return ['ok' => $result['ok'], 'status' => $result['status'], 'ms' => $result['ms'], 'error' => $result['error']];
    }

    private static function fetch(Tx $tx, string $tenantId, string $id): array
    {
        $row = $tx->one('SELECT id, name, url, events, status, disabled_reason, secret_hint, last_delivery_at, last_status, last_error, created_at FROM outbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $tenantId]);
        if (!$row) throw Errors::notFound('Webhook');
        $stats = $tx->all("SELECT status, COUNT(*) AS n FROM outbound_deliveries WHERE endpoint_id = ? AND tenant_id = ? AND created_at > DATE_SUB(NOW(3), INTERVAL 7 DAY) GROUP BY status", [$id, $tenantId]);
        $counts = [];
        foreach ($stats as $s) $counts[$s['status']] = (int) $s['n'];
        return self::dto($row, $counts);
    }

    private static function dto(array $r, array $stats): array
    {
        return [
            'id' => $r['id'], 'name' => $r['name'], 'url' => $r['url'], 'events' => Json::dec($r['events'], []),
            'status' => $r['status'], 'disabledReason' => $r['disabled_reason'], 'secretHint' => $r['secret_hint'],
            'lastDeliveryAt' => Time::iso($r['last_delivery_at']), 'lastStatus' => $r['last_status'] === null ? null : (int) $r['last_status'],
            'lastError' => $r['last_error'],
            'stats' => ['delivered' => $stats['delivered'] ?? 0, 'failed' => ($stats['failed'] ?? 0) + ($stats['dead'] ?? 0), 'pending' => $stats['pending'] ?? 0],
            'createdAt' => Time::iso($r['created_at']) ?? (string) $r['created_at'],
        ];
    }

    private static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || empty($parts['host']) || !in_array($scheme, ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw Errors::validation([['path' => 'body.url', 'message' => 'Use a valid public webhook URL without embedded credentials.']]);
        }
        if ($scheme !== 'https' && (Config::isProduction() || !Config::get('outbound_allow_private', false))) {
            throw Errors::validation([['path' => 'body.url', 'message' => 'Use an https:// address so webhook data is encrypted.']]);
        }
        $host = trim((string) $parts['host'], '[]');
        $allowPrivate = (bool) Config::get('outbound_allow_private', false) && !Config::isProduction();
        if (!$host || (!$allowPrivate && (in_array(strtolower($host), ['localhost'], true) || str_ends_with(strtolower($host), '.localhost') || str_ends_with(strtolower($host), '.local') || str_ends_with(strtolower($host), '.internal')))) {
            throw Errors::validation([['path' => 'body.url', 'message' => 'That address points to a private network. Use a public address.']]);
        }
        try {
            $ips = SafeHttp::resolve($host);
        } catch (\Throwable) {
            $ips = [];
        }
        if (!$ips) throw Errors::validation([['path' => 'body.url', 'message' => 'We could not resolve that address. Check the domain spelling.']]);
        if (!$allowPrivate) {
            foreach ($ips as $ip) if (!SafeHttp::isPublicIp($ip)) throw Errors::validation([['path' => 'body.url', 'message' => 'That address points to a private or reserved network. Use a public address.']]);
        }
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '');
    }
}
