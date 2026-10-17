<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\GraphHttp;
use App\Core\GraphResponse;
use App\Core\GraphTransport;
use App\Core\Ids;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Tx;
use App\Core\Mail\Transport;
use App\Modules\Inbound\Receiver;
use App\Modules\Inbound\InboundProcessor;
use App\Modules\Inbound\InboundService;
use App\Modules\Messaging\MessagingService;
use App\Modules\Messaging\WhatsAppClient;
use App\Modules\Outbound\Catalog;
use App\Modules\Outbound\OutboundProcessor;
use App\Modules\Outbound\OutboundService;
use App\Modules\Agency\AgencyService;
use App\Modules\Agency\XlsxWriter;
use App\Modules\Contacts\ContactsService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class WorkflowGraphTransport implements GraphTransport
{
    /** @var list<GraphResponse> */
    public array $responses = [];
    /** @var list<array{method:string,url:string,headers:array<int,string>,body:?string,timeout:int,maxBytes:int}> */
    public array $requests = [];

    public function queue(int $status, array $payload): void
    {
        $this->responses[] = new GraphResponse($status, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, int $maxResponseBytes): GraphResponse
    {
        $this->requests[] = [
            'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body,
            'timeout' => $timeoutSeconds, 'maxBytes' => $maxResponseBytes,
        ];
        $response = array_shift($this->responses);
        if (!$response) throw new RuntimeException('Mock Graph API response queue is empty');
        return $response;
    }
}

function unzipForAssertions(string $archive): array
{
    $entries = [];
    $offset = 0;
    while (substr($archive, $offset, 4) === "PK\x03\x04") {
        $method = unpack('v', substr($archive, $offset + 8, 2))[1];
        $expectedCrc = unpack('V', substr($archive, $offset + 14, 4))[1];
        $compressedSize = unpack('V', substr($archive, $offset + 18, 4))[1];
        $size = unpack('V', substr($archive, $offset + 22, 4))[1];
        $nameLength = unpack('v', substr($archive, $offset + 26, 2))[1];
        $extraLength = unpack('v', substr($archive, $offset + 28, 2))[1];
        $name = substr($archive, $offset + 30, $nameLength);
        $start = $offset + 30 + $nameLength + $extraLength;
        $compressed = substr($archive, $start, $compressedSize);
        $data = $method === 8 ? gzinflate($compressed) : ($method === 0 ? $compressed : false);
        check(is_string($data) && strlen($data) === $size, 'Excel archive contains an invalid compressed entry');
        check((int) sprintf('%u', crc32($data)) === $expectedCrc, 'Excel archive entry failed CRC validation');
        $entries[$name] = $data;
        $offset = $start + $compressedSize;
    }
    check($entries !== [], 'Excel output is not a valid ZIP archive');
    return $entries;
}

function seedUserAndTenant(PDO $pdo, string $userId, string $orgId, string $tenantId, string $label): void
{
    $st = $pdo->prepare('INSERT INTO users (id,email,password_hash,name,email_verified) VALUES (?,?,?,?,1)');
    $st->execute([$userId, $label . '@example.invalid', password_hash('integration-test-only', PASSWORD_DEFAULT), 'Workflow Test ' . $label]);
    $st = $pdo->prepare('INSERT INTO organisations (id,name,slug,owner_id) VALUES (?,?,?,?)');
    $st->execute([$orgId, 'Workflow Test ' . $label, 'workflow-test-' . strtolower($label), $userId]);
    $st = $pdo->prepare('INSERT INTO tenants (id,organisation_id,name,slug,settings,kind) VALUES (?,?,?,?,?,?)');
    $st->execute([$tenantId, $orgId, 'Workflow Test ' . $label, 'workflow-test-' . strtolower($label), '{}', 'client']);
    $st = $pdo->prepare("INSERT INTO tenant_memberships (id,tenant_id,user_id,role,status,is_active) VALUES (?,?,?,'tenant_owner','active',1)");
    $st->execute([Ids::uuid(), $tenantId, $userId]);
    $hqId = Ids::uuid();
    $st = $pdo->prepare("INSERT INTO tenants (id,organisation_id,name,slug,settings,kind) VALUES (?,?,?,?,'{}','agency_hq')");
    $st->execute([$hqId, $orgId, 'Workflow Test Agency ' . $label, 'workflow-test-hq-' . strtolower($label)]);
    $st = $pdo->prepare("INSERT INTO tenant_memberships (id,tenant_id,user_id,role,status,is_active) VALUES (?,?,?,'tenant_owner','active',1)");
    $st->execute([Ids::uuid(), $hqId, $userId]);
}

function seedPipeline(PDO $pdo, string $tenantId): array
{
    $pipelineId = Ids::uuid();
    $stageId = Ids::uuid();
    $st = $pdo->prepare('INSERT INTO pipelines (id,tenant_id,name,is_default) VALUES (?,?,?,1)');
    $st->execute([$pipelineId, $tenantId, 'Migration Test Pipeline']);
    $st = $pdo->prepare("INSERT INTO pipeline_stages (id,pipeline_id,tenant_id,name,order_index,stage_key,maps_to_status,probability) VALUES (?,?,?,'New',0,'new','open',10)");
    $st->execute([$stageId, $pipelineId, $tenantId]);
    return [$pipelineId, $stageId];
}

$database = (string) \App\Core\Config::get('db.name');
if (!preg_match('/^anibros_crm_feature_tests_\d{8}(?:_[a-z0-9]+)?$/', $database)) {
    throw new RuntimeException('Refusing to run destructive-capable fixtures outside a dedicated feature-test database');
}
if ((string) \App\Core\Config::get('env') !== 'development') throw new RuntimeException('Database workflow tests require development mode');
\App\Core\Config::set('outbound_allow_private', true);
$pdo = Db::pdo();
$initialUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$initialTenants = (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
check($initialUsers === 0 && $initialTenants === 0, 'Dedicated feature-test database is not empty; refusing to alter existing test data');

$userA = Ids::uuid();
$orgA = Ids::uuid();
$tenantA = Ids::uuid();
$userB = Ids::uuid();
$orgB = Ids::uuid();
$tenantB = Ids::uuid();
seedUserAndTenant($pdo, $userA, $orgA, $tenantA, 'alpha');
seedUserAndTenant($pdo, $userB, $orgB, $tenantB, 'beta');
[$pipelineA, $stageA] = seedPipeline($pdo, $tenantA);
seedPipeline($pdo, $tenantB);
$actorA = new Actor($tenantA, $userA, 'tenant_owner');
$actorB = new Actor($tenantB, $userB, 'tenant_owner');
$workerMailTransport = new class implements Transport {
    public array $sent = [];
    public function name(): string { return 'workflow-test'; }
    public function send(array $mail): array { $this->sent[] = $mail; return ['messageId' => 'workflow-test-mail-' . count($this->sent)]; }
};
Mailer::setTransport($workerMailTransport);

$inboundCreated = InboundService::create($actorA, new Request('POST', '/api/v1/lead-sources'), [
    'provider' => 'generic', 'name' => 'Workflow inbound source',
    'config' => ['pipelineId' => $pipelineA, 'stageId' => $stageA, 'ownerId' => $userA],
]);
$endpoint = InboundService::lookupPublic($inboundCreated['endpoint']['id']);
check($endpoint !== null, 'Inbound source could not be looked up');
$captured = [
    'eventId' => 'db-workflow-lead-' . Ids::hex(8),
    'payload' => ['name' => 'Workflow Inbound Lead', 'email' => 'inbound-' . Ids::hex(6) . '@example.invalid', 'phone' => '+15555550123', 'campaign' => 'DB workflow test'],
];
$rawInbound = Json::enc($captured['payload']);
$inboundTimestamp = time();
$inboundSignature = 't=' . $inboundTimestamp . ',v1=' . hash_hmac('sha256', $inboundTimestamp . '.' . $rawInbound, $inboundCreated['secret']);
$inboundRequest = new Request('POST', '/hooks/' . $endpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($rawInbound),
    'x-anibros-signature' => $inboundSignature,
    'x-event-id' => $captured['eventId'],
], rawBody: $rawInbound, ip: '192.0.2.111');
$accepted = new Response();
Receiver::handle($inboundRequest, $accepted);
check($accepted->status === 200 && (Json::dec($accepted->body, [])['accepted'] ?? null) === 1, 'Signed inbound receiver did not accept the event');
$duplicate = new Response();
Receiver::handle($inboundRequest, $duplicate);
check($duplicate->status === 200, 'Inbound receiver did not acknowledge provider retry');
$rejected = new Response();
Receiver::handle(new Request('POST', '/hooks/' . $endpoint['id'], headers: [
    'content-type' => 'application/json',
    'x-anibros-signature' => 't=' . $inboundTimestamp . ',v1=' . str_repeat('0', 64),
    'x-event-id' => 'forged-' . Ids::hex(8),
], rawBody: $rawInbound, ip: '192.0.2.112'), $rejected);
check($rejected->status === 401, 'Inbound receiver accepted an invalid signature');
$inboundEventId = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->val('SELECT id FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?', [$endpoint['id'], $captured['eventId']]));
check(is_string($inboundEventId) && Ids::isUuid($inboundEventId), 'Inbound event was not persisted');
check(Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val('SELECT COUNT(*) FROM inbound_events WHERE endpoint_id = ?', [$endpoint['id']])) === 1, 'Inbound idempotency persisted a duplicate');

$secretFile = tempnam(sys_get_temp_dir(), 'anibros-webhook-secret-');
if ($secretFile === false) throw new RuntimeException('Unable to create private webhook receiver secret file');
$server = null;
try {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($listener === false) throw new RuntimeException('Cannot allocate local webhook receiver port: ' . $errstr);
    $socket = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int) substr(strrchr((string) $socket, ':'), 1);
    putenv('ANIBROS_TEST_WEBHOOK_SECRET_FILE=' . $secretFile);
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/webhook_receiver.php'];
    $pipes = [];
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a']], $pipes, dirname(__DIR__));
    putenv('ANIBROS_TEST_WEBHOOK_SECRET_FILE');
    if (!is_resource($server)) throw new RuntimeException('Unable to start local webhook receiver');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $connectError, $connectMessage, 0.1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    check($ready, 'Local webhook receiver did not start');

    $url = 'http://127.0.0.1:' . $port . '/fail';
    $webhookCreated = OutboundService::create($actorA, ['name' => 'Workflow outbound webhook', 'url' => $url, 'events' => ['lead.created']]);
    file_put_contents($secretFile, $webhookCreated['secret'], LOCK_EX);
    $webhookId = $webhookCreated['webhook']['id'];
    $inboundJobs = Jobs::run([
        'inbound.process' => [InboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($inboundJobs['succeeded'] >= 1, 'Inbound processor worker did not complete successfully');
    $inboundResult = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, lead_id FROM inbound_events WHERE id = ?', [$inboundEventId]));
    check(($inboundResult['status'] ?? '') === 'created' && !empty($inboundResult['lead_id']), 'Inbound worker did not create the lead');
    $lead = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT l.id, l.tenant_id, c.id AS contact_id, c.email FROM leads l JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ?', [$inboundResult['lead_id']]));
    check(($lead['tenant_id'] ?? '') === $tenantA && ($lead['email'] ?? '') === $captured['payload']['email'], 'Inbound lead was not created in the endpoint tenant');
    $dispatched = OutboundProcessor::dispatchEvents(500);
    check($dispatched > 0, 'Outbound event consumer did not advance');
    $delivery = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT id, status FROM outbound_deliveries WHERE endpoint_id = ? AND event_type = ?', [$webhookId, 'lead.created']));
    check(($delivery['status'] ?? '') === 'pending', 'Outbound fan-out did not create a pending delivery');
    $failedRun = Jobs::run([
        'webhook.deliver' => [OutboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($failedRun['retried'] === 1, 'Outbound worker did not schedule a retry for HTTP 503');
    $failedDelivery = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, attempts, response_status FROM outbound_deliveries WHERE id = ?', [$delivery['id']]));
    check(($failedDelivery['status'] ?? '') === 'failed' && (int) $failedDelivery['attempts'] === 1 && (int) $failedDelivery['response_status'] === 503, 'Outbound failure outcome was not persisted');
    OutboundService::update($actorA, $webhookId, ['url' => 'http://127.0.0.1:' . $port . '/ok']);
    $jobId = Db::system()->read(static fn(Tx $tx) => $tx->val("SELECT id FROM jobs WHERE type = 'webhook.deliver' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.deliveryId')) = ? AND status = 'queued'", [$tenantA, $delivery['id']]));
    check(is_string($jobId) && $jobId !== '', 'Outbound retry job was not durably queued');
    Db::system()->tx(static fn(Tx $tx) => $tx->run('UPDATE jobs SET run_at = NOW(3) WHERE id = ?', [$jobId]));
    $successRun = Jobs::run([
        'webhook.deliver' => [OutboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($successRun['succeeded'] === 1, 'Outbound retry did not complete successfully');
    $delivered = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, attempts, response_status FROM outbound_deliveries WHERE id = ?', [$delivery['id']]));
    check(($delivered['status'] ?? '') === 'delivered' && (int) $delivered['attempts'] === 2 && (int) $delivered['response_status'] === 200, 'Outbound delivery retry did not reach a delivered state');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($secretFile);
}

$outboundA = OutboundService::list($actorA);
check(count($outboundA) === 1 && $outboundA[0]['id'] === $webhookId, 'Webhook list crossed tenant boundary or missed its own endpoint');
$accountBId = Ids::uuid();
$pdo->prepare("INSERT INTO messaging_accounts (id,tenant_id,channel,phone_number_id,token_enc,token_hint,status) VALUES (?,?,'whatsapp','123456789',?, 'mock', 'active')")
    ->execute([$accountBId, $tenantB, Crypto::encrypt('synthetic-test-token-only', 'messaging:' . $tenantB . ':whatsapp')]);
check(MessagingService::whatsapp($actorA)['connected'] === false, 'Messaging account read crossed tenant boundary');
check((MessagingService::whatsapp($actorB)['phoneNumberId'] ?? '') === '123456789', 'Messaging account tenant fixture was not isolated');

$whatsappTransport = new WorkflowGraphTransport();
$whatsappTransport->queue(401, ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]);
$whatsappTransport->queue(200, ['display_phone_number' => '+1 555-555-0100', 'verified_name' => 'Mock Business']);
$whatsappTransport->queue(429, ['error' => ['code' => 130429, 'message' => 'Rate limited']]);
$whatsappTransport->queue(200, ['messages' => [['id' => 'wamid.mock-success-1']]]);
$whatsappTransport->queue(401, ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]);
GraphHttp::setTransport($whatsappTransport);
try {
    $invalidConnectRejected = false;
    try {
        MessagingService::connectWhatsapp($actorA, ['phoneNumberId' => 'mock-phone-1', 'accessToken' => 'invalid-token']);
    } catch (AppError $e) {
        $invalidConnectRejected = $e->status === 400;
    }
    check($invalidConnectRejected && MessagingService::whatsapp($actorA)['connected'] === false, 'WhatsApp connection accepted an invalid provider token or persisted it');
    $waToken = 'mock-whatsapp-access-token-' . Ids::hex(4);
    $connected = MessagingService::connectWhatsapp($actorA, ['phoneNumberId' => 'mock-phone-1', 'accessToken' => $waToken]);
    check(($connected['connected'] ?? false) && ($connected['displayPhone'] ?? '') === '+1 555-555-0100', 'WhatsApp account authentication did not persist a connected account');
    $storedWa = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one("SELECT token_enc, token_hint FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", [$tenantA]));
    check(is_array($storedWa) && !str_contains($storedWa['token_enc'], $waToken) && $storedWa['token_hint'] === substr($waToken, -4), 'WhatsApp access token was not encrypted at rest');
    check((MessagingService::whatsapp($actorB)['phoneNumberId'] ?? '') === '123456789', 'Connecting WhatsApp changed another tenant’s account');

    $waMessage = MessagingService::send($actorA, $lead['id'], ['channel' => 'whatsapp', 'body' => 'Provider mock message']);
    $waFirstRun = Jobs::run([
        'message.send' => [MessagingService::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($waFirstRun['retried'] === 1, 'WhatsApp rate limit did not schedule a durable retry');
    $waJobId = Db::system()->read(static fn(Tx $tx) => $tx->val("SELECT id FROM jobs WHERE type = 'message.send' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.activityId')) = ? AND status = 'queued'", [$tenantA, $waMessage['activityId']]));
    check(is_string($waJobId) && $waJobId !== '', 'WhatsApp retry was not persisted in the job queue');
    Db::system()->tx(static fn(Tx $tx) => $tx->run('UPDATE jobs SET run_at = NOW(3) WHERE id = ?', [$waJobId]));
    $waRetry = Jobs::run([
        'message.send' => [MessagingService::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($waRetry['succeeded'] >= 1, 'WhatsApp retry did not complete successfully');
    $waActivity = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT metadata FROM activities WHERE id = ?', [$waMessage['activityId']]));
    $waMetadata = Json::dec($waActivity['metadata'] ?? null, []);
    check(($waMetadata['delivery'] ?? '') === 'sent' && ($waMetadata['providerId'] ?? '') === 'wamid.mock-success-1', 'WhatsApp provider delivery result was not persisted');
    $waPostRequests = array_values(array_filter($whatsappTransport->requests, static fn(array $request): bool => $request['method'] === 'POST'));
    $waPayload = Json::dec($waPostRequests[0]['body'] ?? null, []);
    check(count($waPostRequests) === 2
        && in_array('Authorization: Bearer ' . $waToken, $waPostRequests[0]['headers'], true)
        && str_ends_with($waPostRequests[0]['url'], '/mock-phone-1/messages')
        && ($waPayload['messaging_product'] ?? '') === 'whatsapp'
        && ($waPayload['type'] ?? '') === 'text'
        && ($waPayload['text']['body'] ?? '') === 'Provider mock message',
        'WhatsApp provider request did not preserve authenticated message payload and retry behavior');

    $waPermanent = MessagingService::send($actorA, $lead['id'], ['channel' => 'whatsapp', 'body' => 'Token failure case']);
    $waPermanentRun = Jobs::run([
        'message.send' => [MessagingService::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($waPermanentRun['succeeded'] >= 1, 'WhatsApp permanent provider failure did not finish processing');
    $waPermanentActivity = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT metadata FROM activities WHERE id = ?', [$waPermanent['activityId']]));
    $waPermanentMetadata = Json::dec($waPermanentActivity['metadata'] ?? null, []);
    check(($waPermanentMetadata['delivery'] ?? '') === 'failed'
        && str_contains((string) ($waPermanentMetadata['error'] ?? ''), 'access token')
        && Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM jobs WHERE type = 'message.send' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.activityId')) = ? AND status = 'queued'", [$tenantA, $waPermanent['activityId']])) === 0,
        'WhatsApp invalid-token error was not recorded as a permanent delivery failure');
    $waAccount = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one("SELECT last_error FROM messaging_accounts WHERE tenant_id = ? AND channel = 'whatsapp'", [$tenantA]));
    check(str_contains((string) ($waAccount['last_error'] ?? ''), 'access token'), 'WhatsApp account did not surface the permanent authentication failure');
    $foreignLeadRejected = false;
    try {
        MessagingService::send($actorB, $lead['id'], ['channel' => 'whatsapp', 'body' => 'Cross-tenant attempt']);
    } catch (AppError $e) {
        $foreignLeadRejected = $e->status === 404;
    }
    check($foreignLeadRejected, 'WhatsApp messaging accepted another tenant’s lead id');
} finally {
    GraphHttp::setTransport(null);
}

$emailTransport = new class implements Transport {
    public array $sent = [];
    public function name(): string { return 'test'; }
    public function send(array $mail): array { $this->sent[] = $mail; return ['messageId' => 'db-test-message-id']; }
};
Mailer::setTransport($emailTransport);
try {
    $message = MessagingService::send($actorA, $lead['id'], [
        'channel' => 'email', 'subject' => 'Database workflow test', 'body' => 'This email is sent only to the local transport stub.',
    ]);
    $messageRun = Jobs::run([
        'message.send' => [MessagingService::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($messageRun['succeeded'] === 1 && count($emailTransport->sent) === 1, 'Email worker did not deliver through the local test transport');
    $activity = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT metadata FROM activities WHERE id = ?', [$message['activityId']]));
    $metadata = Json::dec($activity['metadata'] ?? null, []);
    check(($metadata['delivery'] ?? '') === 'sent' && ($metadata['providerId'] ?? '') === 'db-test-message-id', 'Message delivery state was not persisted');

    Db::tenant($actorA)->tx(static function (Tx $tx) use ($lead, $tenantA): void {
        $tx->insert('communication_consents', [
            'tenant_id' => $tenantA, 'contact_id' => $lead['contact_id'], 'channel' => 'email',
            'status' => 'revoked', 'source' => 'integration_test', 'legal_basis' => 'consent',
        ]);
    });
    $optOutRejected = false;
    try {
        MessagingService::send($actorA, $lead['id'], ['channel' => 'email', 'subject' => 'Should not send', 'body' => 'This must be rejected.']);
    } catch (AppError $e) {
        $optOutRejected = $e->status === 409;
    }
    check($optOutRejected, 'Messaging did not enforce the tenant contact opt-out');
    $emailActivityCount = Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM activities WHERE lead_id = ? AND type = 'email'", [$lead['id']]));
    check($emailActivityCount === 1, 'Rejected opt-out message left an activity or queue record behind');
} finally {
    Mailer::setTransport(null);
}

$leadCsv = AgencyService::exportFile($actorA, ['clientId' => $tenantA, 'format' => 'csv', 'dataset' => 'leads']);
check($leadCsv['rows'] === 1 && str_contains($leadCsv['body'], $captured['payload']['email']), 'Agency lead CSV did not include its filtered lead');
$activityCsv = AgencyService::exportFile($actorA, ['clientId' => $tenantA, 'format' => 'csv', 'dataset' => 'activities']);
check($activityCsv['rows'] >= 1 && str_contains($activityCsv['body'], $lead['id']), 'Agency activity CSV did not include the lead timeline');
$workbook = AgencyService::exportFile($actorA, ['clientId' => $tenantA, 'format' => 'xlsx']);
$workbookEntries = unzipForAssertions($workbook['body']);
$workbookXml = $workbookEntries['xl/workbook.xml'] ?? '';
check($workbook['rows'] === 1 && str_contains($workbookXml, 'Activity log') && str_contains($workbookXml, 'Team activity') && str_contains($workbookXml, 'Clients'), 'Agency Excel export is missing its expected worksheets');
check(str_contains($workbookEntries['xl/worksheets/sheet1.xml'] ?? '', $captured['payload']['email']), 'Agency Excel lead sheet omitted the lead');
$injectionWorkbook = XlsxWriter::build([['name' => 'Safe', 'header' => ['Text'], 'rows' => [['=HYPERLINK("https://example.invalid")']]]], 'UTC');
$injectionSheet = unzipForAssertions($injectionWorkbook)['xl/worksheets/sheet1.xml'] ?? '';
check(str_contains($injectionSheet, 't="inlineStr"') && !str_contains($injectionSheet, '<f>'), 'Excel export may interpret untrusted text as a formula');

$reportDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$agencyReport = AgencyService::report($actorA, [
    'from' => $reportDate, 'to' => $reportDate, 'includeTest' => 'false', 'clientId' => $tenantA,
]);
check($agencyReport['range'] === ['from' => $reportDate, 'to' => $reportDate], 'Agency report did not preserve its requested date range');
check($agencyReport['includesTestLeads'] === false, 'Agency report treated the string false as enabled test-lead inclusion');
check($agencyReport['totals']['clients'] === 1 && $agencyReport['rows'][0]['client']['id'] === $tenantA, 'Agency report included a client outside the requested client scope');
check($agencyReport['rows'][0]['leads']['created'] === 1 && $agencyReport['totals']['leads']['created'] === 1, 'Agency report did not use the selected period for lead metrics');
check($agencyReport['rows'][0]['scope'] === 'all' && $agencyReport['bucket'] === 'day' && count($agencyReport['trend']) === 1, 'Agency report omitted scope or aligned daily trend data');
check($agencyReport['rows'][0]['firstReply'] !== null && array_key_exists('deals', $agencyReport['rows'][0]) && array_key_exists('money', $agencyReport['rows'][0]), 'Agency report omitted its report, deals, or money metric fields');
$agencyLeadList = AgencyService::leads($actorA, [
    'clientId' => $tenantA, 'q' => 'DB workflow test', 'status' => ['open'], 'state' => 'open', 'ownerId' => 'me',
    'createdFrom' => $reportDate, 'createdTo' => $reportDate, 'test' => 'exclude', 'page' => 1, 'pageSize' => 25,
    'sort' => 'score', 'order' => 'desc',
]);
check($agencyLeadList['page']['total'] === 1 && $agencyLeadList['byClient'][0]['total'] === 1, 'Agency lead list ignored filters or reported an inexact per-client total');
check($agencyLeadList['items'][0]['id'] === $lead['id'] && $agencyLeadList['items'][0]['touches']['total'] >= 1, 'Agency lead list omitted its filtered lead or timeline touch data');
$claimPool = AgencyService::leads($actorA, ['clientId' => $tenantA, 'pool' => 'true', 'page' => 1, 'pageSize' => 25]);
check($claimPool['page']['total'] === 0, 'Agency claim-pool filter returned an already assigned lead');
$actorTimezone = (string) (Db::asUser($userA)->one('SELECT timezone FROM users WHERE id = ?', [$userA])['timezone'] ?? 'Asia/Kolkata');
$activityDate = (new DateTimeImmutable('now', new DateTimeZone($actorTimezone)))->format('Y-m-d');
$agencyActivity = AgencyService::activity($actorA, ['clientId' => $tenantA, 'from' => $activityDate, 'to' => $activityDate]);
check($agencyActivity['timeZone'] === $actorTimezone && $agencyActivity['totals']['emails'] === 1, 'Agency activity summary did not honor the caller timezone or email totals');

$metaSecret = 'meta-test-app-secret-' . Ids::hex(4);
$metaToken = 'meta-test-page-token-' . Ids::hex(8);
$metaSource = InboundService::create($actorA, new Request('POST', '/api/v1/lead-sources'), [
    'provider' => 'meta', 'name' => 'Mock Meta Lead Ads', 'appSecret' => $metaSecret, 'pageAccessToken' => $metaToken,
    'config' => ['pipelineId' => $pipelineA, 'stageId' => $stageA, 'ownerId' => $userA],
]);
$metaEndpoint = $metaSource['endpoint'];
$metaChallenge = new Response();
Receiver::handle(new Request('GET', '/hooks/' . $metaEndpoint['id'], query: [
    'hub.mode' => 'subscribe', 'hub.verify_token' => $metaEndpoint['verifyToken'], 'hub.challenge' => 'mock-challenge-123',
]), $metaChallenge);
check($metaChallenge->status === 200 && $metaChallenge->body === 'mock-challenge-123', 'Meta webhook verification handshake failed');

$metaLeadId = (string) (time() . random_int(1000, 9999));
$metaEvent = [
    'object' => 'page',
    'entry' => [['id' => 'mock-page-1', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $metaLeadId, 'form_id' => 'mock-form-1']]]]],
];
$metaRaw = Json::enc($metaEvent);
$metaRequest = new Request('POST', '/hooks/' . $metaEndpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($metaRaw),
    'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $metaRaw, $metaSecret),
], rawBody: $metaRaw, ip: '192.0.2.113');
$metaAccepted = new Response();
Receiver::handle($metaRequest, $metaAccepted);
check($metaAccepted->status === 200 && (Json::dec($metaAccepted->body, [])['accepted'] ?? null) === 1, 'Valid Meta signature was not accepted');
$metaDuplicate = new Response();
Receiver::handle($metaRequest, $metaDuplicate);
check($metaDuplicate->status === 200 && (Json::dec($metaDuplicate->body, [])['accepted'] ?? null) === 1, 'Meta duplicate delivery was not acknowledged');
$metaBadSignature = new Response();
Receiver::handle(new Request('POST', '/hooks/' . $metaEndpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($metaRaw),
    'x-hub-signature-256' => 'sha256=' . str_repeat('0', 64),
], rawBody: $metaRaw, ip: '192.0.2.114'), $metaBadSignature);
check($metaBadSignature->status === 401, 'Meta receiver accepted an invalid provider signature');

$metaTransport = new WorkflowGraphTransport();
$metaTransport->queue(503, ['error' => ['code' => 2, 'message' => 'Temporary provider outage']]);
$metaTransport->queue(200, [
    'id' => $metaLeadId,
    'created_time' => '2026-10-17T10:00:00+0000',
    'campaign_name' => 'Mock provider campaign',
    'field_data' => [
        ['name' => 'full_name', 'values' => ['Meta Mock Lead']],
        ['name' => 'email', 'values' => ['meta-' . Ids::hex(4) . '@example.invalid']],
        ['name' => 'phone_number', 'values' => ['+15555550124']],
    ],
]);
GraphHttp::setTransport($metaTransport);
Mailer::setTransport($workerMailTransport);
try {
    $metaEventId = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->val('SELECT id FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?', [$metaEndpoint['id'], $metaLeadId]));
    check(is_string($metaEventId) && Ids::isUuid($metaEventId), 'Meta webhook event was not durably recorded');
    check(Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val('SELECT COUNT(*) FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?', [$metaEndpoint['id'], $metaLeadId])) === 1, 'Meta duplicate delivery created more than one event');
    $metaFirstRun = Jobs::run([
        'inbound.process' => [InboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($metaFirstRun['retried'] === 1, 'Meta transient Graph API failure did not schedule a durable retry');
    $metaJobId = Db::system()->read(static fn(Tx $tx) => $tx->val("SELECT id FROM jobs WHERE type = 'inbound.process' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.eventId')) = ? AND status = 'queued'", [$tenantA, $metaEventId]));
    check(is_string($metaJobId) && $metaJobId !== '', 'Meta retry was not persisted in the job queue');
    Db::system()->tx(static fn(Tx $tx) => $tx->run('UPDATE jobs SET run_at = NOW(3) WHERE id = ?', [$metaJobId]));
    $metaRetry = Jobs::run([
        'inbound.process' => [InboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($metaRetry['succeeded'] >= 1, 'Meta Graph API retry did not complete successfully');
    $metaResult = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, lead_id, attempts FROM inbound_events WHERE id = ?', [$metaEventId]));
    check(($metaResult['status'] ?? '') === 'created' && (int) ($metaResult['attempts'] ?? 0) === 2 && !empty($metaResult['lead_id']), 'Meta retry did not create and persist the lead exactly once');
    check(Db::system()->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM jobs WHERE type = 'inbound.process' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.eventId')) = ?", [$tenantA, $metaEventId])) === 1, 'Meta duplicate webhook receipt created duplicate processing jobs');
    $metaLead = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT l.tenant_id, c.email FROM leads l JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ?', [$metaResult['lead_id']]));
    check(($metaLead['tenant_id'] ?? '') === $tenantA && str_starts_with((string) ($metaLead['email'] ?? ''), 'meta-'), 'Meta Graph lead was not isolated to its source tenant');
    check(Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM activities WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.eventId')) = ?", [$metaEventId])) === 1, 'Meta retry or duplicate delivery created duplicate timeline side effects');
    $metaGraphRequest = $metaTransport->requests[0] ?? [];
    $expectedProof = hash_hmac('sha256', $metaToken, $metaSecret);
    check(($metaGraphRequest['method'] ?? '') === 'GET'
        && str_contains((string) ($metaGraphRequest['url'] ?? ''), 'https://graph.facebook.com/v23.0/' . $metaLeadId)
        && str_contains((string) ($metaGraphRequest['url'] ?? ''), 'appsecret_proof=' . $expectedProof)
        && in_array('Authorization: Bearer ' . $metaToken, $metaGraphRequest['headers'] ?? [], true)
        && ($metaGraphRequest['timeout'] ?? 0) === 8
        && ($metaGraphRequest['maxBytes'] ?? 0) === 1048576,
        'Meta Graph request did not preserve provider authentication, proof, timeout and body limit');
    check(Db::tenant($actorB)->read(static fn(Tx $tx) => $tx->val('SELECT id FROM inbound_events WHERE id = ?', [$metaEventId])) === null, 'Meta webhook event crossed the tenant boundary');

    $metaTransport->queue(401, ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']]);
    $failedMetaLeadId = (string) (time() . random_int(10000, 99999));
    $failedMetaRaw = Json::enc([
        'object' => 'page',
        'entry' => [['id' => 'mock-page-1', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $failedMetaLeadId, 'form_id' => 'mock-form-1']]]]],
    ]);
    $failedMetaReceipt = new Response();
    Receiver::handle(new Request('POST', '/hooks/' . $metaEndpoint['id'], headers: [
        'content-type' => 'application/json',
        'content-length' => (string) strlen($failedMetaRaw),
        'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $failedMetaRaw, $metaSecret),
    ], rawBody: $failedMetaRaw, ip: '192.0.2.115'), $failedMetaReceipt);
    check($failedMetaReceipt->status === 200, 'Meta webhook did not durably accept an event for permanent Graph authentication failure handling');
    $failedMetaEventId = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->val('SELECT id FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?', [$metaEndpoint['id'], $failedMetaLeadId]));
    check(is_string($failedMetaEventId) && Ids::isUuid($failedMetaEventId), 'Meta permanent-failure event was not persisted');
    $metaPermanentRun = Jobs::run([
        'inbound.process' => [InboundProcessor::class, 'handle'],
        'mail.send' => [Mailer::class, 'handle'],
    ], 10, 10);
    check($metaPermanentRun['succeeded'] >= 1, 'Meta permanent Graph authentication failure was not handled');
    $failedMetaState = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, reason FROM inbound_events WHERE id = ?', [$failedMetaEventId]));
    check(($failedMetaState['status'] ?? '') === 'needs_review'
        && str_contains((string) ($failedMetaState['reason'] ?? ''), 'Meta refused')
        && Db::system()->read(static fn(Tx $tx) => (int) $tx->val("SELECT COUNT(*) FROM jobs WHERE type = 'inbound.process' AND tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.eventId')) = ? AND status = 'queued'", [$tenantA, $failedMetaEventId])) === 0,
        'Meta permanent Graph authentication failure was retried instead of surfaced for review');
} finally {
    GraphHttp::setTransport(null);
    Mailer::setTransport(null);
}

$staffInviteEmail = 'agency-invite-' . Ids::hex(6) . '@example.invalid';
$staffInvite = AgencyService::inviteStaff($actorA, $staffInviteEmail);
$agencyHqId = Db::asUser($userA)->one("SELECT id FROM tenants WHERE organisation_id = ? AND kind = 'agency_hq'", [$orgA])['id'] ?? null;
check(is_string($agencyHqId) && Ids::isUuid($agencyHqId), 'Agency HQ tenant could not be resolved for invitation verification');
$agencyHqActor = new Actor((string) $agencyHqId, $userA, 'tenant_owner');
$persistedInvite = Db::tenant($agencyHqActor)->read(static fn(Tx $tx) => $tx->one('SELECT tenant_id, email, role FROM tenant_invitations WHERE id = ?', [$staffInvite['id']]));
check(($persistedInvite['tenant_id'] ?? '') === $agencyHqId && ($persistedInvite['email'] ?? '') === $staffInviteEmail && ($persistedInvite['role'] ?? '') === 'sales_agent', 'Agency staff invitation did not persist in the agency workspace');
$agencyStaffView = AgencyService::staff($actorA);
check(count(array_filter($agencyStaffView['invitations'], static fn(array $invitation): bool => $invitation['id'] === $staffInvite['id'])) === 1, 'Agency staff listing did not return its active workspace invitations');
$agencyStaffId = Ids::uuid();
$pdo->prepare('INSERT INTO users (id,email,password_hash,name,email_verified) VALUES (?,?,?,?,1)')
    ->execute([$agencyStaffId, 'agency-staff-' . Ids::hex(6) . '@example.invalid', password_hash('integration-test-only', PASSWORD_DEFAULT), 'Workflow Agency Staff']);
$membershipInsert = $pdo->prepare("INSERT INTO tenant_memberships (id,tenant_id,user_id,role,status,is_active) VALUES (?,?,?,'sales_agent','active',1)");
$membershipInsert->execute([Ids::uuid(), $agencyHqId, $agencyStaffId]);
$membershipInsert->execute([Ids::uuid(), $tenantA, $agencyStaffId]);
Db::tenant($actorA)->tx(static fn(Tx $tx) => $tx->run('UPDATE leads SET owner_id = ? WHERE id = ?', [$agencyStaffId, $lead['id']]));
$agencyStaffActor = new Actor($tenantA, $agencyStaffId, 'sales_agent');
$staffLeadList = AgencyService::leads($agencyStaffActor, ['clientId' => $tenantA, 'page' => 1, 'pageSize' => 25]);
$staffReport = AgencyService::report($agencyStaffActor, ['from' => $reportDate, 'to' => $reportDate, 'clientId' => $tenantA]);
check($staffLeadList['page']['total'] === 1 && $staffLeadList['items'][0]['ownerId'] === $agencyStaffId, 'Agency lead list did not enforce the assigned employee own-lead scope');
check($staffReport['partial'] === true && $staffReport['rows'][0]['scope'] === 'own' && $staffReport['rows'][0]['leads']['created'] === 1, 'Agency comparison report did not preserve partial own-scope metrics');
$clientTimezoneName = 'America/Los_Angeles';
$pdo->prepare('UPDATE tenants SET timezone = ? WHERE id = ?')->execute([$clientTimezoneName, $tenantA]);
$clientTimezone = new DateTimeZone($clientTimezoneName);
$taskDueAt = (new DateTimeImmutable('now', $clientTimezone))->setTime(23, 30)->setTimezone(new DateTimeZone('UTC'));
$timezoneTaskId = Ids::uuid();
Db::tenant($actorA)->tx(static fn(Tx $tx) => $tx->insert('tasks', [
    'id' => $timezoneTaskId, 'tenant_id' => $tenantA, 'assigned_to' => $userA, 'created_by' => $userA,
    'title' => 'Timezone-boundary workflow task', 'priority' => 'medium', 'status' => 'pending', 'kind' => 'todo',
    'due_date' => \App\Core\Time::db($taskDueAt),
]));
$agencyTasks = AgencyService::tasks($actorA);
check(count(array_filter($agencyTasks, static fn($task) => $task['id'] === $timezoneTaskId)) === 1, 'Agency task feed did not include a task due before the client-local end of today');

$bEndpointId = Ids::uuid();
$bEventId = Ids::uuid();
$bSecret = 'tenant-beta-secret';
$pdo->prepare('INSERT INTO inbound_endpoints (id,tenant_id,provider,name,secret_enc,config) VALUES (?,?,?, ?,?,?)')
    ->execute([$bEndpointId, $tenantB, 'generic', 'Beta private source', Crypto::encrypt($bSecret, 'inbound:' . $bEndpointId), '{}']);
$pdo->prepare('INSERT INTO inbound_events (id,tenant_id,endpoint_id,provider,provider_event_id,payload,status) VALUES (?,?,?,?,?,?,?)')
    ->execute([$bEventId, $tenantB, $bEndpointId, 'generic', 'beta-private-event', '{"name":"Private"}', 'received']);
$tenantAEvents = InboundService::events($actorA, ['limit' => 25])['items'];
check(count($tenantAEvents) === 3
    && in_array($inboundEventId, array_column($tenantAEvents, 'id'), true)
    && in_array($metaEventId, array_column($tenantAEvents, 'id'), true)
    && in_array($failedMetaEventId, array_column($tenantAEvents, 'id'), true),
    'Inbound event history crossed tenant boundary or omitted a provider event');
check(MessagingService::whatsapp($actorB)['connected'] === true, 'Second-tenant messaging fixture did not exist');
$bLeadsVisibleFromA = Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val('SELECT COUNT(*) FROM leads WHERE tenant_id = ?', [$tenantB]));
check($bLeadsVisibleFromA === 0, 'Tenant-scoped lead view exposed another tenant');
$crossTenantWriteRejected = false;
try {
    Db::tenant($actorA)->tx(static fn(Tx $tx) => $tx->insert('outbound_endpoints', [
        'tenant_id' => $tenantB, 'name' => 'must be rejected', 'url' => 'https://example.invalid',
        'secret_enc' => Crypto::encrypt('not-persisted', Catalog::aad(Ids::uuid())), 'events' => '[]',
    ]));
} catch (PDOException $e) {
    $crossTenantWriteRejected = (string) $e->getCode() === '44000';
}
check($crossTenantWriteRejected, 'Tenant write view accepted a cross-tenant insert');
$tenantAEvents = InboundService::events($actorA, ['limit' => 25])['items'];
check(count($tenantAEvents) === 3 && !in_array($bEventId, array_column($tenantAEvents, 'id'), true), 'Tenant A received tenant B inbound event rows');
\App\Routes::registerAll();
$agencyExportRoute = array_filter(Router::routes(), static fn($route) => ($route['method'] ?? '') === 'GET' && ($route['fullPath'] ?? '') === '/api/v1/agency/leads/export');
check(count($agencyExportRoute) === 1, 'React agency export API path is not registered in PHP');
$agencyReportRoute = array_filter(Router::routes(), static fn($route) => ($route['method'] ?? '') === 'GET' && ($route['fullPath'] ?? '') === '/api/v1/agency/report');
check(count($agencyReportRoute) === 1 && isset(array_values($agencyReportRoute)[0]['query']), 'React agency report API path or query validation is missing in PHP');
$agencyLeadsRoute = array_filter(Router::routes(), static fn($route) => ($route['method'] ?? '') === 'GET' && ($route['fullPath'] ?? '') === '/api/v1/agency/leads');
check(count($agencyLeadsRoute) === 1 && isset(array_values($agencyLeadsRoute)[0]['query']), 'React agency lead API route or filter validation is missing in PHP');
$parsedLeadQuery = array_values($agencyLeadsRoute)[0]['query']->parseOrFail(['status' => 'open,won', 'createdFrom' => $reportDate, 'scoreMin' => '0'], 'query');
check($parsedLeadQuery['status'] === ['open', 'won'] && $parsedLeadQuery['scoreMin'] === 0, 'Agency lead query schema did not normalize React filter parameters');
$agencyActivityRoute = array_filter(Router::routes(), static fn($route) => ($route['method'] ?? '') === 'GET' && ($route['fullPath'] ?? '') === '/api/v1/agency/activity');
check(count($agencyActivityRoute) === 1 && isset(array_values($agencyActivityRoute)[0]['query']), 'React agency activity route or date validation is missing in PHP');
$agencyUnreadRoute = array_filter(Router::routes(), static fn($route) => ($route['method'] ?? '') === 'GET' && ($route['fullPath'] ?? '') === '/api/v1/agency/unread');
check(count($agencyUnreadRoute) === 1, 'React agency unread-count API path is not registered in PHP');
$registeredAgencyRoutes = Router::routes();
$contactList = ContactsService::list($actorA, ['limit' => 50, 'cursor' => null, 'q' => null, 'ownerId' => null, 'tag' => null, 'sort' => 'created', 'order' => 'desc']);
check(count($contactList['items']) > 0 && is_array($contactList['items'][0]['alternateEmails']) && is_array($contactList['items'][0]['alternatePhones']), 'Contacts list did not return source-compatible alternate identifier arrays');
$automationRoutes = array_values(array_filter($registeredAgencyRoutes, static fn($route) => str_starts_with((string) ($route['fullPath'] ?? ''), '/api/v1/automations')));
$automationContract = array_map(static fn($route) => [$route['method'], $route['fullPath'], $route['perm'] ?? null], $automationRoutes);
$expectedAutomationContract = [
    ['GET', '/api/v1/automations', 'automation:read'],
    ['POST', '/api/v1/automations/{template}/enable', 'automation:manage'],
    ['POST', '/api/v1/automations/{template}/pause', 'automation:manage'],
    ['GET', '/api/v1/automations/executions', 'automation:read'],
    ['GET', '/api/v1/automations/executions/{id}', 'automation:read'],
    ['POST', '/api/v1/automations/executions/{id}/retry', 'automation:manage'],
];
check($automationContract === $expectedAutomationContract, 'React automation API paths or permissions are not registered in PHP');
$automationList = \App\Modules\Automation\AutomationService::list($actorA);
check(count($automationList) === 1 && $automationList[0]['key'] === 'new_lead_follow_up' && $automationList[0]['rule'] === null, 'Automation template list did not return the source-compatible initial state');
$inviteStaffRoute = array_values(array_filter($registeredAgencyRoutes, static fn($route) => ($route['method'] ?? '') === 'POST' && ($route['fullPath'] ?? '') === '/api/v1/agency/staff/invitations'));
$staffRoleRoute = array_values(array_filter($registeredAgencyRoutes, static fn($route) => ($route['method'] ?? '') === 'PATCH' && ($route['fullPath'] ?? '') === '/api/v1/agency/staff/{userId}'));
$clientUnassignRoute = array_values(array_filter($registeredAgencyRoutes, static fn($route) => ($route['method'] ?? '') === 'DELETE' && ($route['fullPath'] ?? '') === '/api/v1/agency/clients/{id}/team/{userId}'));
$staffRemoveRoute = array_values(array_filter($registeredAgencyRoutes, static fn($route) => ($route['method'] ?? '') === 'DELETE' && ($route['fullPath'] ?? '') === '/api/v1/agency/staff/{userId}'));
check(count($inviteStaffRoute) === 1 && $inviteStaffRoute[0]['status'] === 201, 'React agency staff invitation route is missing or has the wrong success status');
check(count($staffRoleRoute) === 1, 'React agency staff role route is missing');
$staffRoleRoute[0]['body']->parseOrFail(['agencyRole' => 'admin'], 'body');
check(count($clientUnassignRoute) === 1 && ($clientUnassignRoute[0]['status'] ?? 200) === 200, 'Agency client unassignment does not return its result payload');
check(count($staffRemoveRoute) === 1 && ($staffRemoveRoute[0]['status'] ?? 200) === 200, 'Agency staff removal does not return its result payload');

$websiteSource = InboundService::create($actorA, new Request('POST', '/api/v1/lead-sources'), [
    'provider' => 'website', 'name' => 'Workflow chatbot source',
    'config' => ['pipelineId' => $pipelineA, 'stageId' => $stageA, 'ownerId' => $userA],
]);
$websiteEndpoint = InboundService::lookupPublic($websiteSource['endpoint']['id']);
check($websiteEndpoint !== null, 'Website chatbot source could not be looked up');
$chatPayload = [
    'eventId' => 'chatbot-' . Ids::hex(8),
    'full_name' => 'Workflow Chat Visitor',
    'email' => 'chat-' . Ids::hex(6) . '@example.invalid',
    'phone' => '+1555' . (string) random_int(1000000, 9999999),
    'conversation' => [
        ['role' => 'assistant', 'content' => 'How can I help?'],
        ['role' => 'user', 'content' => 'I want to book a viewing.'],
    ],
];
$chatRaw = Json::enc($chatPayload);
$chatResponse = new Response();
Receiver::handle(new Request('POST', '/hooks/' . $websiteEndpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($chatRaw),
], rawBody: $chatRaw, ip: '192.0.2.113'), $chatResponse);
check($chatResponse->status === 200, 'Website chatbot receiver did not accept the submission');
$chatEventId = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->val(
    'SELECT id FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?',
    [$websiteEndpoint['id'], $chatPayload['eventId']]
));
check(is_string($chatEventId) && Ids::isUuid($chatEventId), 'Website chatbot delivery was not persisted');
$chatProcess = Jobs::run([
    'inbound.process' => [InboundProcessor::class, 'handle'],
    'mail.send' => [Mailer::class, 'handle'],
], 10, 10);
check($chatProcess['succeeded'] >= 1, 'Website chatbot processing job did not complete');
$chatResult = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, lead_id FROM inbound_events WHERE id = ?', [$chatEventId]));
check(($chatResult['status'] ?? '') === 'created' && !empty($chatResult['lead_id']), 'Website chatbot worker did not create a lead');
$chatLead = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one(
    'SELECT l.tenant_id, c.email FROM leads l JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ?',
    [$chatResult['lead_id']]
));
check(($chatLead['tenant_id'] ?? '') === $tenantA && ($chatLead['email'] ?? '') === $chatPayload['email'], 'Website chatbot lead was not created in the endpoint tenant');
$chatNotes = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->all(
    "SELECT description, metadata FROM activities WHERE lead_id = ? AND tenant_id = ? AND type = 'note'",
    [$chatResult['lead_id'], $tenantA]
));
$conversationNote = null;
foreach ($chatNotes as $note) {
    if ((Json::dec($note['metadata'] ?? null, [])['event'] ?? '') === 'inbound_message') {
        $conversationNote = $note;
        break;
    }
}
$conversationMetadata = Json::dec($conversationNote['metadata'] ?? null, []);
check($conversationNote !== null
    && str_contains((string) $conversationNote['description'], 'Visitor: I want to book a viewing.')
    && str_contains((string) $conversationNote['description'], 'Bot: How can I help?')
    && (int) ($conversationMetadata['turns'] ?? 0) === 2,
    'Website chatbot transcript was not preserved in the lead timeline');

$googleSource = InboundService::create($actorA, new Request('POST', '/api/v1/lead-sources'), [
    'provider' => 'google', 'name' => 'Workflow Google Ads source',
    'config' => ['pipelineId' => $pipelineA, 'stageId' => $stageA, 'ownerId' => $userA],
]);
$googleEndpoint = InboundService::lookupPublic($googleSource['endpoint']['id']);
check($googleEndpoint !== null, 'Google Ads source could not be looked up');
$googleLeadId = 'google-lead-' . Ids::hex(8);
$googleEmail = 'google-' . Ids::hex(6) . '@example.invalid';
$googlePayload = [
    'google_key' => $googleSource['secret'],
    'lead_id' => $googleLeadId,
    'campaign_id' => 'workflow-campaign',
    'campaign_name' => 'Workflow spring campaign',
    'gcl_id' => 'workflow-click-' . Ids::hex(4),
    'user_column_data' => [
        ['column_id' => 'FULL_NAME', 'string_value' => 'Workflow Google Lead'],
        ['column_id' => 'EMAIL', 'string_value' => $googleEmail],
        ['column_id' => 'PHONE_NUMBER', 'string_value' => '+1555' . (string) random_int(1000000, 9999999)],
    ],
];
$googleRaw = Json::enc($googlePayload);
$googleRequest = new Request('POST', '/hooks/' . $googleEndpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($googleRaw),
], rawBody: $googleRaw, ip: '192.0.2.114');
$googleRejected = new Response();
$badGooglePayload = $googlePayload;
$badGooglePayload['google_key'] = 'invalid-google-key';
$badGoogleRaw = Json::enc($badGooglePayload);
Receiver::handle(new Request('POST', '/hooks/' . $googleEndpoint['id'], headers: [
    'content-type' => 'application/json',
    'content-length' => (string) strlen($badGoogleRaw),
], rawBody: $badGoogleRaw, ip: '192.0.2.115'), $googleRejected);
check($googleRejected->status === 401, 'Google lead receiver accepted an invalid shared key');
check(Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val(
    'SELECT COUNT(*) FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?',
    [$googleEndpoint['id'], $googleLeadId]
)) === 0, 'Google lead receiver persisted an unauthenticated event');
$googleAccepted = new Response();
Receiver::handle($googleRequest, $googleAccepted);
$googleDuplicate = new Response();
Receiver::handle($googleRequest, $googleDuplicate);
check($googleAccepted->status === 200 && $googleDuplicate->status === 200, 'Google lead receiver did not accept and acknowledge a duplicate delivery');
$googleEvent = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one(
    'SELECT id, status, payload FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?',
    [$googleEndpoint['id'], $googleLeadId]
));
$googleStoredPayload = Json::dec($googleEvent['payload'] ?? null, []);
check(is_string($googleEvent['id'] ?? null)
    && $googleEvent['status'] === 'received'
    && !array_key_exists('google_key', $googleStoredPayload)
    && Db::tenant($actorA)->read(static fn(Tx $tx) => (int) $tx->val(
        'SELECT COUNT(*) FROM inbound_events WHERE endpoint_id = ? AND provider_event_id = ?',
        [$googleEndpoint['id'], $googleLeadId]
    )) === 1,
    'Google key redaction or duplicate receipt idempotency failed');
$googleProcess = Jobs::run([
    'inbound.process' => [InboundProcessor::class, 'handle'],
    'mail.send' => [Mailer::class, 'handle'],
], 10, 10);
check($googleProcess['succeeded'] >= 1, 'Google lead processing job did not complete');
$googleResult = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, lead_id FROM inbound_events WHERE id = ?', [$googleEvent['id']]));
$googleLead = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one(
    'SELECT l.tenant_id, l.campaign, l.source_details, c.email FROM leads l JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ?',
    [$googleResult['lead_id']]
));
$googleLeadDetails = Json::dec($googleLead['source_details'] ?? null, []);
check(($googleResult['status'] ?? '') === 'created'
    && ($googleLead['tenant_id'] ?? '') === $tenantA
    && ($googleLead['email'] ?? '') === $googleEmail
    && ($googleLead['campaign'] ?? '') === 'Workflow spring campaign'
    && ($googleLeadDetails['gclid'] ?? '') === $googlePayload['gcl_id'],
    'Authenticated Google Ads event did not create a tenant-scoped lead with campaign and click attribution');

$interruptedEventId = Ids::uuid();
$interruptedJobId = Ids::uuid();
$pdo->prepare('INSERT INTO inbound_events (id, tenant_id, endpoint_id, provider, provider_event_id, payload) VALUES (?,?,?,?,?,?)')
    ->execute([$interruptedEventId, $tenantA, $endpoint['id'], 'generic', 'interrupted-' . Ids::hex(6), '{}']);
$pdo->prepare("INSERT INTO jobs (id, tenant_id, type, payload, status, attempts, max_attempts, lease_until, locked_by, dedupe_key) VALUES (?,?, 'inbound.process', ?, 'running', 6, 6, DATE_SUB(NOW(3), INTERVAL 1 SECOND), 'dead-worker', ?)")
    ->execute([$interruptedJobId, $tenantA, Json::enc(['eventId' => $interruptedEventId]), 'inbound-interrupted:' . $interruptedEventId]);
$workerRun = \App\Core\Worker::pump(1.0, 0);
$interruptedJobState = Db::system()->read(static fn(Tx $tx) => $tx->val('SELECT status FROM jobs WHERE id = ?', [$interruptedJobId]));
check(($workerRun['claimed'] ?? -1) === 0 && $interruptedJobState === 'dead', 'Worker pump did not reap an expired final inbound lease');
$interruptedState = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->one('SELECT status, reason FROM inbound_events WHERE id = ?', [$interruptedEventId]));
check(($interruptedState['status'] ?? '') === 'failed' && str_contains((string) ($interruptedState['reason'] ?? ''), 'worker exhausted its retries'),
    'Worker pump left a dead-lettered inbound event undiscoverable in received status');

$pendingRetryEventId = Ids::uuid();
$pdo->prepare('INSERT INTO inbound_events (id, tenant_id, endpoint_id, provider, provider_event_id, payload) VALUES (?,?,?,?,?,?)')
    ->execute([$pendingRetryEventId, $tenantA, $endpoint['id'], 'generic', 'pending-retry-' . Ids::hex(6), '{}']);
$pendingRetryJobId = Ids::uuid();
$pdo->prepare("INSERT INTO jobs (id, tenant_id, type, payload, status, attempts, max_attempts, dedupe_key) VALUES (?,?, 'inbound.process', ?, 'dead', 6, 6, ?)")
    ->execute([$pendingRetryJobId, $tenantA, Json::enc(['eventId' => $pendingRetryEventId]), 'inbound-old-dead:' . $pendingRetryEventId]);
Db::tenant($actorA)->tx(static fn(Tx $tx) => \App\Core\Jobs::enqueue(
    $tx,
    ['type' => 'inbound.process', 'payload' => ['eventId' => $pendingRetryEventId], 'tenantId' => $tenantA, 'dedupeKey' => 'inbound-active-retry:' . $pendingRetryEventId]
));
$pendingReconciled = InboundProcessor::reconcileDeadJobs();
$pendingState = Db::tenant($actorA)->read(static fn(Tx $tx) => $tx->val('SELECT status FROM inbound_events WHERE id = ?', [$pendingRetryEventId]));
check($pendingReconciled === 0 && $pendingState === 'received', 'Dead-job recovery incorrectly failed an event with an active retry');

echo "Database workflows passed: signed inbound receipt/idempotency/lead creation; Google key authentication/redaction/idempotency/lead creation; website chatbot transcript capture; outbound signed 503->200 retry; WhatsApp mock authentication/rate-limit retry/permanent failure; Meta mock signature/handshake/idempotency/Graph retry; agency filters/own scope/timezone-aware tasks/activity/exports/report/staff invitation; cross-tenant read/write isolation\n";
