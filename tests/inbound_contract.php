<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\CurlGraphTransport;
use App\Core\Sql;
use App\Modules\Inbound\BadPayload;
use App\Modules\Inbound\InboundRoutes;
use App\Modules\Inbound\Providers;
use App\Modules\Inbound\Receiver;
use App\Modules\Inbound\InboundProcessor;

InboundRoutes::register();
$routes = array_values(array_filter(
    \App\Core\Router::routes(),
    static fn(array $route): bool => str_starts_with($route['fullPath'], '/api/v1/lead-sources')
));
$paths = array_column($routes, 'fullPath');
$expected = [
    '/api/v1/lead-sources',
    '/api/v1/lead-sources',
    '/api/v1/lead-sources/events',
    '/api/v1/lead-sources/{id}',
    '/api/v1/lead-sources/{id}',
    '/api/v1/lead-sources/{id}/rotate-secret',
    '/api/v1/lead-sources/{id}/test',
    '/api/v1/lead-sources/events/{id}/retry',
    '/api/v1/lead-sources/events/{id}/ignore',
];
if ($paths !== $expected) throw new RuntimeException('Inbound route contract mismatch: ' . json_encode($paths));

[$endpointSql] = Sql::prepare('SELECT * FROM inbound_endpoints WHERE id = ?', ['endpoint'], 'tenant');
[$eventSql] = Sql::prepare('INSERT INTO inbound_events (id, tenant_id) VALUES (?, ?)', ['event', 'tenant'], 'tenant');
[$updateSql] = Sql::prepare('UPDATE inbound_events SET status = ? WHERE id = ?', ['created', 'event'], 'tenant');
[$ignoreSql] = Sql::prepare('INSERT IGNORE INTO inbound_events (id, tenant_id) VALUES (?, ?)', ['event', 'tenant'], 'tenant');
if (!str_contains($endpointSql, 'FROM v_inbound_endpoints')
    || !str_contains($eventSql, 'INSERT INTO v_inbound_events')
    || !str_contains($updateSql, 'UPDATE v_inbound_events')
    || !str_contains($ignoreSql, 'INSERT IGNORE INTO v_inbound_events')) {
    throw new RuntimeException('Inbound tenant-scoped SQL rewrite contract mismatch');
}

$secret = 'test-inbound-secret';
$raw = '{"lead_id":"provider-1"}';
$metaSig = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if (!Providers::verifyMeta($raw, $metaSig, $secret) || Providers::verifyMeta($raw . ' ', $metaSig, $secret)) {
    throw new RuntimeException('Meta raw-body HMAC contract mismatch');
}
$now = time();
$genericHash = hash_hmac('sha256', $now . '.' . $raw, $secret);
$genericSig = 't=' . $now . ',v1=' . $genericHash;
$genericSigReordered = 'v1=' . $genericHash . ',t=' . $now;
if (!Providers::verifyGeneric($raw, $genericSig, $secret, $now)
    || !Providers::verifyGeneric($raw, $genericSigReordered, $secret, $now)
    || Providers::verifyGeneric($raw, $genericSig, $secret, $now + 301)) {
    throw new RuntimeException('Generic signed timestamp/replay contract mismatch');
}
if (!Crypto::safeEqual('same', 'same') || Crypto::safeEqual('same', 'different')) {
    throw new RuntimeException('Constant-time key comparison contract mismatch');
}

$google = Providers::parseBody('{"google_key":"do-not-persist","lead_id":"g-1","user_column_data":[{"column_id":"EMAIL","string_value":"Person@example.com"}]}', 'application/json');
$googleEvents = Providers::extract('google', $google, '{}', []);
if (count($googleEvents) !== 1 || $googleEvents[0]['eventId'] !== 'g-1' || isset($googleEvents[0]['payload']['google_key'])) {
    throw new RuntimeException('Google event authentication-key redaction contract mismatch');
}
$googleLead = Providers::normalize('google', $googleEvents[0]['payload'], [], false);
if ($googleLead['email'] !== 'person@example.com' || $googleLead['source'] !== 'google_ads') {
    throw new RuntimeException('Google Ads lead normalization contract mismatch');
}
$googleAttribution = Providers::normalize('google', [
    'lead_id' => 'g-attribution',
    'campaign_id' => 'campaign-42',
    'campaign_name' => 'Spring homes',
    'gcl_id' => 'click-123',
    'user_column_data' => [['column_id' => 'EMAIL', 'string_value' => 'buyer@example.com']],
], [], false);
if ($googleAttribution['campaign'] !== 'Spring homes'
    || ($googleAttribution['details']['gclid'] ?? null) !== 'click-123'
    || in_array(['label' => 'Gcl Id', 'value' => 'click-123'], $googleAttribution['answers'], true)) {
    throw new RuntimeException('Google campaign and click attribution did not match the source contract');
}

$chatLead = Providers::normalize('website', [
    'visitor' => ['name' => 'Chat Visitor', 'email' => 'chat@example.com'],
    'conversation' => [
        ['role' => 'assistant', 'content' => 'Welcome'],
        ['role' => 'user', 'content' => 'I need help'],
        ['from' => 'support', 'text' => 'How can we help?'],
        ['speaker' => 'customer', 'value' => 'Please call me'],
        ['is_bot' => true, 'message' => 'An agent will follow up'],
        'A visitor message',
        'A bot response',
    ],
], [], false);
$expectedConversation = [
    ['who' => 'bot', 'text' => 'Welcome'],
    ['who' => 'visitor', 'text' => 'I need help'],
    ['who' => 'bot', 'text' => 'How can we help?'],
    ['who' => 'visitor', 'text' => 'Please call me'],
    ['who' => 'bot', 'text' => 'An agent will follow up'],
    ['who' => 'bot', 'text' => 'A visitor message'],
    ['who' => 'visitor', 'text' => 'A bot response'],
];
if ($chatLead['conversation'] !== $expectedConversation) {
    throw new RuntimeException('Chatbot transcript speaker and message normalization contract mismatch');
}

$metaBody = ['object' => 'page', 'entry' => [['id' => 'page-1', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'meta-1', 'form_id' => 'form-1']]]]]];
$metaEvents = Providers::extract('meta', $metaBody, '{}', []);
if (count($metaEvents) !== 1 || $metaEvents[0]['eventId'] !== 'meta-1' || $metaEvents[0]['payload']['pageId'] !== 'page-1') {
    throw new RuntimeException('Meta batched event extraction contract mismatch');
}
$metaLead = Providers::normalize('meta', ['leadgen' => ['leadgen_id' => 'meta-1'], 'lead' => ['field_data' => [
    ['name' => 'full_name', 'values' => ['Taylor Example']],
    ['name' => 'email', 'values' => ['Taylor@example.com']],
    ['name' => 'budget', 'values' => ['85 lakh']],
    ['name' => 'preferred_location', 'values' => ['Whitefield']],
]]], ['tags' => ['website-lead']], false);
if ($metaLead['firstName'] !== 'Taylor' || $metaLead['lastName'] !== 'Example' || $metaLead['budgetMax'] !== 8500000.0 || $metaLead['locations'] !== ['Whitefield']) {
    throw new RuntimeException('Meta lead-field normalization contract mismatch');
}

if (!Providers::honeypot(['_hp' => 'bot']) || Providers::honeypot(['_hp' => '', 'name' => 'Human'])) {
    throw new RuntimeException('Website honeypot contract mismatch');
}
$websiteEvents = Providers::extract('website', ['_hp' => '', 'website_url' => '', 'email' => 'a@example.com'], '{}', []);
if (count($websiteEvents) !== 1 || isset($websiteEvents[0]['payload']['_hp']) || isset($websiteEvents[0]['payload']['website_url'])) {
    throw new RuntimeException('Website honeypot redaction contract mismatch');
}

$capture = new ReflectionMethod(Receiver::class, 'captureScript');
$script = $capture->invoke(null, 'https://crm.example/hooks/123');
if (!str_contains($script, 'https://crm.example/hooks/123') || str_contains($script, '__ENDPOINT__') || !str_contains($script, 'AnibrosCRM')) {
    throw new RuntimeException('Website capture script generation contract mismatch');
}

$graphRequest = (new ReflectionMethod(InboundProcessor::class, 'metaGraphRequest'))->invoke(null, '1234567890', 'secret-page-token', 'meta-app-secret');
if (str_contains($graphRequest['url'], 'secret-page-token') || !str_contains(implode("\n", $graphRequest['headers']), 'Authorization: Bearer secret-page-token') || !str_contains($graphRequest['url'], 'appsecret_proof=')) {
    throw new RuntimeException('Meta Graph token transport and app-secret proof contract mismatch');
}

try {
    (new CurlGraphTransport())->request('GET', 'https://graph.facebook.com.evil.invalid/v23.0/1234567890', [], null, 1, 1024);
    throw new RuntimeException('Graph transport accepted a lookalike provider hostname');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'official HTTPS endpoint')) throw $e;
}

try {
    Providers::parseBody('{broken', 'application/json');
    throw new RuntimeException('Malformed payload was not rejected');
} catch (BadPayload) {
}
if (Providers::parseBody('{}', 'application/json') !== []) {
    throw new RuntimeException('Empty JSON object body was not accepted');
}
try {
    Providers::parseBody('[]', 'application/json');
    throw new RuntimeException('JSON array body was not rejected');
} catch (BadPayload) {
}

echo "Inbound provider, signature, route, tenant-scope and capture contracts passed\n";
