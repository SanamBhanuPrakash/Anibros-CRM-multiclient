<?php
declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Config;
use App\Core\GraphHttp;
use App\Core\PermanentJobError;

final class WhatsAppClient
{
    public static function checkAccount(string $phoneNumberId, string $token): ?string
    {
        $data = self::request('GET', '/' . rawurlencode($phoneNumberId) . '?fields=display_phone_number,verified_name', $token, null, 10);
        return isset($data['display_phone_number']) ? (string) $data['display_phone_number'] : null;
    }

    public static function send(string $phoneNumberId, string $token, string $to, string $text, ?array $template): string
    {
        $digits = preg_replace('/\D/', '', $to);
        if (strlen($digits) < 8 || strlen($digits) > 15) throw new PermanentJobError('That phone number does not look like a full international number. Add the country code.');
        if ($template) {
            $t = ['name' => $template['name'], 'language' => ['code' => $template['language']]];
            if (!empty($template['params'])) $t['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn($value) => ['type' => 'text', 'text' => $value], $template['params']),
            ]];
            $payload = ['messaging_product' => 'whatsapp', 'to' => $digits, 'type' => 'template', 'template' => $t];
        } else {
            $payload = ['messaging_product' => 'whatsapp', 'to' => $digits, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]];
        }
        $data = self::request('POST', '/' . rawurlencode($phoneNumberId) . '/messages', $token, $payload, 12);
        $id = $data['messages'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') throw new \RuntimeException('WhatsApp did not return a message id');
        return $id;
    }

    private static function request(string $method, string $path, string $token, ?array $payload, int $timeout): array
    {
        $base = rtrim((string) Config::get('whatsapp_graph_url', 'https://graph.facebook.com/v21.0'), '/');
        $url = $base . $path;
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $rawPayload = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = GraphHttp::transport()->request($method, $url, $headers, $rawPayload, $timeout, 1048576);
        if ($response->error !== null) throw new \RuntimeException('Could not reach WhatsApp: ' . mb_substr($response->error, 0, 120));
        $data = json_decode($response->body, true);
        if (!is_array($data)) $data = [];
        if ($response->status >= 200 && $response->status < 300) return $data;
        $code = (int) ($data['error']['code'] ?? 0);
        $knownPermanent = [190, 131047, 131026, 131030, 132001, 132000];
        $knownTransient = [130429, 131056];
        $messages = [
            190 => 'WhatsApp rejected the access token. It has expired or was revoked: connect the number again with a new token.',
            131047 => 'WhatsApp only allows free text within 24 hours of the person’s last message. Send an approved template instead.',
            131026 => 'That phone number is not on WhatsApp, or has not accepted WhatsApp’s terms.',
            131030 => 'That number is not on the list of allowed test recipients for this WhatsApp number.',
            132001 => 'That template does not exist (or not in that language). Check its exact name in WhatsApp Manager.',
            132000 => 'The number of values does not match what the template expects.',
            130429 => 'WhatsApp is slowing this number down (rate limit). It will be retried.',
            131056 => 'Too many messages to this person in a short time. It will be retried.',
        ];
        if (in_array($code, $knownPermanent, true) || (!in_array($code, $knownTransient, true) && $response->status >= 400 && $response->status < 500 && $response->status !== 429)) {
            throw new PermanentJobError($messages[$code] ?? 'WhatsApp refused the message: ' . mb_substr((string) ($data['error']['message'] ?? $response->status), 0, 160));
        }
        throw new \RuntimeException($messages[$code] ?? 'WhatsApp could not accept the message: ' . mb_substr((string) ($data['error']['message'] ?? $response->status), 0, 160));
    }
}
