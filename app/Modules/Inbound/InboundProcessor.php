<?php
declare(strict_types=1);

namespace App\Modules\Inbound;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Db;
use App\Core\Events;
use App\Core\GraphHttp;
use App\Core\Ids;
use App\Core\JobContext;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Notify;
use App\Core\PermanentJobError;
use App\Core\Raw;
use App\Core\Tx;
use App\Modules\Analytics\Touches;
use App\Modules\Contacts\Normalize;
use App\Modules\Leads\LeadsService;

final class InboundProcessor
{
    private const LABELS = ['meta' => 'Meta Lead Ads', 'google' => 'Google Ads lead form', 'website' => 'your website form', 'generic' => 'an integration'];

    /** @param array<string,mixed> $job */
    public static function handle(array $job, JobContext $_context): mixed
    {
        $tenantId = (string) ($job['tenantId'] ?? '');
        $eventId = (string) ($job['payload']['eventId'] ?? '');
        if (!Ids::isUuid($tenantId) || !Ids::isUuid($eventId)) throw new PermanentJobError('Malformed inbound job');
        try {
            return self::process($tenantId, $eventId);
        } catch (\Throwable $e) {
            if ($e instanceof PermanentJobError || (int) ($job['attempts'] ?? 0) >= (int) ($job['maxAttempts'] ?? 6)) {
                try {
                    Db::tenant(['tenantId' => $tenantId])->tx(static fn(Tx $tx) => InboundService::finish($tx, $tenantId, $eventId, 'failed', 'We could not process this delivery after several attempts. Use Retry once the cause is fixed.', null));
                } catch (\Throwable $recordError) {
                    Logger::error('Unable to mark inbound delivery failed', ['eventId' => $eventId, 'error' => $recordError->getMessage()]);
                }
            }
            throw $e;
        }
    }

    public static function reconcileDeadJobs(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $failedEvents = Db::system()->read(static fn(Tx $tx) => $tx->all(
            "SELECT j.tenant_id, ev.id AS event_id
               FROM jobs j
               JOIN inbound_events ev
                 ON ev.id = CASE WHEN JSON_VALID(j.payload) THEN JSON_UNQUOTE(JSON_EXTRACT(j.payload, '$.eventId')) ELSE '' END
                AND ev.tenant_id = j.tenant_id
              WHERE j.type = 'inbound.process' AND j.status = 'dead' AND ev.status = 'received'
                AND NOT EXISTS (
                    SELECT 1 FROM jobs active
                     WHERE active.type = 'inbound.process' AND active.tenant_id = j.tenant_id
                       AND CASE WHEN JSON_VALID(active.payload) THEN JSON_UNQUOTE(JSON_EXTRACT(active.payload, '$.eventId')) ELSE '' END = ev.id
                       AND active.status IN ('queued', 'running')
                )
              GROUP BY j.tenant_id, ev.id
              ORDER BY MIN(j.finished_at), ev.id
              LIMIT ?",
            [$limit]
        ));
        $reconciled = 0;
        foreach ($failedEvents as $row) {
            $tenantId = (string) $row['tenant_id'];
            $eventId = (string) $row['event_id'];
            if (!Ids::isUuid($tenantId) || !Ids::isUuid($eventId)) continue;
            $updated = Db::tenant(['tenantId' => $tenantId])->tx(static fn(Tx $tx) => InboundService::finish(
                $tx,
                $tenantId,
                $eventId,
                'failed',
                'The worker exhausted its retries before recording an outcome. Check worker health, then retry this delivery.',
                null
            ));
            if ($updated) $reconciled++;
        }
        return $reconciled;
    }

    private static function process(string $tenantId, string $eventId): array
    {
        $row = Db::tenant(['tenantId' => $tenantId])->tx(static function (Tx $tx) use ($tenantId, $eventId): ?array {
            $row = $tx->one(
                'SELECT ev.id, ev.endpoint_id, ev.provider, ev.status, ev.payload, ev.is_test, ev.attempts, ep.config, ep.secret_enc, ep.access_token_enc FROM inbound_events ev JOIN inbound_endpoints ep ON ep.id = ev.endpoint_id WHERE ev.id = ? AND ev.tenant_id = ?',
                [$eventId, $tenantId]
            );
            if ($row && $row['status'] === 'received') {
                $tx->update('inbound_events', ['attempts' => new Raw('attempts + 1')], 'id = ? AND tenant_id = ? AND status = ?', [$eventId, $tenantId, 'received']);
            }
            return $row;
        });
        if (!$row) throw new PermanentJobError('Delivery not found');
        if ($row['status'] !== 'received') return ['skipped' => $row['status']];
        $payload = Json::dec($row['payload'], []);
        $config = Json::dec($row['config'], []);

        if ($row['provider'] === 'meta' && !isset($payload['lead'])) {
            $token = InboundService::readSecret($row['endpoint_id'], $row['access_token_enc']);
            $appSecret = InboundService::readSecret($row['endpoint_id'], $row['secret_enc']);
            if (!$token || !$appSecret) return self::needsReview($tenantId, $eventId, 'Add this Meta source’s page access token so we can fetch the lead’s answers, then press Retry.');
            try {
                $payload['lead'] = self::fetchMetaLead((string) ($payload['leadgen']['leadgen_id'] ?? ''), $token, $appSecret);
                Db::tenant(['tenantId' => $tenantId])->tx(static fn(Tx $tx) => $tx->update('inbound_events', ['payload' => Json::enc($payload)], 'id = ? AND tenant_id = ?', [$eventId, $tenantId]));
            } catch (MetaPermanentFailure $e) {
                return self::needsReview($tenantId, $eventId, 'Meta refused the request: ' . $e->getMessage() . '. Check the page access token and its lead-retrieval permission, then press Retry.');
            }
        }

        try {
            $lead = Providers::normalize($row['provider'], $payload, $config, (bool) $row['is_test']);
        } catch (BadPayload $e) {
            return self::needsReview($tenantId, $eventId, $e->getMessage() . '.');
        }
        if (!Normalize::email($lead['email']) && !Normalize::phone($lead['phone'])) {
            return self::needsReview($tenantId, $eventId, 'The submission had no usable phone number or email address, so there is nobody to contact.');
        }
        $actor = Actor::system($tenantId);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($tenantId, $eventId, $row, $config, $lead, $actor): array {
            $cur = $tx->one('SELECT status FROM inbound_events WHERE id = ? AND tenant_id = ? FOR UPDATE', [$eventId, $tenantId]);
            if (!$cur || $cur['status'] !== 'received') return ['skipped' => $cur['status'] ?? 'missing'];

            $input = [
                'contact' => ['firstName' => $lead['firstName'], 'lastName' => $lead['lastName'], 'email' => $lead['email'], 'phone' => $lead['phone']],
                'source' => $lead['source'], 'campaign' => $lead['campaign'], 'tags' => $lead['tags'],
                'budgetMax' => $lead['budgetMax'], 'bedroomsMin' => $lead['bedroomsMin'], 'locations' => $lead['locations'],
                'sourceDetails' => $lead['details'] + ['inbound' => ['provider' => $row['provider'], 'endpointId' => $row['endpoint_id'], 'eventId' => $eventId]],
            ];
            foreach (['pipelineId' => 'pipelineId', 'stageId' => 'stageId', 'ownerId' => 'ownerId'] as $cfgKey => $inputKey) {
                if (!empty($config[$cfgKey])) $input[$inputKey] = $config[$cfgKey];
            }
            $tx->run('SAVEPOINT inbound_lead');
            try {
                $created = LeadsService::createInTx($tx, $actor, $input, ['enforceRequired' => false]);
                $tx->run('RELEASE SAVEPOINT inbound_lead');
                self::addTimeline($tx, $tenantId, $created['id'], $row['endpoint_id'], $eventId, $row['provider'], $lead, 'created');
                self::addTheirWords($tx, $tenantId, $created['id'], $row['endpoint_id'], $row['provider'], $lead);
                InboundService::finish($tx, $tenantId, $eventId, 'created', null, $created['id']);
                if (!empty($created['ownerId'])) {
                    Notify::send($tx, [
                        'userId' => $created['ownerId'], 'title' => 'New lead from ' . self::LABELS[$row['provider']],
                        'message' => implode(' · ', array_filter([$created['contactName'] ?? null, $lead['campaign']])),
                        'type' => 'lead_assigned', 'link' => '/leads/' . $created['id'], 'entityType' => 'lead',
                        'entityId' => $created['id'], 'dedupeKey' => 'inbound-lead:' . $eventId,
                    ]);
                }
                return ['status' => 'created', 'leadId' => $created['id']];
            } catch (AppError $e) {
                $tx->run('ROLLBACK TO SAVEPOINT inbound_lead');
                $tx->run('RELEASE SAVEPOINT inbound_lead');
                if (($e->extensions['reason'] ?? null) === 'duplicate_lead' && !empty($e->extensions['leadId'])) {
                    $existingLead = (string) $e->extensions['leadId'];
                    Touches::recordLater($tx, $tenantId, $existingLead, Touches::from(['source' => $lead['source'], 'campaign' => $lead['campaign'], 'sourceDetails' => $lead['details']]));
                    self::addTimeline($tx, $tenantId, $existingLead, $row['endpoint_id'], $eventId, $row['provider'], $lead, 'duplicate');
                    self::addTheirWords($tx, $tenantId, $existingLead, $row['endpoint_id'], $row['provider'], $lead);
                    InboundService::finish($tx, $tenantId, $eventId, 'duplicate', 'This person already has an open lead, so the new enquiry was added to its timeline.', $existingLead);
                    $owner = $tx->val('SELECT owner_id FROM leads WHERE id = ? AND tenant_id = ?', [$existingLead, $tenantId]);
                    if ($owner) {
                        Notify::send($tx, [
                            'userId' => $owner, 'title' => 'An existing lead enquired again',
                            'message' => 'Via ' . self::LABELS[$row['provider']], 'type' => 'lead_activity',
                            'link' => '/leads/' . $existingLead, 'entityType' => 'lead',
                            'entityId' => $existingLead, 'dedupeKey' => 'inbound-dup:' . $eventId,
                        ]);
                    }
                    return ['status' => 'duplicate', 'leadId' => $existingLead];
                }
                if ($e->status >= 400 && $e->status < 500) {
                    InboundService::finish($tx, $tenantId, $eventId, 'needs_review', mb_substr($e->getMessage(), 0, 500), null);
                    return ['status' => 'needs_review'];
                }
                throw $e;
            } catch (\Throwable $e) {
                $tx->run('ROLLBACK TO SAVEPOINT inbound_lead');
                $tx->run('RELEASE SAVEPOINT inbound_lead');
                throw $e;
            }
        });
    }

    private static function needsReview(string $tenantId, string $eventId, string $reason): array
    {
        Db::tenant(['tenantId' => $tenantId])->tx(static fn(Tx $tx) => InboundService::finish($tx, $tenantId, $eventId, 'needs_review', $reason, null));
        return ['status' => 'needs_review'];
    }

    private static function addTimeline(Tx $tx, string $tenantId, string $leadId, string $endpointId, string $eventId, string $provider, array $lead, string $outcome): void
    {
        $label = self::LABELS[$provider] ?? 'an integration';
        $title = $outcome === 'duplicate' ? 'Enquired again via ' : 'Captured from ';
        $description = $title . $label . ($lead['campaign'] ? ' · ' . $lead['campaign'] : '');
        $details = ['event' => 'inbound', 'outcome' => $outcome, 'provider' => $provider, 'endpointId' => $endpointId, 'eventId' => $eventId, 'isTest' => $lead['isTest'], 'answers' => $lead['answers']];
        $tx->insert('activities', ['tenant_id' => $tenantId, 'lead_id' => $leadId, 'type' => 'note', 'description' => mb_substr($description, 0, 500), 'metadata' => Json::enc($details)]);
        Events::emit($tx, 'activity.created', $leadId, ['kind' => 'inbound']);
    }

    private static function addTheirWords(Tx $tx, string $tenantId, string $leadId, string $endpointId, string $provider, array $lead): void
    {
        $talked = count($lead['conversation']) > 0;
        if (!$lead['message'] && !$talked) return;
        if ($talked) {
            $lines = ['Chat with the visitor:'];
            foreach (array_slice($lead['conversation'], 0, 60) as $turn) $lines[] = ($turn['who'] === 'visitor' ? 'Visitor: ' : 'Bot: ') . $turn['text'];
            $description = mb_substr(implode("\n", $lines), 0, 6000);
        } else {
            $description = mb_substr('Their message: “' . $lead['message'] . '”', 0, 2200);
        }
        $metadata = ['event' => 'inbound_message', 'provider' => $provider, 'endpointId' => $endpointId, 'isTest' => $lead['isTest']];
        if ($talked) $metadata['turns'] = count($lead['conversation']);
        $tx->insert('activities', ['tenant_id' => $tenantId, 'lead_id' => $leadId, 'type' => 'note', 'description' => $description, 'metadata' => Json::enc($metadata)]);
        Events::emit($tx, 'activity.created', $leadId, ['kind' => 'inbound_message']);
    }

    private static function fetchMetaLead(string $leadId, string $token, string $appSecret): array
    {
        $request = self::metaGraphRequest($leadId, $token, $appSecret);
        $response = GraphHttp::transport()->request('GET', $request['url'], $request['headers'], null, 8, 1048576);
        if ($response->error !== null) throw new \RuntimeException('Meta lead retrieval failed: ' . mb_substr($response->error, 0, 200));
        try {
            $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Meta returned an invalid response');
        }
        if (!is_array($decoded) || array_is_list($decoded)) throw new \RuntimeException('Meta returned an invalid response object');
        $code = (int) ($decoded['error']['code'] ?? 0);
        if ($response->status === 429 || $response->status >= 500 || in_array($code, [1, 2, 4, 17, 32, 341, 613], true)) throw new \RuntimeException('Meta is busy or unavailable (HTTP ' . $response->status . ')');
        if ($response->status >= 400 || isset($decoded['error'])) {
            $message = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? 'Request denied') : 'Request denied';
            if (in_array($code, [100, 102, 190, 200, 10, 803, 2500], true) || in_array($response->status, [400, 401, 403, 404], true)) throw new MetaPermanentFailure(mb_substr($message, 0, 200));
            throw new \RuntimeException(mb_substr($message, 0, 200));
        }
        if (!isset($decoded['id'])) throw new \RuntimeException('Meta returned no lead record');
        return $decoded;
    }

    /** @return array{url:string,headers:array<int,string>} */
    private static function metaGraphRequest(string $leadId, string $token, string $appSecret): array
    {
        if (!preg_match('/^\d{5,40}$/', $leadId)) throw new MetaPermanentFailure('The lead id from Meta is not a valid id');
        $proof = hash_hmac('sha256', $token, $appSecret);
        $query = http_build_query(['fields' => 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,platform,is_organic,field_data', 'appsecret_proof' => $proof], '', '&', PHP_QUERY_RFC3986);
        return [
            'url' => 'https://graph.facebook.com/v23.0/' . rawurlencode($leadId) . '?' . $query,
            'headers' => ['Accept: application/json', 'Authorization: Bearer ' . $token],
        ];
    }
}
