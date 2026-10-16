<?php
declare(strict_types=1);

namespace App\Core;

/** JSON helpers that keep PHP's array/object ambiguity ({} vs []) under control. */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function enc(mixed $v): string
    {
        return (string) json_encode($v, self::FLAGS);
    }

    /** Encode as a JSON object even when empty (custom_fields, metadata, settings, ...). */
    public static function encObj(mixed $v): string
    {
        if (is_array($v) && $v === []) return '{}';
        return self::enc($v);
    }

    /** Decode JSON text to arrays; invalid or empty input yields $default. */
    public static function dec(?string $text, mixed $default = null): mixed
    {
        if ($text === null || $text === '') return $default;
        try {
            return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
    }

    /** For responses: an empty array that must serialise as {} instead of []. */
    public static function obj(mixed $v): mixed
    {
        if (is_array($v) && ($v === [] || !array_is_list($v))) return $v === [] ? new \stdClass() : $v;
        return $v;
    }
}

/** UUID v4, random tokens. */
final class Ids
{
    private static int $lastMs = 0;
    private static int $seq = 0;

    /**
     * UUID version 7: 48-bit millisecond timestamp, a 12-bit counter that keeps ids created in the same millisecond in creation order, then
     * random bits. Time-ordered keys keep InnoDB inserts sequential (random v4 keys scatter them) and make "ORDER BY created_at, id" a true
     * creation order.
     */
    public static function uuid(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs) {
            $ms = self::$lastMs;
            if (++self::$seq > 0xFFF) { $ms++; self::$seq = 0; }
        } else {
            self::$seq = 0;
        }
        self::$lastMs = $ms;
        $t = sprintf('%012x', $ms);
        $rand = bin2hex(random_bytes(7));
        return substr($t, 0, 8) . '-' . substr($t, 8, 4) . '-7' . sprintf('%03x', self::$seq) . '-' . dechex(8 | (hexdec($rand[0]) & 3)) . substr($rand, 1, 3) . '-' . substr($rand, 4, 10) . substr(bin2hex(random_bytes(1)), 0, 2);
    }

    public static function isUuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    /** URL-safe random token (base64url, no padding). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

/** Time helpers. Everything is UTC. DB format 'Y-m-d H:i:s.v', wire format ISO-8601 with milliseconds and 'Z'. */
final class Time
{
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Anything date-like to a DateTimeImmutable (UTC); null on failure. */
    public static function parse(mixed $v): ?\DateTimeImmutable
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone('UTC'));
        if (is_int($v) || is_float($v)) return (new \DateTimeImmutable('@' . (int) floor($v / 1000)))->setTimezone(new \DateTimeZone('UTC'));
        $s = (string) $v;
        // DB format has no zone: it is UTC
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $s)) $s .= 'Z';
        try {
            return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public static function db(mixed $v): ?string
    {
        $d = self::parse($v);
        return $d ? $d->format('Y-m-d H:i:s.v') : null;
    }

    public static function iso(mixed $v): ?string
    {
        $d = self::parse($v);
        return $d ? $d->format('Y-m-d\TH:i:s.v\Z') : null;
    }

    /** Milliseconds since the epoch of a DB/ISO timestamp, or null. */
    public static function ms(mixed $v): ?int
    {
        $d = self::parse($v);
        return $d ? ((int) $d->format('U')) * 1000 + (int) $d->format('v') : null;
    }

    public static function plus(string $interval, ?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? self::now())->modify($interval);
    }
}

/** Row mapping helpers: DB rows come back as strings/ints; the API wants booleans, numbers, decoded JSON. */
final class Map
{
    /**
     * @param array<string,mixed> $row
     * @param array{json?:array<int|string,mixed>,obj?:string[],bool?:string[],int?:string[],float?:string[]} $spec
     *   json: column => default for decoded JSON arrays; obj: JSON object columns (empty serialises as {}).
     */
    public static function row(array $row, array $spec): array
    {
        foreach ($spec['json'] ?? [] as $k => $def) {
            if (is_int($k)) { $k = $def; $def = []; }
            if (array_key_exists($k, $row)) $row[$k] = is_string($row[$k]) ? Json::dec($row[$k], $def) : ($row[$k] ?? $def);
        }
        foreach ($spec['obj'] ?? [] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = Json::obj(is_string($row[$k]) ? Json::dec($row[$k], []) : ($row[$k] ?? []));
        }
        foreach ($spec['bool'] ?? [] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (bool) $row[$k];
        }
        foreach ($spec['int'] ?? [] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
        }
        foreach ($spec['float'] ?? [] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (float) $row[$k];
        }
        return $row;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function rows(array $rows, array $spec): array
    {
        return array_map(static fn(array $r) => self::row($r, $spec), $rows);
    }

    /** Decimal string from MySQL => float (or null). */
    public static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}

/** Redaction used by logs, audit and operational events (same fragments as the Node logger). */
final class Redact
{
    private const FRAGMENTS = ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey', 'api-key', 'signature', 'credential', 'private_key', 'otp', 'recovery_code', 'session', 'csrf', 'set-cookie'];

    public static function data(mixed $v, int $depth = 0): mixed
    {
        if ($v === null) return null;
        if (is_string($v)) return mb_strlen($v) > 2000 ? mb_substr($v, 0, 2000) . '…[truncated]' : $v;
        if (is_scalar($v)) return $v;
        if ($depth >= 6) return '[MAX_DEPTH]';
        if ($v instanceof \Throwable) return ['name' => get_class($v), 'message' => $v->getMessage()];
        if (is_object($v)) $v = (array) $v;
        if (is_array($v)) {
            if (array_is_list($v)) return array_map(static fn($x) => self::data($x, $depth + 1), array_slice($v, 0, 50));
            $out = [];
            foreach ($v as $k => $x) $out[$k] = self::isSensitive((string) $k) ? '[REDACTED]' : self::data($x, $depth + 1);
            return $out;
        }
        return null;
    }

    public static function isSensitive(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::FRAGMENTS as $f) if (str_contains($k, $f)) return true;
        return false;
    }
}
