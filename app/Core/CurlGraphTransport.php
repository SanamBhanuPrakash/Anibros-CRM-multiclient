<?php
declare(strict_types=1);

namespace App\Core;

final class CurlGraphTransport implements GraphTransport
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $maxResponseBytes
    ): GraphResponse {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'graph.facebook.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new \RuntimeException('Graph API requests must use the official HTTPS endpoint');
        }
        if (!function_exists('curl_init')) throw new \RuntimeException('The PHP cURL extension is required for Graph API requests');
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('Unable to initialize Graph API request');

        $responseBody = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maxResponseBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) throw new \RuntimeException('Graph API response exceeded the configured size limit');
        return new GraphResponse($status, $responseBody, $ok === false ? $error : null);
    }
}
