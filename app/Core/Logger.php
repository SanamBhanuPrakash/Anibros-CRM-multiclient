<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Structured JSON logger: one line per event in storage/logs/app-YYYY-MM-DD.log (rotated daily, old files pruned by the worker).
 * Sensitive keys are redacted like in the Node version. Logging never throws.
 */
final class Logger
{
    private const ORDER = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];
    public static ?string $correlationId = null;

    public static function debug(string $msg, array $meta = []): void { self::log('debug', $msg, $meta); }
    public static function info(string $msg, array $meta = []): void { self::log('info', $msg, $meta); }
    public static function warn(string $msg, array $meta = []): void { self::log('warn', $msg, $meta); }
    public static function error(string $msg, array $meta = []): void { self::log('error', $msg, $meta); }

    public static function log(string $level, string $msg, array $meta = []): void
    {
        try {
            $min = self::ORDER[(string) Config::get('log_level', 'info')] ?? 20;
            if ((self::ORDER[$level] ?? 20) < $min) return;
            $entry = [
                'timestamp' => Time::iso(Time::now()),
                'level' => $level,
                'correlationId' => self::$correlationId,
                'message' => $msg,
            ];
            if ($meta) $entry['meta'] = Redact::data($meta);
            $dir = rtrim((string) Config::get('storage_dir'), '/\\') . '/logs';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $line = Json::enc($entry) . "\n";
            if (@file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX) === false) error_log($line);
        } catch (\Throwable) {
            // a log write must never take the request down
        }
    }
}
