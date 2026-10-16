<?php
declare(strict_types=1);

/**
 * Create / upgrade the database objects: php bin/migrate.php
 *   --views   only (re)create the isolation functions and views
 * Safe to run any number of times.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Migrator;
use App\Core\Views;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$only = in_array('--views', $argv, true);
try {
    $pdo = Migrator::ensureDatabase((array) Config::get('db'));
    if ($only) {
        echo 'views: ', Views::apply($pdo), " statements\n";
    } else {
        $r = Migrator::run($pdo, true);
        echo 'applied: ', $r['applied'] ? implode(', ', $r['applied']) : 'nothing new', "\nviews: ", $r['views'], " statements\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
