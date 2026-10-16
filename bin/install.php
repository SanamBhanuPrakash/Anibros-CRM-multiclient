<?php
declare(strict_types=1);

/**
 * Command-line installer (cPanel Terminal / SSH, or the Windows test bed):
 *
 *   php bin/install.php --db-name=cpuser_crm --db-user=cpuser_crm --db-pass=... --app-url=https://crm.anibrosdesign.com
 *       [--db-host=localhost] [--db-port=3306] [--env=production|development] [--smtp-host=mail.example.com --smtp-user=... --smtp-pass=... --smtp-port=465 --smtp-secure=ssl --mail-from="Anibros CRM <no-reply@example.com>"]
 *       [--force]   overwrite an existing config/config.php (new secrets: stored integration tokens become unreadable!)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Installer;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $o[$m[1]] = $m[2] ?? '1';
}
$need = fn(string $k) => $o[$k] ?? (fwrite(STDERR, "Missing --$k\n") && exit(2));
foreach (Installer::requirements() as $name => $r) echo ($r['ok'] ? '[ok]   ' : '[FAIL] ') . $name . ' (' . $r['detail'] . ")\n";

$cfgFile = APP_ROOT . '/config/config.php';
if (is_file($cfgFile) && !isset($o['force'])) {
    echo "config/config.php already exists: keeping it (use --force to replace). Running migrations only.\n";
} else {
    $mail = isset($o['smtp-host']) ? ['host' => $o['smtp-host'], 'port' => (int) ($o['smtp-port'] ?? 587), 'user' => $o['smtp-user'] ?? '', 'pass' => $o['smtp-pass'] ?? '', 'secure' => $o['smtp-secure'] ?? 'tls', 'from' => $o['mail-from'] ?? 'Anibros CRM <no-reply@localhost>'] : null;
    $in = [
        'env' => $o['env'] ?? 'production',
        'app_url' => $need('app-url'),
        'db' => ['host' => $o['db-host'] ?? 'localhost', 'port' => (int) ($o['db-port'] ?? 3306), 'name' => $need('db-name'), 'user' => $need('db-user'), 'pass' => $o['db-pass'] ?? ''],
    ];
    if ($mail) $in['mail'] = $mail;
    echo 'Wrote ', Installer::writeConfig($in), "\n";
}
try {
    $r = Installer::migrate(true);
    echo 'Database ready (', count($r['applied']), ' migration(s) applied, ', $r['views'], " isolation statements).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "\nNext: add the cron job  * * * * * php " . APP_ROOT . "/bin/worker.php   and open the site to create the first account.\n";
