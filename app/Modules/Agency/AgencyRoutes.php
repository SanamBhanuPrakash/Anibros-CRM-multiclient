<?php
declare(strict_types=1);

namespace App\Modules\Agency;

use App\Core\Ctx;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class AgencyRoutes
{
    public static function register(): void
    {
        $id = V::uuid();
        $userId = V::uuid();
        $date = V::str()->regex('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/', 'Use an ISO date or timestamp');
        $exportQuery = V::obj([
            'format' => V::enum(['xlsx', 'csv'])->default('xlsx'),
            'dataset' => V::enum(['leads', 'activities'])->default('leads'),
            'clientId' => V::uuid()->optional(),
            'q' => V::str()->trim()->max(100)->optional(),
            'status' => V::str()->max(200)->optional(),
            'state' => V::enum(['open', 'closed'])->optional(),
            'ownerId' => V::str()->max(36)->optional(),
            'source' => V::str()->max(40)->optional(),
            'tag' => V::str()->trim()->max(40)->optional(),
            'scoreMin' => V::coerceInt()->min(0)->max(100)->optional(),
            'createdFrom' => $date->optional(),
            'createdTo' => $date->optional(),
            'test' => V::enum(['exclude'])->optional(),
            'sla' => V::enum(['breached', 'at_risk'])->optional(),
            'followup' => V::enum(['overdue', 'today'])->optional(),
            'field' => V::str()->regex('/^[a-z][a-z0-9_]{0,39}$/')->optional(),
            'fieldValue' => V::str()->max(200)->optional(),
            'pool' => V::enum(['true'])->optional(),
        ]);
        $reportQuery = V::obj([
            'from' => V::str()->regex('/^\d{4}-\d{2}-\d{2}$/')->optional(),
            'to' => V::str()->regex('/^\d{4}-\d{2}-\d{2}$/')->optional(),
            'includeTest' => V::enum(['true', 'false'])->default('false'),
            'clientId' => V::uuid()->optional(),
        ]);
        $leadQuery = V::obj([
            'clientId' => V::uuid()->optional(),
            'q' => V::str()->trim()->max(100)->optional(),
            'status' => V::str()->refine(static function ($value) {
                foreach (array_filter(array_map('trim', explode(',', $value))) as $status) if (!in_array($status, \App\Modules\Leads\Vocab::STATUSES, true)) return false;
                return true;
            }, 'Unknown status')->transform(static fn($value) => array_values(array_filter(array_map('trim', explode(',', $value)))))->optional(),
            'state' => V::enum(['open', 'closed'])->optional(),
            'ownerId' => V::union([V::uuid(), V::literal('unassigned'), V::literal('me')])->optional(),
            'source' => V::enum(\App\Modules\Leads\Routing::LEAD_SOURCES)->optional(),
            'tag' => V::str()->trim()->max(40)->optional(),
            'scoreMin' => V::coerceInt()->min(0)->max(100)->optional(),
            'createdFrom' => V::str()->refine(static fn($value) => strtotime($value) !== false, 'Use an ISO date or timestamp')->optional(),
            'createdTo' => V::str()->refine(static fn($value) => strtotime($value) !== false, 'Use an ISO date or timestamp')->optional(),
            'test' => V::enum(['exclude'])->optional(),
            'sla' => V::enum(['breached', 'at_risk'])->optional(),
            'followup' => V::enum(['overdue', 'today'])->optional(),
            'field' => V::str()->regex('/^[a-z][a-z0-9_]{0,39}$/')->optional(),
            'fieldValue' => V::str()->max(200)->optional(),
            'pool' => V::enum(['true'])->optional(),
            'page' => V::coerceInt()->min(1)->default(1),
            'pageSize' => V::coerceInt()->min(1)->max(100)->default(50),
            'sort' => V::enum(['created', 'updated', 'score', 'value'])->default('created'),
            'order' => V::enum(['asc', 'desc'])->default('desc'),
        ]);
        $activityQuery = V::obj([
            'clientId' => V::uuid()->optional(),
            'from' => V::dateStr()->optional(),
            'to' => V::dateStr()->optional(),
        ]);
        $inviteStaffBody = V::obj(['email' => V::str()->trim()->lower()->email()->max(254)]);

        Router::mount('/api/v1/agency', [
            ['method' => 'GET', 'path' => '/overview', 'handler' => fn(Ctx $c) => AgencyService::overview($c->actor)],
            ['method' => 'GET', 'path' => '/options', 'handler' => fn(Ctx $c) => AgencyService::options()],

            // Clients
            ['method' => 'GET', 'path' => '/clients', 'handler' => function (Ctx $c) {
                $status = (string) ($c->query['status'] ?? 'active');
                $stats = ($c->query['stats'] ?? 'true') !== 'false';
                return AgencyService::clients($c->actor, $status, $stats);
            }],
            ['method' => 'POST', 'path' => '/clients', 'status' => 201, 'body' => V::obj(['name' => V::str()->trim()->min(1)->max(120)])->passthrough(),
                'handler' => fn(Ctx $c) => new Reply(AgencyService::create($c->actor, $c->body), 201)],
            ['method' => 'GET', 'path' => '/clients/{id}', 'params' => V::obj(['id' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::get($c->actor, $c->params['id'])],
            ['method' => 'PATCH', 'path' => '/clients/{id}', 'params' => V::obj(['id' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'POST', 'path' => '/clients/{id}/archive', 'params' => V::obj(['id' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::archive($c->actor, $c->params['id'], true)],
            ['method' => 'POST', 'path' => '/clients/{id}/restore', 'params' => V::obj(['id' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::archive($c->actor, $c->params['id'], false)],
            ['method' => 'POST', 'path' => '/clients/{id}/custom-fields/copy', 'params' => V::obj(['id' => $id]),
                'body' => V::obj(['fromClientId' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::copyCustomFields($c->actor, $c->params['id'], $c->body['fromClientId'])],

            // Team for client
            ['method' => 'GET', 'path' => '/clients/{id}/team', 'params' => V::obj(['id' => $id]),
                'handler' => fn(Ctx $c) => AgencyService::team($c->actor, $c->params['id'])],
            ['method' => 'PUT', 'path' => '/clients/{id}/team/{userId}', 'params' => V::obj(['id' => $id, 'userId' => $userId]),
                'body' => V::obj([
                    'role' => V::enum(AgencyService::CLIENT_ROLES),
                    'channels' => V::arr(V::enum(\App\Modules\Leads\Vocab::WORK_CHANNELS))->max(6)->nullish(),
                ]),
                'handler' => fn(Ctx $c) => AgencyService::assign(
                    $c->actor,
                    $c->params['id'],
                    $c->params['userId'],
                    $c->body['role'],
                    $c->body['channels'] ?? null,
                    array_key_exists('channels', $c->body),
                )],
            ['method' => 'DELETE', 'path' => '/clients/{id}/team/{userId}', 'params' => V::obj(['id' => $id, 'userId' => $userId]),
                'handler' => fn(Ctx $c) => AgencyService::unassign($c->actor, $c->params['id'], $c->params['userId'])],

            // Staff
            ['method' => 'GET', 'path' => '/staff', 'handler' => fn(Ctx $c) => AgencyService::staff($c->actor)],
            ['method' => 'POST', 'path' => '/staff/invitations', 'status' => 201, 'body' => $inviteStaffBody,
                'handler' => fn(Ctx $c) => new Reply(AgencyService::inviteStaff($c->actor, $c->body['email']), 201)],
            ['method' => 'POST', 'path' => '/staff', 'status' => 201, 'body' => $inviteStaffBody,
                'handler' => fn(Ctx $c) => new Reply(AgencyService::inviteStaff($c->actor, $c->body['email']), 201)],
            ['method' => 'PATCH', 'path' => '/staff/{userId}', 'params' => V::obj(['userId' => $userId]),
                'body' => V::obj(['agencyRole' => V::enum(['admin', 'staff'])]),
                'handler' => fn(Ctx $c) => AgencyService::setStaffRole($c->actor, $c->params['userId'], $c->body['agencyRole'])],
            ['method' => 'DELETE', 'path' => '/staff/{userId}', 'params' => V::obj(['userId' => $userId]),
                'handler' => fn(Ctx $c) => AgencyService::removeStaff($c->actor, $c->params['userId'])],

            // Aggregate feeds across all clients
            ['method' => 'GET', 'path' => '/leads', 'query' => $leadQuery, 'handler' => fn(Ctx $c) => AgencyService::leads($c->actor, $c->query)],
            ['method' => 'GET', 'path' => '/unread', 'handler' => fn(Ctx $c) => AgencyService::unread($c->actor)],
            ['method' => 'GET', 'path' => '/leads/unread', 'handler' => fn(Ctx $c) => AgencyService::unread($c->actor)],
            ['method' => 'GET', 'path' => '/tasks', 'handler' => fn(Ctx $c) => AgencyService::tasks($c->actor)],
            ['method' => 'GET', 'path' => '/activity', 'query' => $activityQuery, 'handler' => fn(Ctx $c) => AgencyService::activity($c->actor, $c->query)],
            ['method' => 'GET', 'path' => '/report', 'query' => $reportQuery, 'handler' => fn(Ctx $c) => AgencyService::report($c->actor, $c->query)],
            ['method' => 'GET', 'path' => '/leads/export', 'query' => $exportQuery, 'handler' => function (Ctx $c) {
                $file = AgencyService::exportFile($c->actor, $c->query);
                $xlsx = $c->query['format'] === 'xlsx';
                return new RawReply($file['body'], $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8', [
                    'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
                    'X-Row-Count' => (string) $file['rows'],
                    'Cache-Control' => 'no-store',
                ]);
            }],
            ['method' => 'GET', 'path' => '/export', 'handler' => function (Ctx $c) {
                $file = AgencyService::exportFile($c->actor, array_replace($c->query, ['format' => 'csv', 'dataset' => 'leads']));
                return new RawReply($file['body'], 'text/csv; charset=utf-8', [
                    'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
                    'X-Row-Count' => (string) $file['rows'],
                    'Cache-Control' => 'no-store',
                ]);
            }],
        ]);
    }
}
