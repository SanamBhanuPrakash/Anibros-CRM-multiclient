<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Persistent, workspace-visible activity log (table operational_logs): the curated subset a workspace admin sees in the Monitor panel
 * (server errors, security denials, slow requests, failed jobs, rejected lead deliveries).
 *
 * Entries are buffered in memory and written once, after the response has been sent (register_shutdown_function), so logging is never on
 * the request path. A throttle key limits an entry to once per minute (stored through the file cache so it works across requests).
 * A failing write is reported on the file log and swallowed: observability must never become an outage.
 */
final class EventLog
{
    private const MAX_BUFFER = 200;
    /** @var array<int,array<string,mixed>> */
    private static array $buffer = [];
    private static bool $hooked = false;

    /**
     * @param array{tenantId?:?string,userId?:?string,component:string,severity:string,message:string,context?:array,correlationId?:?string,throttleKey?:?string} $e
     */
    public static function record(array $e): void
    {
        if (!empty($e['throttleKey']) && !self::throttleAllows(($e['tenantId'] ?? '-') . ':' . $e['throttleKey'])) return;
        if (count(self::$buffer) >= self::MAX_BUFFER) array_shift(self::$buffer);
        $e['at'] = Time::now();
        self::$buffer[] = $e;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function flush(): void
    {
        $batch = self::$buffer;
        self::$buffer = [];
        if (!$batch) return;
        try {
            $conn = Db::system();
            $conn->tx(static function (Tx $tx) use ($batch) {
                foreach ($batch as $e) {
                    $msg = (string) $e['message'];
                    $tx->insert('operational_logs', [
                        'tenant_id' => $e['tenantId'] ?? null,
                        'component' => mb_substr((string) $e['component'], 0, 40),
                        'severity' => $e['severity'],
                        'message' => mb_strlen($msg) > 500 ? mb_substr($msg, 0, 500) . '…' : $msg,
                        'context' => Json::encObj(Redact::data($e['context'] ?? [])),
                        'correlation_id' => $e['correlationId'] ?? null,
                        'user_id' => $e['userId'] ?? null,
                        'created_at' => $e['at'],
                    ]);
                }
            }, ['retries' => 0]);
        } catch (\Throwable $ex) {
            Logger::warn('Activity log write failed', ['error' => $ex->getMessage(), 'entries' => count($batch)]);
        }
    }

    private static function throttleAllows(string $key): bool
    {
        $file = rtrim((string) Config::get('storage_dir'), '/\\') . '/cache/throttle-' . substr(sha1($key), 0, 24);
        $now = time();
        $last = is_file($file) ? (int) @filemtime($file) : 0;
        if ($last && $now - $last < 60) return false;
        @touch($file);
        return true;
    }
}
