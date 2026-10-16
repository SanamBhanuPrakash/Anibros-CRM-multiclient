<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Token-bucket rate limiter kept in the database (PHP has no memory between requests): table rate_buckets, one atomic upsert per
 * decision, database clock. The bucket holds `capacity` tokens refilled at `refillPerSecond`: bursts are allowed, the long-run rate
 * is enforced. If the database cannot be reached the request is let through (a limiter must never be the reason the service is down).
 */
final class RateLimiter
{
    /** @return array{allowed:bool,remaining:int,retryAfter:int,limit:int} */
    public static function take(string $name, string $key, float $capacity, float $refillPerSecond, float $cost = 1.0): array
    {
        $scale = (float) Config::get('rate_limits_scale', 1);
        $capacity *= $scale;
        $refillPerSecond *= $scale;
        $k = substr($name . ':' . $key, 0, 200);
        $limit = (int) $capacity;
        try {
            return Db::system()->tx(static function (Tx $tx) use ($k, $capacity, $refillPerSecond, $cost, $limit) {
                $tx->run(
                    'INSERT INTO rate_buckets (`key`, tokens, updated_at) VALUES (?, ?, NOW(6))
                     ON DUPLICATE KEY UPDATE tokens = LEAST(?, tokens + GREATEST(0, TIMESTAMPDIFF(MICROSECOND, updated_at, NOW(6)) / 1000000) * ?), updated_at = NOW(6)',
                    [$k, $capacity, $capacity, $refillPerSecond]
                );
                $tokens = (float) $tx->val('SELECT tokens FROM rate_buckets WHERE `key` = ? FOR UPDATE', [$k]);
                if ($tokens >= $cost) {
                    $tx->run('UPDATE rate_buckets SET tokens = tokens - ? WHERE `key` = ?', [$cost, $k]);
                    return ['allowed' => true, 'remaining' => (int) floor($tokens - $cost), 'retryAfter' => 0, 'limit' => $limit];
                }
                return ['allowed' => false, 'remaining' => 0, 'retryAfter' => (int) ceil(($cost - $tokens) / $refillPerSecond), 'limit' => $limit];
            }, ['retries' => 1]);
        } catch (\Throwable $e) {
            Logger::warn('Rate limiter unavailable (request allowed)', ['error' => $e->getMessage()]);
            return ['allowed' => true, 'remaining' => $limit, 'retryAfter' => 0, 'limit' => $limit];
        }
    }

    /** Throw 429 when over the allowance. */
    public static function guard(string $name, string $key, float $capacity, float $refillPerSecond, string $message = 'Too many requests. Please try again later.'): void
    {
        $d = self::take($name, $key, $capacity, $refillPerSecond);
        if (!$d['allowed']) throw Errors::rateLimited($d['retryAfter'], $message);
    }

    public static function purge(): int
    {
        return Db::system()->tx(static fn(Tx $tx) => $tx->run('DELETE FROM rate_buckets WHERE updated_at < DATE_SUB(NOW(6), INTERVAL 1 DAY)'));
    }
}
