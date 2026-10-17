<?php
declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\JobContext;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\Mailer;
use App\Core\Notify;
use App\Core\PermanentJobError;
use App\Core\Raw;
use App\Core\Tx;
use App\Modules\Leads\LeadsService;

final class MessagingService
{
    private const SEND_LIMIT_PER_10_MIN = 40;
    private static function aad(string $tenantId): string { return 'messaging:' . $tenantId . ':whatsapp'; }

    public static function whatsapp(Actor $actor): array
    {
        $actor->assertCan('integrations:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor): array {
            $r = $tx->one("SELECT phone_number_id, display_phone, token_hint, status, last_error, last_sent_at FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", [$actor->tenantId]);
            return $r ? [
                'connected' => true, 'phoneNumberId' => $r['phone_number_id'], 'displayPhone' => $r['display_phone'],
                'tokenHint' => $r['token_hint'], 'status' => $r['status'], 'lastError' => $r['last_error'], 'lastSentAt' => \App\Core\Time::iso($r['last_sent_at']),
            ] : ['connected' => false, 'phoneNumberId' => null, 'displayPhone' => null, 'tokenHint' => null, 'status' => null, 'lastError' => null, 'lastSentAt' => null];
        });
    }

    public static function connectWhatsapp(Actor $actor, array $input): array
    {
        $actor->assertCan('integrations:manage');
        try {
            $displayPhone = WhatsAppClient::checkAccount($input['phoneNumberId'], $input['accessToken']);
        } catch (PermanentJobError $e) {
            throw Errors::validation([['path' => 'body.accessToken', 'message' => $e->getMessage()]]);
        } catch (\Throwable $e) {
            throw Errors::validation([['path' => 'body.accessToken', 'message' => 'Could not verify the WhatsApp number and token: ' . mb_substr($e->getMessage(), 0, 160)]]);
        }
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $input, $displayPhone): void {
            $tx->insert('messaging_accounts', [
                'id' => Ids::uuid(), 'tenant_id' => $actor->tenantId, 'channel' => 'whatsapp',
                'phone_number_id' => $input['phoneNumberId'], 'display_phone' => $displayPhone,
                'token_enc' => Crypto::encrypt($input['accessToken'], self::aad($actor->tenantId)),
                'token_hint' => substr($input['accessToken'], -4), 'status' => 'active', 'last_error' => null, 'created_by' => $actor->userId,
            ], ['onDuplicate' => [
                'phone_number_id' => $input['phoneNumberId'], 'display_phone' => $displayPhone,
                'token_enc' => Crypto::encrypt($input['accessToken'], self::aad($actor->tenantId)),
                'token_hint' => substr($input['accessToken'], -4), 'status' => 'active', 'last_error' => null, 'created_by' => $actor->userId,
            ]]);
            Audit::log($tx, 'messaging.whatsapp_connect', 'messaging_account', $actor->tenantId, null, ['phoneNumberId' => $input['phoneNumberId']]);
            Events::emit($tx, 'integration.updated', $actor->tenantId, ['change' => 'whatsapp_connected']);
        });
        return self::whatsapp($actor);
    }

    public static function disconnectWhatsapp(Actor $actor): void
    {
        $actor->assertCan('integrations:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor): void {
            $tx->run("DELETE FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", [$actor->tenantId]);
            Audit::log($tx, 'messaging.whatsapp_disconnect', 'messaging_account', $actor->tenantId);
            Events::emit($tx, 'integration.updated', $actor->tenantId, ['change' => 'whatsapp_disconnected']);
        });
    }

    public static function send(Actor $actor, string $leadId, array $input): array
    {
        $body = trim($input['body']);
        if ($body === '' && !($input['channel'] === 'whatsapp' && !empty($input['template']))) throw Errors::validation([['path' => 'body.body', 'message' => 'Write a message']]);
        if ($input['channel'] === 'email' && trim((string) ($input['subject'] ?? '')) === '') throw Errors::validation([['path' => 'body.subject', 'message' => 'Add a subject']]);
        if ($input['channel'] === 'email' && !empty($input['template'])) throw Errors::validation([['path' => 'body.template', 'message' => 'Templates are for WhatsApp only']]);
        $actor->assertCan('activities:create');
        $recent = Db::tenant($actor)->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM activities WHERE tenant_id = ? AND user_id = ? AND JSON_EXTRACT(metadata, '$.delivery') IS NOT NULL AND created_at > DATE_SUB(NOW(3), INTERVAL 10 MINUTE)", [$actor->tenantId, $actor->userId]));
        if ($recent >= self::SEND_LIMIT_PER_10_MIN) throw Errors::rateLimited(120, 'You have sent a lot of messages in the last few minutes. Wait a little before sending more.');

        $template = $input['template'] ?? null;
        $description = $input['channel'] === 'email'
            ? 'Subject: ' . trim((string) $input['subject']) . "\n\n" . $body
            : ($body !== '' ? $body : 'Template “' . $template['name'] . '”');
        $activity = LeadsService::logActivity($actor, $leadId, [
            'type' => $input['channel'], 'description' => $description, 'direction' => 'outbound',
        ], [
            'metadata' => ['delivery' => 'queued', 'channel' => $input['channel']] +
                (!empty($input['subject']) ? ['subject' => trim($input['subject'])] : []) +
                ($template ? ['template' => $template] : []),
            'after' => static function (Tx $tx, array $activity) use ($actor, $leadId, $input): void {
                $contact = $tx->one('SELECT c.email, c.phone, l.contact_id FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ?', [$leadId, $actor->tenantId]);
                if ($input['channel'] === 'email' && empty($contact['email'])) throw Errors::validation([['path' => 'leadId', 'message' => 'This lead has no e-mail address. Add one first.']]);
                if ($input['channel'] === 'whatsapp' && empty($contact['phone'])) throw Errors::validation([['path' => 'leadId', 'message' => 'This lead has no phone number. Add one first.']]);
                $channel = $input['channel'];
                if (!empty($contact['contact_id'])) {
                    $revoked = $tx->exists(
                        "SELECT 1 FROM communication_consents cc WHERE cc.contact_id = ? AND cc.tenant_id = ? AND cc.channel = ? AND cc.status = 'revoked'
                           AND NOT EXISTS (SELECT 1 FROM communication_consents g WHERE g.contact_id = cc.contact_id AND g.tenant_id = cc.tenant_id AND g.channel = cc.channel AND g.status = 'granted') LIMIT 1",
                        [$contact['contact_id'], $actor->tenantId, $channel]
                    );
                    if ($revoked) throw Errors::conflict('This person has opted out of ' . ($channel === 'email' ? 'e-mail' : 'WhatsApp') . ' messages. If they agreed again, switch it back on in their contact first.');
                }
                if ($channel === 'whatsapp' && !$tx->exists("SELECT 1 FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp' AND status = 'active'", [$actor->tenantId])) {
                    throw Errors::conflict('WhatsApp is not connected for this client. Connect its WhatsApp Business number in Settings → Lead sources, or open WhatsApp from the lead to message by hand.');
                }
                Jobs::enqueue($tx, ['type' => 'message.send', 'payload' => ['activityId' => $activity['id']], 'dedupeKey' => 'msg:' . $activity['id'], 'maxAttempts' => 6]);
            },
        ]);
        return ['activityId' => $activity['id'], 'delivery' => 'queued'];
    }

    public static function handle(array $job, JobContext $ctx): array
    {
        $tenantId = (string) ($job['tenantId'] ?? '');
        $activityId = (string) ($job['payload']['activityId'] ?? '');
        if (!Ids::isUuid($tenantId) || !Ids::isUuid($activityId)) throw new PermanentJobError('Malformed message-send job');
        $loaded = $ctx->withTenant(static function (Tx $tx) use ($tenantId, $activityId): array {
            $row = $tx->one('SELECT a.id, a.lead_id, a.type, a.description, a.metadata, a.user_id, c.email, c.phone, t.name AS tenant_name, u.email AS sender_email FROM activities a JOIN leads l ON l.id = a.lead_id AND l.tenant_id = a.tenant_id LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id JOIN tenants t ON t.id = a.tenant_id LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.tenant_id = ?', [$activityId, $tenantId]);
            if (!$row) throw new PermanentJobError('Message activity not found');
            $row['metadata'] = Json::dec($row['metadata'], []);
            if (($row['metadata']['delivery'] ?? null) !== 'queued') return ['skip' => (string) ($row['metadata']['delivery'] ?? 'unknown')];
            $credentials = null;
            if ($row['type'] === 'whatsapp') {
                $account = $tx->one("SELECT phone_number_id, token_enc, status FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", [$tenantId]);
                if (!$account || $account['status'] !== 'active') {
                    self::markFailed($tx, $tenantId, $row, 'WhatsApp is no longer connected for this client.');
                    return ['skip' => 'whatsapp not connected'];
                }
                $credentials = ['phoneNumberId' => $account['phone_number_id'], 'token' => Crypto::decrypt($account['token_enc'], self::aad($tenantId))];
            }
            return ['row' => $row, 'credentials' => $credentials];
        });
        if (isset($loaded['skip'])) return ['skipped' => $loaded['skip']];
        $row = $loaded['row'];
        try {
            $providerId = null;
            if ($row['type'] === 'email') {
                if (!filter_var((string) $row['email'], FILTER_VALIDATE_EMAIL)) throw new PermanentJobError('That e-mail address is not valid.');
                $subject = (string) ($row['metadata']['subject'] ?? '');
                $text = preg_replace('/^Subject: .*\n\n/', '', (string) $row['description']);
                $tenantName = (string) $row['tenant_name'];
                $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $sent = Mailer::transport()->send([
                    'to' => $row['email'], 'fromName' => $tenantName, 'replyTo' => $row['sender_email'] ?: null, 'subject' => $subject,
                    'text' => $text . "\n\n--\nSent on behalf of " . $tenantName,
                    'html' => '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.5;color:#1c1c1a">' . nl2br($safeText, false) . '<p style="color:#8a8a84;font-size:12px;margin-top:24px">Sent on behalf of ' . htmlspecialchars($tenantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div>',
                ]);
                $providerId = $sent['messageId'] ?? null;
            } else {
                $template = $row['metadata']['template'] ?? null;
                $providerId = WhatsAppClient::send($loaded['credentials']['phoneNumberId'], $loaded['credentials']['token'], (string) $row['phone'], (string) $row['description'], $template);
            }
            $ctx->withTenant(static function (Tx $tx) use ($tenantId, $activityId, $row, $providerId): void {
                $meta = $row['metadata'] + ['delivery' => 'sent', 'sentAt' => gmdate('c')];
                $meta['delivery'] = 'sent';
                $meta['sentAt'] = gmdate('c');
                if ($providerId !== null) $meta['providerId'] = $providerId;
                $tx->update('activities', ['metadata' => Json::encObj($meta)], 'id = ? AND tenant_id = ?', [$activityId, $tenantId]);
                if ($row['type'] === 'whatsapp') $tx->run("UPDATE messaging_accounts SET last_sent_at = NOW(3), last_error = NULL WHERE tenant_id = ? AND channel = 'whatsapp'", [$tenantId]);
            });
            return ['sent' => true];
        } catch (\Throwable $e) {
            $permanent = $e instanceof PermanentJobError;
            $reason = $permanent ? $e->getMessage() : ($row['type'] === 'email' ? 'The mail server did not accept the message.' : mb_substr($e->getMessage(), 0, 300));
            if ($permanent || (int) $job['attempts'] >= (int) $job['maxAttempts']) {
                $ctx->withTenant(static function (Tx $tx) use ($tenantId, $row, $reason): void {
                    self::markFailed($tx, $tenantId, $row, $reason);
                    if ($row['type'] === 'whatsapp') $tx->run("UPDATE messaging_accounts SET last_error = ? WHERE tenant_id = ? AND channel = 'whatsapp'", [mb_substr($reason, 0, 300), $tenantId]);
                });
                return ['failed' => $reason];
            }
            throw $e;
        }
    }

    private static function markFailed(Tx $tx, string $tenantId, array $row, string $reason): void
    {
        $meta = $row['metadata'] ?? Json::dec($row['metadata'] ?? '{}', []);
        $meta['delivery'] = 'failed';
        $meta['error'] = mb_substr($reason, 0, 300);
        $tx->update('activities', ['metadata' => Json::encObj($meta)], 'id = ? AND tenant_id = ?', [$row['id'], $tenantId]);
        if (!empty($row['user_id'])) Notify::send($tx, [
            'userId' => $row['user_id'], 'title' => 'Your ' . ($row['type'] === 'email' ? 'e-mail' : 'WhatsApp message') . ' was not delivered',
            'message' => mb_substr($reason, 0, 200), 'type' => 'lead_activity', 'link' => '/leads/' . $row['lead_id'],
            'entityType' => 'lead', 'entityId' => $row['lead_id'], 'dedupeKey' => 'msg-failed:' . $row['id'],
        ]);
    }
}
