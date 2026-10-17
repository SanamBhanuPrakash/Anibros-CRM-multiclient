<?php
declare(strict_types=1);

namespace App\Modules\Outbound;

use App\Core\Config;

final class BlockedTarget extends \RuntimeException
{
}

final class SafeHttp
{
    /** Validate the URL and resolve/pin a public address before sending. */
    public static function postJson(string $rawUrl, string $body, array $headers = [], int $timeoutSeconds = 8): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('The cURL extension is required for outbound webhooks');
        $parts = parse_url($rawUrl);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)) {
            throw new BlockedTarget('Use a valid https:// webhook address.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && (Config::isProduction() || !Config::get('outbound_allow_private', false))) {
            throw new BlockedTarget('Use an https:// address so webhook data is encrypted.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) throw new BlockedTarget('Remove the username and password from the webhook address.');
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowPrivate = (bool) Config::get('outbound_allow_private', false) && !Config::isProduction();
        $addresses = self::resolve($host);
        if (!$allowPrivate) {
            foreach ($addresses as $address) {
                if (!self::isPublicIp($address)) throw new BlockedTarget('That address points to a private or reserved network.');
            }
        }
        if (!$addresses) throw new BlockedTarget('We could not resolve that webhook address.');
        $ip = $addresses[0];
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $url = $scheme . '://' . $resolveHost . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port) . $path;
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('Unable to initialize webhook request');
        $responseBody = '';
        $started = microtime(true);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Content-Length: ' . strlen($body)], $headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) < 2048) $responseBody .= substr($chunk, 0, 2048 - strlen($responseBody));
                return strlen($chunk);
            },
        ];
        if (!filter_var($host, FILTER_VALIDATE_IP)) $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolveIp];
        curl_setopt_array($ch, $options);
        foreach ($addresses as $address) if (!$allowPrivate && !self::isPublicIp($address)) {
            curl_close($ch);
            throw new BlockedTarget('That address points to a private or reserved network.');
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? curl_error($ch) : null;
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        curl_close($ch);
        if ($ok === false) throw new \RuntimeException(mb_substr($error ?: 'Webhook request failed', 0, 300));
        return ['status' => $status, 'ms' => $elapsed, 'body' => $responseBody];
    }

    /** @return array<int,string> */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $ips = [];
        foreach (gethostbynamel($host) ?: [] as $ip) $ips[] = $ip;
        if (function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }
        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ipv4 = inet_ntop(substr($packed, 12));
            if ($ipv4 === false) return false;
            $ip = $ipv4;
        }
        $blocked = str_contains($ip, ':')
            ? ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '64:ff9b::/96', '100::/64']
            : ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        foreach ($blocked as $range) if (self::inCidr($ip, $range)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function inCidr(string $ip, string $range): bool
    {
        [$network, $bits] = explode('/', $range, 2);
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) return false;
        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if (substr($address, 0, $fullBytes) !== substr($base, 0, $fullBytes)) return false;
        if ($remaining === 0) return true;
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($address[$fullBytes]) & $mask) === (ord($base[$fullBytes]) & $mask);
    }
}
