<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

\App\Routes::registerAll();
$routes = \App\Core\Router::routes();
$monitor = array_values(array_filter($routes, static fn(array $route): bool => str_starts_with($route['fullPath'], '/api/v1/monitor')));
$paths = array_column($monitor, 'fullPath');
$expected = [
    '/api/v1/monitor/overview',
    '/api/v1/monitor/events',
    '/api/v1/monitor/audit',
    '/api/v1/monitor/audit/facets',
    '/api/v1/monitor/audit/export',
    '/api/v1/monitor/audit/{id}',
];
if ($paths !== $expected) {
    throw new RuntimeException('Monitor route contract mismatch: ' . json_encode($paths));
}

$permissions = array_column($monitor, 'perm', 'fullPath');
if (($permissions['/api/v1/monitor/overview'] ?? null) !== 'operations:read'
    || ($permissions['/api/v1/monitor/events'] ?? null) !== 'operations:read'
    || ($permissions['/api/v1/monitor/audit'] ?? null) !== 'audit:read'
    || ($permissions['/api/v1/monitor/audit/export'] ?? null) !== 'audit:read') {
    throw new RuntimeException('Monitor permission contract mismatch');
}

[$readSql] = \App\Core\Sql::prepare('SELECT * FROM system_health WHERE component = ?', ['worker'], 'tenant');
[$writeSql] = \App\Core\Sql::prepare('UPDATE system_health SET status = ? WHERE component = ?', ['healthy', 'worker'], 'tenant');
if (!str_contains($readSql, 'FROM v_system_health') || !str_contains($writeSql, 'UPDATE w_system_health')) {
    throw new RuntimeException('System health tenant view rewrite mismatch');
}

$viewSql = \App\Core\Views::statements();
if (!in_array("CREATE OR REPLACE VIEW v_system_health AS SELECT * FROM system_health WHERE tenant_id = app_tenant() OR (tenant_id IS NULL AND component = 'worker')", $viewSql, true)
    || !in_array('CREATE OR REPLACE VIEW w_system_health AS SELECT * FROM system_health WHERE tenant_id = app_tenant() WITH CASCADED CHECK OPTION', $viewSql, true)) {
    throw new RuntimeException('System health view isolation contract mismatch');
}

echo "Monitor route and tenant-view contracts passed\n";
