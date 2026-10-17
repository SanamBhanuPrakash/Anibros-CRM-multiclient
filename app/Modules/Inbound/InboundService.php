<?php
declare(strict_types=1);

namespace App\Modules\Inbound;

use App\Core\Actor;
use App\Core\Audit;
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
use App\Modules\Contacts\Normalize;

final class InboundService
{
    private const AAD_PREFIX = 'inbound:';

    public static function hookUrl(Request $request, string $id): string
    {
        return $request->appUrl() . '/hooks/' . $id;
    }

    /** Narrow system-context lookup for public requests: accepts one endpoint id and exposes no tenant-listing capability. */
    public static function lookupPublic(string $id): ?array
    {
        return Db::system()->read(static function (Tx $tx) use ($id): ?array {
            $row = $tx->one("SELECT id, tenant_id, provider, status, secret_enc, verify_token, access_token_enc, config FROM inbound_endpoints WHERE id = ? AND deleted_at IS NULL", [$id]);
            if (!$row) return null;
            $row['config'] = Json::dec($row['config'], []);
            return $row;
        });
    }

    public static function readSecret(string $id, ?string $encrypted): ?string
    {
        return $encrypted === null || $encrypted === '' ? null : Crypto::decrypt($encrypted, self::AAD_PREFIX . $id);
    }

    public static function list(Actor $actor, Request $request): array
    {
        $actor->assertCan('integrations:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $request): array {
            $rows = $tx->all("SELECT e.id, e.provider, e.name, e.status, e.secret_hint, (e.access_token_enc IS NOT NULL) AS has_access_token, e.verify_token, e.config, e.last_received_at, e.received_count, e.created_at FROM inbound_endpoints e WHERE e.tenant_id = ? AND e.deleted_at IS NULL ORDER BY e.created_at DESC", [$actor->tenantId]);
            $stats = $tx->all("SELECT endpoint_id, status, COUNT(*) AS n FROM inbound_events WHERE tenant_id = ? AND received_at > DATE_SUB(NOW(3), INTERVAL 7 DAY) GROUP BY endpoint_id, status", [$actor->tenantId]);
            $byEndpoint = [];
            foreach ($stats as $s) $byEndpoint[$s['endpoint_id']][$s['status']] = (int) $s['n'];
            return array_map(static fn($row) => self::dto($row, $byEndpoint[$row['id']] ?? [], $request), $rows);
        });
    }

    public static function create(Actor $actor, Request $request, array $input): array
    {
        $actor->assertCan('integrations:manage');
        self::validateInput($input, true);
        if ($input['provider'] === 'meta' && empty($input['appSecret'])) {
            throw Errors::validation([['path' => 'body.appSecret', 'message' => 'Paste your Meta app secret to verify webhook deliveries']]);
        }
        $id = Ids::uuid();
        $provider = $input['provider'];
        $generated = in_array($provider, ['google', 'generic'], true) ? 'ansk_' . Crypto::b64(random_bytes(32)) : null;
        $secret = $provider === 'meta' ? ($input['appSecret'] ?? null) : $generated;
        $config = self::normalizeConfig($input['config'] ?? []);
        $endpoint = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $provider, $input, $secret, $config, $request): array {
            self::validateConfig($tx, $actor->tenantId, $config);
            $tx->insert('inbound_endpoints', [
                'id' => $id, 'tenant_id' => $actor->tenantId, 'provider' => $provider,
                'name' => trim($input['name']), 'secret_enc' => $secret ? Crypto::encrypt($secret, self::AAD_PREFIX . $id) : null,
                'secret_hint' => $secret ? substr($secret, -4) : null,
                'verify_token' => $provider === 'meta' ? Crypto::b64(random_bytes(24)) : null,
                'access_token_enc' => !empty($input['pageAccessToken']) ? Crypto::encrypt((string) $input['pageAccessToken'], self::AAD_PREFIX . $id) : null,
                'config' => Json::enc($config), 'created_by' => $actor->userId,
            ]);
            Audit::log($tx, 'lead_source.create', 'lead_source', $id, null, ['provider' => $provider, 'name' => trim($input['name'])]);
            Events::emit($tx, 'lead_source.updated', $id, ['change' => 'created']);
            return self::fetch($tx, $actor->tenantId, $id, $request);
        });
        return ['endpoint' => $endpoint, 'secret' => $generated];
    }

    public static function update(Actor $actor, Request $request, string $id, array $patch): array
    {
        $actor->assertCan('integrations:manage');
        self::validateInput($patch, false);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $request, $id, $patch): array {
            $cur = $tx->one("SELECT provider FROM inbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE", [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Lead source');
            if (isset($patch['appSecret']) && $cur['provider'] !== 'meta') {
                throw Errors::validation([['path' => 'body.appSecret', 'message' => 'Only Meta sources use an app secret']]);
            }
            $set = [];
            if (array_key_exists('name', $patch)) $set['name'] = trim($patch['name']);
            if (array_key_exists('status', $patch)) $set['status'] = $patch['status'];
            if (array_key_exists('config', $patch)) {
                $config = self::normalizeConfig($patch['config']);
                self::validateConfig($tx, $actor->tenantId, $config);
                $set['config'] = Json::enc($config);
            }
            if (array_key_exists('appSecret', $patch)) {
                $set['secret_enc'] = Crypto::encrypt($patch['appSecret'], self::AAD_PREFIX . $id);
                $set['secret_hint'] = substr($patch['appSecret'], -4);
            }
            if (array_key_exists('pageAccessToken', $patch)) {
                $token = $patch['pageAccessToken'];
                $set['access_token_enc'] = $token ? Crypto::encrypt($token, self::AAD_PREFIX . $id) : null;
            }
            if (!$set) return self::fetch($tx, $actor->tenantId, $id, $request);
            $tx->update('inbound_endpoints', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'lead_source.update', 'lead_source', $id, null, ['fields' => array_keys($patch)]);
            Events::emit($tx, 'lead_source.updated', $id, ['change' => 'updated']);
            return self::fetch($tx, $actor->tenantId, $id, $request);
        });
    }

    public static function rotateSecret(Actor $actor, Request $request, string $id): array
    {
        $actor->assertCan('integrations:manage');
        $secret = 'ansk_' . Crypto::b64(random_bytes(32));
        $endpoint = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $request, $id, $secret): array {
            $provider = $tx->val('SELECT provider FROM inbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$provider) throw Errors::notFound('Lead source');
            if (!in_array($provider, ['google', 'generic'], true)) throw Errors::conflict('This source does not use a generated secret');
            $tx->update('inbound_endpoints', ['secret_enc' => Crypto::encrypt($secret, self::AAD_PREFIX . $id), 'secret_hint' => substr($secret, -4)], 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'lead_source.rotate_secret', 'lead_source', $id);
            Events::emit($tx, 'lead_source.updated', $id, ['change' => 'secret_rotated']);
            return self::fetch($tx, $actor->tenantId, $id, $request);
        });
        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('integrations:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id): void {
            if (!$tx->update('inbound_endpoints', ['deleted_at' => Time::now(), 'status' => 'paused'], 'id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId])) throw Errors::notFound('Lead source');
            Audit::log($tx, 'lead_source.delete', 'lead_source', $id);
            Events::emit($tx, 'lead_source.updated', $id, ['change' => 'deleted']);
        });
    }

    public static function events(Actor $actor, array $query): array
    {
        $actor->assertCan('integrations:manage');
        $signature = 'inbound-events';
        $cursor = Pagination::decode($query['cursor'] ?? null, $signature);
        $rows = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $query, $cursor): array {
            $params = [$actor->tenantId];
            $where = ['ev.tenant_id = ?'];
            if (!empty($query['endpointId'])) { $where[] = 'ev.endpoint_id = ?'; $params[] = $query['endpointId']; }
            if (!empty($query['status'])) { $where[] = 'ev.status = ?'; $params[] = $query['status']; }
            $pred = Pagination::predicate($cursor, $params, 'ev.received_at', 'ev.id', 'desc', 'ts');
            if ($pred) $where[] = $pred;
            $params[] = $query['limit'] + 1;
            return $tx->all('SELECT ev.id, ev.endpoint_id, ep.name AS endpoint_name, ev.provider, ev.status, ev.is_test, ev.lead_id, ev.reason, ev.attempts, ev.received_at, ev.received_at AS _k, ev.processed_at, ev.payload FROM inbound_events ev JOIN inbound_endpoints ep ON ep.id = ev.endpoint_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ev.received_at DESC, ev.id DESC LIMIT ?', $params);
        });
        $page = Pagination::page($rows, $query['limit'], $signature, static fn($r) => $r['_k']);
        return ['items' => array_map(static function ($r): array {
            $payload = Json::dec($r['payload'], []);
            return [
                'id' => $r['id'], 'endpointId' => $r['endpoint_id'], 'endpointName' => $r['endpoint_name'],
                'provider' => $r['provider'], 'status' => $r['status'], 'isTest' => (bool) $r['is_test'],
                'leadId' => $r['lead_id'], 'who' => self::summarize($r['provider'], $payload),
                'reason' => $r['reason'], 'attempts' => (int) $r['attempts'],
                'receivedAt' => $r['received_at'], 'processedAt' => $r['processed_at'],
            ];
        }, $page['items']), 'page' => $page['page']];
    }

    public static function retry(Actor $actor, string $eventId): void
    {
        $actor->assertCan('integrations:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $eventId): void {
            $n = $tx->update('inbound_events', ['status' => 'received', 'reason' => null, 'attempts' => 0, 'processed_at' => null], 'id = ? AND tenant_id = ? AND status IN (?, ?)', [$eventId, $actor->tenantId, 'needs_review', 'failed']);
            if (!$n) throw Errors::conflict('Only deliveries that need review or failed can be retried');
            Jobs::enqueue($tx, ['type' => 'inbound.process', 'payload' => ['eventId' => $eventId], 'dedupeKey' => 'inbound:' . $eventId . ':retry:' . Ids::hex(8), 'maxAttempts' => 6, 'priority' => 5]);
            Audit::log($tx, 'inbound_event.retry', 'inbound_event', $eventId);
            Events::emit($tx, 'inbound.updated', $eventId, ['status' => 'received']);
        });
    }

    public static function ignore(Actor $actor, string $eventId): void
    {
        $actor->assertCan('integrations:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $eventId): void {
            $n = $tx->update('inbound_events', ['status' => 'ignored', 'processed_at' => Time::now()], 'id = ? AND tenant_id = ? AND status IN (?, ?)', [$eventId, $actor->tenantId, 'needs_review', 'failed']);
            if (!$n) throw Errors::conflict('Only deliveries that need review or failed can be ignored');
            Audit::log($tx, 'inbound_event.ignore', 'inbound_event', $eventId);
            Events::emit($tx, 'inbound.updated', $eventId, ['status' => 'ignored']);
        });
    }

    public static function sendTest(Actor $actor, string $id): array
    {
        $actor->assertCan('integrations:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id): array {
            $ep = $tx->one('SELECT provider FROM inbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId]);
            if (!$ep) throw Errors::notFound('Lead source');
            $rnd = bin2hex(random_bytes(4));
            $phone = '+9198' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $email = 'lead.test.' . $rnd . '@example.com';
            $payload = match ($ep['provider']) {
                'meta' => ['leadgen' => ['leadgen_id' => 'test-' . $rnd, 'form_id' => '0'], 'lead' => ['field_data' => [['name' => 'full_name', 'values' => ['Test Lead']], ['name' => 'email', 'values' => [$email]], ['name' => 'phone_number', 'values' => [$phone]], ['name' => 'preferred_location', 'values' => ['Whitefield']]]]],
                'google' => ['lead_id' => 'test-' . $rnd, 'full_name' => 'Test Lead', 'email' => $email, 'phone_number' => $phone, 'is_test' => true],
                'website' => ['name' => 'Test Lead', 'email' => $email, 'phone' => $phone, 'message' => 'This is a test enquiry from the Send test lead button.', 'page_url' => 'https://example.com/contact'],
                default => ['name' => 'Test Lead', 'email' => $email, 'phone' => $phone, 'message' => 'This is a test delivery from the Send test lead button.'],
            };
            $eventId = 'test-' . $rnd;
            $idEvent = Ids::uuid();
            $tx->insert('inbound_events', ['id' => $idEvent, 'tenant_id' => $actor->tenantId, 'endpoint_id' => $id, 'provider' => $ep['provider'], 'provider_event_id' => $eventId, 'payload' => Json::enc($payload), 'is_test' => 1]);
            Jobs::enqueue($tx, ['type' => 'inbound.process', 'payload' => ['eventId' => $idEvent], 'dedupeKey' => 'inbound:' . $idEvent, 'maxAttempts' => 6, 'priority' => 5]);
            Audit::log($tx, 'lead_source.test', 'lead_source', $id, null, ['eventId' => $idEvent]);
            Events::emit($tx, 'inbound.updated', $idEvent, ['status' => 'received']);
            return ['eventId' => $idEvent];
        });
    }

    /** Persist authenticated events and durable jobs in the same tenant transaction before returning 200. */
    public static function receive(array $endpoint, array $events): int
    {
        return Db::tenant(['tenantId' => $endpoint['tenant_id']])->tx(static function (Tx $tx) use ($endpoint, $events): int {
            $accepted = 0;
            foreach ($events as $event) {
                $eventId = $event['eventId'];
                $newId = $tx->insertIgnore('inbound_events', [
                    'id' => Ids::uuid(), 'tenant_id' => $endpoint['tenant_id'], 'endpoint_id' => $endpoint['id'],
                    'provider' => $endpoint['provider'], 'provider_event_id' => $eventId,
                    'payload' => Json::enc($event['payload']), 'is_test' => $event['isTest'] ? 1 : 0,
                ]);
                if (!$newId) continue;
                Jobs::enqueue($tx, ['type' => 'inbound.process', 'payload' => ['eventId' => $newId], 'dedupeKey' => 'inbound:' . $newId, 'maxAttempts' => 6, 'priority' => 5]);
                Events::emit($tx, 'inbound.updated', $newId, ['status' => 'received']);
                $accepted++;
            }
            if ($accepted) $tx->update('inbound_endpoints', ['last_received_at' => Time::now(), 'received_count' => new \App\Core\Raw('received_count + ' . $accepted)], 'id = ? AND tenant_id = ?', [$endpoint['id'], $endpoint['tenant_id']]);
            return $accepted;
        });
    }

    public static function finish(Tx $tx, string $tenantId, string $eventId, string $status, ?string $reason, ?string $leadId): bool
    {
        $set = ['status' => $status, 'reason' => $reason ? mb_substr($reason, 0, 500) : null, 'processed_at' => Time::now()];
        if ($leadId !== null) $set['lead_id'] = $leadId;
        $updated = $tx->update('inbound_events', $set, 'id = ? AND tenant_id = ? AND status = ?', [$eventId, $tenantId, 'received']);
        if (!$updated) return false;
        Audit::log($tx, 'inbound_event.' . $status, 'inbound_event', $eventId, null, ['leadId' => $leadId]);
        Events::emit($tx, 'inbound.updated', $eventId, ['status' => $status, 'leadId' => $leadId]);
        return true;
    }

    private static function validateInput(array $input, bool $creating): void
    {
        if ($creating && !in_array($input['provider'] ?? null, Providers::PROVIDERS, true)) throw Errors::validation([['path' => 'body.provider', 'message' => 'Unsupported lead source provider']]);
        if (array_key_exists('name', $input) && (!is_string($input['name']) || trim($input['name']) === '' || mb_strlen(trim($input['name'])) > 120)) throw Errors::validation([['path' => 'body.name', 'message' => 'Name must be between 1 and 120 characters']]);
        if (isset($input['status']) && !in_array($input['status'], ['active', 'paused'], true)) throw Errors::validation([['path' => 'body.status', 'message' => 'Status must be active or paused']]);
        foreach (['appSecret' => 500, 'pageAccessToken' => 5000] as $key => $max) {
            if (array_key_exists($key, $input) && $input[$key] !== null && (!is_string($input[$key]) || strlen($input[$key]) > $max)) throw Errors::validation([['path' => 'body.' . $key, 'message' => 'Invalid secret value']]);
        }
        if (array_key_exists('config', $input) && !is_array($input['config'])) throw Errors::validation([['path' => 'body.config', 'message' => 'Configuration must be an object']]);
    }

    private static function normalizeConfig(array $config): array
    {
        $out = [];
        foreach (['source', 'campaign', 'pipelineId', 'stageId', 'ownerId'] as $key) {
            if (!array_key_exists($key, $config)) continue;
            if ($key === 'source' && !in_array($config[$key], ['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other'], true)) {
                throw Errors::validation([['path' => 'body.config.source', 'message' => 'Choose a valid lead source']]);
            }
            if (in_array($key, ['pipelineId', 'stageId', 'ownerId'], true) && $config[$key] !== null && !preg_match('/^[0-9a-f-]{36}$/i', (string) $config[$key])) throw Errors::validation([['path' => 'body.config.' . $key, 'message' => 'Expected an id']]);
            if (in_array($key, ['source', 'campaign'], true) && (!is_string($config[$key]) || mb_strlen($config[$key]) > 200)) throw Errors::validation([['path' => 'body.config.' . $key, 'message' => 'Value is too long']]);
            $out[$key] = $config[$key];
        }
        if (isset($config['tags'])) {
            if (!is_array($config['tags'])) throw Errors::validation([['path' => 'body.config.tags', 'message' => 'Tags must be a list']]);
            $out['tags'] = Normalize::tags(array_slice($config['tags'], 0, 30));
        }
        if (isset($config['allowedOrigins'])) {
            if (!is_array($config['allowedOrigins'])) throw Errors::validation([['path' => 'body.config.allowedOrigins', 'message' => 'Allowed origins must be a list']]);
            $origins = [];
            foreach (array_slice($config['allowedOrigins'], 0, 30) as $origin) {
                $origin = rtrim(trim((string) $origin), '/');
                $p = parse_url($origin);
                if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['https', 'http'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['path']) && $p['path'] !== '' || isset($p['query']) || isset($p['fragment'])) throw Errors::validation([['path' => 'body.config.allowedOrigins', 'message' => 'Each allowed origin must be an origin such as https://example.com']]);
                $origins[] = $origin;
            }
            $out['allowedOrigins'] = array_values(array_unique($origins));
        }
        if (isset($config['fieldMap'])) {
            if (!is_array($config['fieldMap']) || count($config['fieldMap']) > 80) throw Errors::validation([['path' => 'body.config.fieldMap', 'message' => 'Invalid field mapping']]);
            $map = [];
            foreach ($config['fieldMap'] as $key => $value) {
                if (!is_string($key) || !is_string($value) || strlen($key) > 100 || strlen($value) > 100) throw Errors::validation([['path' => 'body.config.fieldMap', 'message' => 'Invalid field mapping']]);
                $map[$key] = $value;
            }
            $out['fieldMap'] = $map;
        }
        return $out;
    }

    private static function validateConfig(Tx $tx, string $tenantId, array $config): void
    {
        if (!empty($config['pipelineId'])) {
            if (!$tx->exists('SELECT 1 FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$config['pipelineId'], $tenantId])) throw Errors::validation([['path' => 'body.config.pipelineId', 'message' => 'Pipeline not found']]);
            if (!empty($config['stageId']) && !$tx->exists('SELECT 1 FROM pipeline_stages WHERE id = ? AND pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL', [$config['stageId'], $config['pipelineId'], $tenantId])) throw Errors::validation([['path' => 'body.config.stageId', 'message' => 'Stage not found in that pipeline']]);
        } elseif (!empty($config['stageId'])) throw Errors::validation([['path' => 'body.config.stageId', 'message' => 'Choose the pipeline as well']]);
        if (!empty($config['ownerId']) && !$tx->exists("SELECT 1 FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND u.is_active = 1", [$tenantId, $config['ownerId']])) throw Errors::validation([['path' => 'body.config.ownerId', 'message' => 'Owner must be an active member of this workspace']]);
    }

    private static function fetch(Tx $tx, string $tenantId, string $id, Request $request): array
    {
        $row = $tx->one('SELECT id, provider, name, status, secret_hint, (access_token_enc IS NOT NULL) AS has_access_token, verify_token, config, last_received_at, received_count, created_at FROM inbound_endpoints WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $tenantId]);
        if (!$row) throw Errors::notFound('Lead source');
        $stats = [];
        foreach ($tx->all("SELECT status, COUNT(*) AS n FROM inbound_events WHERE tenant_id = ? AND endpoint_id = ? AND received_at > DATE_SUB(NOW(3), INTERVAL 7 DAY) GROUP BY status", [$tenantId, $id]) as $s) $stats[$s['status']] = (int) $s['n'];
        return self::dto($row, $stats, $request);
    }

    private static function dto(array $row, array $stats, Request $request): array
    {
        $count = ['received' => 0, 'created' => 0, 'duplicate' => 0, 'needs_review' => 0, 'ignored' => 0, 'failed' => 0];
        foreach ($stats as $key => $n) if (array_key_exists($key, $count)) $count[$key] = $n;
        return [
            'id' => $row['id'], 'provider' => $row['provider'], 'name' => $row['name'], 'status' => $row['status'],
            'secretHint' => $row['secret_hint'], 'hasAccessToken' => (bool) $row['has_access_token'],
            'verifyToken' => $row['verify_token'], 'url' => self::hookUrl($request, $row['id']),
            'config' => Json::dec($row['config'], []), 'lastReceivedAt' => $row['last_received_at'],
            'receivedCount' => (int) $row['received_count'], 'stats' => $count,
            'createdAt' => $row['created_at'],
        ];
    }

    private static function summarize(string $provider, array $payload): string
    {
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : $payload;
        try {
            $normalized = Providers::normalize($provider, $payload, [], false);
            return trim($normalized['firstName'] . ' ' . (string) ($normalized['lastName'] ?? ''));
        } catch (\Throwable) {
            if ($provider === 'meta') return !empty($payload['leadgen']['leadgen_id']) ? 'Meta lead ' . substr((string) $payload['leadgen']['leadgen_id'], -6) : '';
            return '';
        }
    }
}
