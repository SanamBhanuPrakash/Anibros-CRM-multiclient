<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Ids;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Tx;

final class AgencyAssignmentRollback extends RuntimeException
{
}

\App\Routes::registerAll();
$assignRoute = null;
foreach (Router::routes() as $route) {
    if (($route['fullPath'] ?? '') === '/api/v1/agency/clients/{id}/team/{userId}' && ($route['method'] ?? '') === 'PUT') {
        $assignRoute = $route;
        break;
    }
}
if (!$assignRoute || !isset($assignRoute['body']) || !is_callable($assignRoute['handler'] ?? null)) {
    throw new RuntimeException('Agency team assignment route is incomplete');
}

$ownerId = Ids::uuid();
$staffId = Ids::uuid();
$organisationId = Ids::uuid();
$hqId = Ids::uuid();
$clientId = Ids::uuid();
$ownerEmail = 'agency-channel-owner-' . str_replace('-', '', $ownerId) . '@example.invalid';
$staffEmail = 'agency-channel-staff-' . str_replace('-', '', $staffId) . '@example.invalid';
$owner = new Actor($clientId, $ownerId, 'tenant_owner');
$routeId = '/api/v1/agency/clients/' . $clientId . '/team/' . $staffId;

try {
    Db::system()->tx(static function (Tx $tx) use ($assignRoute, $ownerId, $staffId, $organisationId, $hqId, $clientId, $ownerEmail, $staffEmail, $owner, $routeId): void {
        $tx->insert('users', ['id' => $ownerId, 'email' => $ownerEmail, 'password_hash' => password_hash('not-used-in-contract-test', PASSWORD_DEFAULT), 'name' => 'Agency channel owner', 'email_verified' => 1]);
        $tx->insert('users', ['id' => $staffId, 'email' => $staffEmail, 'password_hash' => password_hash('not-used-in-contract-test', PASSWORD_DEFAULT), 'name' => 'Agency channel staff', 'email_verified' => 1]);
        $tx->insert('organisations', ['id' => $organisationId, 'name' => 'Agency channel contract', 'slug' => 'agency-channel-' . substr(str_replace('-', '', $organisationId), 0, 20), 'owner_id' => $ownerId]);
        $tx->insert('tenants', ['id' => $hqId, 'organisation_id' => $organisationId, 'name' => 'Agency channel HQ', 'slug' => 'agency-channel-hq-' . substr(str_replace('-', '', $hqId), 0, 16), 'settings' => '{}', 'kind' => 'agency_hq']);
        $tx->insert('tenants', ['id' => $clientId, 'organisation_id' => $organisationId, 'name' => 'Agency channel client', 'slug' => 'agency-channel-client-' . substr(str_replace('-', '', $clientId), 0, 16), 'settings' => '{}', 'kind' => 'client']);
        foreach ([[$hqId, $ownerId, 'tenant_owner'], [$clientId, $ownerId, 'tenant_owner'], [$hqId, $staffId, 'sales_agent'], [$clientId, $staffId, 'sales_agent']] as [$tenantId, $userId, $role]) {
            $tx->insert('tenant_memberships', ['id' => Ids::uuid(), 'tenant_id' => $tenantId, 'user_id' => $userId, 'role' => $role, 'status' => 'active', 'is_active' => 1]);
        }

        $invoke = static function (array $input) use ($assignRoute, $owner, $clientId, $staffId, $routeId): mixed {
            $body = $assignRoute['body']->parseOrFail($input, 'body');
            return ($assignRoute['handler'])(new Ctx(
                ['id' => $clientId, 'userId' => $staffId],
                [],
                $body,
                $owner,
                new Request('PUT', $routeId),
                new Response(),
            ));
        };

        $invoke(['role' => 'sales_agent', 'channels' => ['call']]);
        $restricted = json_decode((string) $tx->val('SELECT work_channels FROM tenant_memberships WHERE tenant_id = ? AND user_id = ?', [$clientId, $staffId]), true, 512, JSON_THROW_ON_ERROR);
        if ($restricted !== ['call']) throw new RuntimeException('Explicit channel list was not saved');

        $omitted = $assignRoute['body']->parseOrFail(['role' => 'sales_agent'], 'body');
        if (array_key_exists('channels', $omitted)) throw new RuntimeException('Omitted channels were not preserved as omitted by validation');
        $invoke($omitted);
        $preserved = json_decode((string) $tx->val('SELECT work_channels FROM tenant_memberships WHERE tenant_id = ? AND user_id = ?', [$clientId, $staffId]), true, 512, JSON_THROW_ON_ERROR);
        if ($preserved !== ['call']) throw new RuntimeException('Omitting channels removed the existing restriction');

        $invoke(['role' => 'sales_agent', 'channels' => null]);
        if ($tx->val('SELECT work_channels FROM tenant_memberships WHERE tenant_id = ? AND user_id = ?', [$clientId, $staffId]) !== null) {
            throw new RuntimeException('Explicit null did not restore access to all work channels');
        }

        $invalidRejected = false;
        try {
            $assignRoute['body']->parseOrFail(['role' => 'sales_agent', 'channels' => ['fax']], 'body');
        } catch (AppError $e) {
            if ($e->status !== 400) throw $e;
            $invalidRejected = true;
        }
        if (!$invalidRejected) throw new RuntimeException('Unknown work channel was accepted');

        throw new AgencyAssignmentRollback('rollback contract fixture');
    });
} catch (AgencyAssignmentRollback) {
    echo "Agency work-channel omission, explicit-null, validation, and persistence contracts passed; fixture rolled back\n";
}
