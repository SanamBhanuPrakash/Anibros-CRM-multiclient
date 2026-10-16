<?php
declare(strict_types=1);

/**
 * Bootstrap: autoloader (PSR-4, namespace App\ => app/), configuration, error handling.
 * Required by public/index.php (web), bin/*.php (cron, CLI).
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Anibros CRM needs PHP 8.1 or newer (cPanel: MultiPHP Manager).');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    // A few small classes share a file; everything else is one class per file (PSR-4).
    static $shared = [
        'App\\Core\\AppError' => 'Core/Errors.php',
        'App\\Core\\Json' => 'Core/Support.php', 'App\\Core\\Ids' => 'Core/Support.php', 'App\\Core\\Time' => 'Core/Support.php',
        'App\\Core\\Map' => 'Core/Support.php', 'App\\Core\\Redact' => 'Core/Support.php',
        'App\\Core\\Raw' => 'Core/Db.php', 'App\\Core\\Conn' => 'Core/Db.php', 'App\\Core\\Tx' => 'Core/Db.php', 'App\\Core\\Sql' => 'Core/Db.php',
        'App\\Core\\Actor' => 'Core/Authz.php', 'App\\Core\\Matrix' => 'Core/Authz.php',
        'App\\Core\\Request' => 'Core/Http.php', 'App\\Core\\Reply' => 'Core/Http.php', 'App\\Core\\RawReply' => 'Core/Http.php',
        'App\\Core\\Response' => 'Core/Http.php', 'App\\Core\\Ctx' => 'Core/Http.php',
        'App\\Core\\PermanentJobError' => 'Core/Jobs.php', 'App\\Core\\RetryAfterError' => 'Core/Jobs.php', 'App\\Core\\JobContext' => 'Core/Jobs.php', 'App\\Core\\CsvError' => 'Core/Csv.php',
    ];
    $file = APP_DIR . '/' . ($shared[$class] ?? str_replace('\\', '/', substr($class, 4)) . '.php');
    if (is_file($file)) require $file;
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

App\Core\Config::load();

// Nothing is ever printed to the browser on a defect: it is logged and answered with an opaque 500 (see Core\Http).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    // Deprecations (new PHP versions on the host) are logged, never fatal.
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
        error_log("[deprecated] $msg in $file:$line");
        return true;
    }
    // Warnings and notices are defects in this code base: surface them as exceptions so they are logged with a stack.
    throw new ErrorException($msg, 0, $no, $file, $line);
});
