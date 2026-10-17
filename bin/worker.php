<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $stats = \App\Core\Worker::pump(55.0, 100);
    fwrite(STDOUT, json_encode($stats, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    error_log('Anibros worker failed: ' . $e->getMessage());
    fwrite(STDERR, "Worker failed\n");
    exit(1);
}
