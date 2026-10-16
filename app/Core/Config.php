<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Runtime configuration. Source order: config/config.php (written by the installer) then environment overrides ANIBROS_*.
 * Secrets are never logged: use describe() for diagnostics.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $c = [];

    public static function load(?string $file = null): void
    {
        $file ??= APP_ROOT . '/config/config.php';
        $cfg = is_file($file) ? (require $file) : [];
        if (!is_array($cfg)) $cfg = [];
        // Environment overrides (handy for CI and the Windows test bed): ANIBROS_DB_NAME, ANIBROS_ENV, ...
        $map = [
            'ANIBROS_ENV' => 'env', 'ANIBROS_APP_URL' => 'app_url',
            'ANIBROS_DB_HOST' => 'db.host', 'ANIBROS_DB_PORT' => 'db.port', 'ANIBROS_DB_NAME' => 'db.name',
            'ANIBROS_DB_USER' => 'db.user', 'ANIBROS_DB_PASS' => 'db.pass', 'ANIBROS_DB_SOCKET' => 'db.socket',
            'ANIBROS_COOKIE_SECRET' => 'secrets.cookie', 'ANIBROS_FIELD_KEYS' => 'secrets.field_keys',
            'ANIBROS_SMTP_HOST' => 'mail.host', 'ANIBROS_SMTP_PORT' => 'mail.port',
            'ANIBROS_OUTBOUND_ALLOW_PRIVATE' => 'outbound_allow_private',
        ];
        foreach ($map as $env => $key) {
            $v = getenv($env);
            if ($v !== false && $v !== '') self::setPath($cfg, $key, $v);
        }
        $defaults = [
            'env' => 'production',
            'app_url' => null,
            'allowed_origins' => [],
            'trust_proxy' => false,
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'socket' => ''],
            'secrets' => ['cookie' => '', 'field_keys' => ''],
            'mail' => ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => '', 'from' => 'Anibros CRM <no-reply@localhost>'],
            'session_cookie' => 'anibrosdesign_session',
            'session_idle_hours' => 12,
            'session_absolute_days' => 14,
            'login_ip_limit' => 600,
            'storage_dir' => APP_ROOT . '/storage',
            'log_level' => 'info',
            'outbound_allow_private' => false,
            'whatsapp_graph_url' => 'https://graph.facebook.com/v21.0',
            'meta_app_secret' => '',
            'meta_verify_token' => '',
            'pump_after_response' => true,
            'rate_limits_scale' => 1,
        ];
        self::$c = self::merge($defaults, $cfg);
        if (is_string(self::$c['outbound_allow_private'])) self::$c['outbound_allow_private'] = in_array(strtolower(self::$c['outbound_allow_private']), ['1', 'true', 'yes'], true);
    }

    /** Dot path read: Config::get('db.host'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }

    public static function set(string $path, mixed $value): void
    {
        self::setPath(self::$c, $path, $value);
    }

    public static function isProduction(): bool
    {
        return self::$c['env'] === 'production';
    }

    public static function isTest(): bool
    {
        return self::$c['env'] === 'test';
    }

    public static function describe(): array
    {
        return [
            'env' => self::$c['env'],
            'db' => self::get('db.user') . '@' . self::get('db.host') . ':' . self::get('db.port') . '/' . self::get('db.name'),
            'smtp' => (bool) self::get('mail.host'),
            'appUrl' => self::$c['app_url'],
        ];
    }

    private static function setPath(array &$arr, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $ref = &$arr;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = $ref[$k] ?? [];
            if (!is_array($ref[$k])) $ref[$k] = [];
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    private static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = (isset($a[$k]) && is_array($a[$k]) && is_array($v) && !array_is_list($v)) ? self::merge($a[$k], $v) : $v;
        }
        return $a;
    }
}
