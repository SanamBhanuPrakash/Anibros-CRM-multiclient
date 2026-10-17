<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Router;
use App\Core\Sql;
use App\Modules\Outbound\Catalog;
use App\Modules\Outbound\OutboundProcessor;
use App\Modules\Outbound\SafeHttp;

\App\Routes::registerAll();
$workerHandlers = new ReflectionMethod(\App\Core\Worker::class, 'handlers');
$workerHandlers->setAccessible(true);
$registeredHandlers = $workerHandlers->invoke(null);
foreach (['webhook.deliver', 'message.send'] as $jobType) {
    if (!isset($registeredHandlers[$jobType])) throw new RuntimeException('Missing worker handler: ' . $jobType);
}
foreach ([\App\Modules\Outbound\OutboundService::class, \App\Modules\Messaging\MessagingService::class, \App\Modules\Messaging\WhatsAppClient::class] as $class) {
    if (!class_exists($class)) throw new RuntimeException('Could not load migrated module: ' . $class);
}
$routes = array_values(array_filter(
    Router::routes(),
    static fn(array $route): bool => str_starts_with($route['fullPath'], '/api/v1/webhooks')
        || str_starts_with($route['fullPath'], '/api/v1/messaging')
));
$paths = array_column($routes, 'fullPath');
foreach ([
    '/api/v1/webhooks/events',
    '/api/v1/webhooks',
    '/api/v1/webhooks/deliveries',
    '/api/v1/webhooks/deliveries/{id}/retry',
    '/api/v1/webhooks/{id}',
    '/api/v1/webhooks/{id}/rotate-secret',
    '/api/v1/webhooks/{id}/test',
    '/api/v1/messaging/send',
    '/api/v1/messaging/whatsapp',
] as $path) {
    if (!in_array($path, $paths, true)) throw new RuntimeException('Missing outbound/messaging route: ' . $path);
}

$createRoute = null;
$updateRoute = null;
foreach ($routes as $route) {
    if ($route['fullPath'] === '/api/v1/webhooks' && $route['method'] === 'POST') $createRoute = $route;
    if ($route['fullPath'] === '/api/v1/webhooks/{id}' && $route['method'] === 'PATCH') $updateRoute = $route;
}
if (!$createRoute || !$updateRoute) throw new RuntimeException('Webhook create/update route definitions are incomplete');
$createRoute['body']->parseOrFail(['name' => 'Contract webhook', 'url' => 'https://example.com/hooks', 'events' => ['lead.created']], 'body');
$updateRoute['body']->parseOrFail(['status' => 'paused'], 'body');

foreach (array_keys(Catalog::EVENTS) as $event) {
    if (!isset(\App\Core\Events::CATALOG[$event])) throw new RuntimeException('Outbound event is not emitted by the PHP CRM: ' . $event);
}

[$readSql] = Sql::prepare('SELECT id FROM outbound_endpoints WHERE tenant_id = ?', ['tenant'], 'tenant');
[$insertSql] = Sql::prepare('INSERT INTO outbound_deliveries (id, tenant_id) VALUES (?, ?)', ['delivery', 'tenant'], 'tenant');
[$accountSql] = Sql::prepare("SELECT status FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", ['tenant'], 'tenant');
if (!str_contains($readSql, 'FROM v_outbound_endpoints')
    || !str_contains($insertSql, 'INSERT INTO v_outbound_deliveries')
    || !str_contains($accountSql, 'FROM v_messaging_accounts')) {
    throw new RuntimeException('Outbound/messaging tenant-view SQL contract mismatch: ' . json_encode([$readSql, $insertSql, $accountSql]));
}

$secret = 'test-webhook-signing-secret';
$body = '{"id":"delivery","event":"lead.created"}';
$timestamp = '1712345678';
$expected = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
if (OutboundProcessor::signature($secret, $body, $timestamp) !== $expected) throw new RuntimeException('Outbound webhook signature contract mismatch');

if (SafeHttp::isPublicIp('127.0.0.1') || SafeHttp::isPublicIp('10.0.0.1') || SafeHttp::isPublicIp('169.254.169.254') || SafeHttp::isPublicIp('::1') || SafeHttp::isPublicIp('::ffff:127.0.0.1')) {
    throw new RuntimeException('Outbound URL validation accepted a private/reserved address');
}
if (!SafeHttp::isPublicIp('1.1.1.1') || !SafeHttp::isPublicIp('2606:4700:4700::1111')) {
    throw new RuntimeException('Outbound URL validation rejected a public address');
}

$encrypted = Crypto::encrypt($secret, Catalog::aad('endpoint-contract'));
if (Crypto::decrypt($encrypted, Catalog::aad('endpoint-contract')) !== $secret) throw new RuntimeException('Webhook secret encryption contract mismatch');
$wrongAadFailed = false;
try {
    Crypto::decrypt($encrypted, Catalog::aad('other-endpoint'));
} catch (RuntimeException) {
    $wrongAadFailed = true;
}
if (!$wrongAadFailed) throw new RuntimeException('Webhook secret was not bound to its endpoint');

echo 'Outbound webhook and messaging contracts passed (' . count(Router::routes()) . " API routes registered)\n";
