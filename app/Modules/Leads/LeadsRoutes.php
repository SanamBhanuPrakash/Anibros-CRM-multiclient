<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Time;
use App\Core\V;

/** /api/v1/leads (+ imports, activities, handlers), /api/v1/pipelines, /api/v1/saved-views */
final class LeadsRoutes
{
    private static function text(int $max): V
    {
        return V::str()->trim()->max($max);
    }

    private static function money(): V
    {
        return V::num()->min(0)->max(1e13);
    }

    private static function etag(array $l): array
    {
        return Etag::make((int) $l['version'], $l['leadScore'], $l['lastActivityAt'], $l['nextFollowupAt'], $l['sla']['state']);
    }

    /** @return array<string,V> */
    private static function requirement(): array
    {
        return [
            'estimatedValue' => self::money()->nullish(), 'currency' => V::str()->trim()->min(3)->max(3)->optional(), 'budgetMin' => self::money()->nullish(), 'budgetMax' => self::money()->nullish(),
            'bedroomsMin' => V::int()->min(0)->max(20)->nullish(), 'locations' => V::arr(self::text(80)->min(1))->max(10)->optional(), 'propertyTypes' => V::arr(V::enum(Vocab::PROPERTY_TYPES))->max(8)->optional(),
            'listingType' => V::enum(['sale', 'rent', 'lease'])->nullish(), 'tags' => V::arr(self::text(40))->max(30)->optional(), 'campaign' => self::text(150)->nullish(),
            'customFields' => V::record(V::any())->refine(static fn($v) => strlen(json_encode($v)) <= 12000, 'Custom fields are too large (12 kB maximum)')->optional(), 'nextFollowupAt' => V::datetime()->nullish(),
        ];
    }

    /** @return array<string,V> */
    private static function filterShape(): array
    {
        return [
            'q' => self::text(100)->optional(), 'pipelineId' => V::uuid()->optional(), 'stageId' => V::uuid()->optional(),
            'status' => V::str()->refine(static function ($v) {
                foreach (array_filter(array_map('trim', explode(',', $v))) as $p) if (!in_array($p, Vocab::STATUSES, true)) return false;
                return true;
            }, 'Unknown status')->transform(static fn($v) => array_values(array_filter(array_map('trim', explode(',', $v)))))->optional(),
            'state' => V::enum(['open', 'closed'])->optional(), 'ownerId' => V::union([V::uuid(), V::literal('unassigned')])->optional(), 'source' => V::enum(Routing::LEAD_SOURCES)->optional(),
            'tag' => self::text(40)->optional(), 'scoreMin' => V::coerceInt()->min(0)->max(100)->optional(),
            'createdFrom' => V::str()->refine(static fn($v) => strtotime($v) !== false, 'Use an ISO date, e.g. 2026-10-14 or 2026-10-14T09:30:00Z')->optional(),
            'createdTo' => V::str()->refine(static fn($v) => strtotime($v) !== false, 'Use an ISO date, e.g. 2026-10-14 or 2026-10-14T09:30:00Z')->optional(),
            'test' => V::enum(['exclude'])->optional(), 'contactId' => V::uuid()->optional(), 'sla' => V::enum(['breached', 'at_risk'])->optional(), 'followup' => V::enum(['overdue', 'today'])->optional(),
            'field' => V::str()->regex('/^[a-z][a-z0-9_]{0,39}$/', 'Use the field key, e.g. treatment')->optional(), 'fieldValue' => self::text(200)->optional(),
            'pool' => V::enum(['true'])->transform(static fn($v) => $v === 'true' ? true : null)->optional(),
        ];
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $uuid = V::uuid();

        Router::mount('/api/v1/leads', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'leads:read',
                'query' => V::obj(array_merge(['limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional()], self::filterShape(), ['sort' => V::enum(['created', 'updated', 'score', 'value', 'followup'])->default('created'), 'order' => V::enum(['asc', 'desc'])->default('desc')])),
                'handler' => function (Ctx $c) { $r = LeadsService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/export', 'perm' => 'leads:export', 'query' => V::obj(self::filterShape()),
                'handler' => function (Ctx $c) {
                    $r = LeadsService::exportCsv($c->actor, $c->query);
                    return new RawReply($r['csv'], 'text/csv; charset=utf-8', ['Content-Disposition' => 'attachment; filename="leads-' . gmdate('Y-m-d') . '.csv"', 'X-Row-Count' => (string) $r['rows'], 'Cache-Control' => 'no-store']);
                }],
            ['method' => 'GET', 'path' => '/activity-options', 'perm' => 'activities:read',
                'handler' => fn(Ctx $c) => ['types' => Vocab::ACTIVITY_TYPES, 'directions' => Vocab::ACTIVITY_DIRECTIONS, 'outcomes' => Vocab::ACTIVITY_OUTCOMES]],
            ['method' => 'GET', 'path' => '/imports', 'perm' => 'leads:import', 'handler' => fn(Ctx $c) => ImportService::list($c->actor)],
            ['method' => 'POST', 'path' => '/imports', 'perm' => 'leads:import', 'status' => 202, 'bodyLimit' => 6 * 1024 * 1024,
                'body' => V::obj([
                    'fileName' => self::text(200)->optional(), 'csv' => V::str()->min(1), 'mapping' => V::record(V::enum(ImportService::FIELDS))->optional(),
                    'onDuplicate' => V::enum(['skip', 'create'])->optional(), 'defaultSource' => V::enum(Routing::LEAD_SOURCES)->optional(), 'ownerId' => V::uuid()->nullish(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(ImportService::create($c->actor, $c->body), 202)],
            ['method' => 'GET', 'path' => '/imports/{id}', 'perm' => 'leads:import', 'params' => $id, 'handler' => fn(Ctx $c) => ImportService::get($c->actor, $c->params['id'])],
            ['method' => 'POST', 'path' => '/bulk', 'perm' => 'leads:bulk', 'status' => 200,
                'body' => V::union([
                    V::obj(['ids' => V::arr(V::uuid())->min(1)->max(200), 'action' => V::literal('assign'), 'ownerId' => V::uuid()->nullable()]),
                    V::obj(['ids' => V::arr(V::uuid())->min(1)->max(200), 'action' => V::literal('move_stage'), 'stageId' => V::uuid(), 'lossReason' => self::text(300)->optional(), 'reasonCode' => V::str()->max(40)->optional()]),
                    V::obj(['ids' => V::arr(V::uuid())->min(1)->max(200), 'action' => V::enum(['add_tags', 'remove_tags']), 'tags' => V::arr(self::text(40)->min(1))->min(1)->max(10)]),
                    V::obj(['ids' => V::arr(V::uuid())->min(1)->max(200), 'action' => V::literal('archive')]),
                ]),
                'handler' => fn(Ctx $c) => new Reply(LeadsService::bulk($c->actor, $c->body), 200)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'leads:create', 'idempotent' => true,
                'body' => V::obj(array_merge([
                    'contactId' => V::uuid()->optional(),
                    'contact' => V::obj(['firstName' => self::text(100)->min(1), 'lastName' => self::text(100)->nullish(), 'email' => self::text(254)->nullish(), 'phone' => self::text(40)->nullish()])->optional(),
                    'pipelineId' => V::uuid()->optional(), 'stageId' => V::uuid()->optional(), 'ownerId' => V::uuid()->nullish(), 'source' => V::enum(Routing::LEAD_SOURCES)->optional(), 'allowDuplicate' => V::bool()->optional(),
                ], self::requirement())),
                'handler' => function (Ctx $c) { $l = LeadsService::create($c->actor, $c->body); return new Reply($l, 201, null, self::etag($l)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'leads:read', 'params' => $id,
                'handler' => function (Ctx $c) { $l = LeadsService::get($c->actor, $c->params['id']); return new Reply($l, 200, null, self::etag($l)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'leads:update', 'params' => $id,
                'body' => V::obj(array_merge(['source' => V::enum(Routing::LEAD_SOURCES)->optional()], self::requirement())),
                'handler' => function (Ctx $c) { $l = LeadsService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($l, 200, null, self::etag($l)); }],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'leads:delete', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { LeadsService::archive($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'POST', 'path' => '/{id}/restore', 'perm' => 'leads:delete', 'params' => $id, 'status' => 200, 'handler' => fn(Ctx $c) => new Reply(LeadsService::restore($c->actor, $c->params['id']), 200)],
            ['method' => 'POST', 'path' => '/{id}/stage', 'perm' => 'leads:update', 'params' => $id, 'status' => 200,
                'body' => V::obj([
                    'stageId' => V::uuid(), 'reason' => self::text(300)->nullish(), 'lossReason' => self::text(300)->nullish(), 'reasonCode' => V::str()->max(40)->nullish(),
                    'deal' => V::obj(['reservationId' => V::uuid()->optional(), 'propertyId' => V::uuid()->optional(), 'dealValue' => V::num()->min(0.0000001)->max(1e13)])->optional(),
                ]),
                'handler' => function (Ctx $c) { $l = LeadsService::move($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($l, 200, null, self::etag($l)); }],
            ['method' => 'POST', 'path' => '/{id}/assign', 'perm' => 'leads:assign', 'params' => $id, 'status' => 200, 'body' => V::obj(['ownerId' => V::uuid()->nullable()]),
                'handler' => fn(Ctx $c) => new Reply(LeadsService::assign($c->actor, $c->params['id'], $c->body['ownerId']), 200)],
            ['method' => 'GET', 'path' => '/{id}/handlers', 'perm' => 'leads:read', 'params' => $id, 'handler' => fn(Ctx $c) => LeadsService::handlers($c->actor, $c->params['id'])],
            ['method' => 'PUT', 'path' => '/{id}/handlers/{userId}', 'perm' => 'leads:assign', 'params' => V::obj(['id' => V::uuid(), 'userId' => V::uuid()]), 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(LeadsService::addHandler($c->actor, $c->params['id'], $c->params['userId']), 200)],
            ['method' => 'DELETE', 'path' => '/{id}/handlers/{userId}', 'perm' => 'leads:assign', 'params' => V::obj(['id' => V::uuid(), 'userId' => V::uuid()]), 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(LeadsService::removeHandler($c->actor, $c->params['id'], $c->params['userId']), 200)],
            ['method' => 'POST', 'path' => '/{id}/claim', 'perm' => 'leads:claim', 'params' => $id, 'status' => 200, 'handler' => fn(Ctx $c) => new Reply(LeadsService::claim($c->actor, $c->params['id']), 200)],
            ['method' => 'GET', 'path' => '/{id}/activities', 'perm' => 'activities:read', 'params' => $id,
                'query' => V::obj(['limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional()]),
                'handler' => function (Ctx $c) { $r = LeadsService::activities($c->actor, $c->params['id'], $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'POST', 'path' => '/{id}/activities', 'perm' => 'activities:create', 'params' => $id,
                'body' => V::obj([
                    'type' => V::enum(Vocab::ACTIVITY_TYPES), 'description' => self::text(4000)->min(1), 'direction' => V::enum(Vocab::ACTIVITY_DIRECTIONS)->optional(),
                    'outcome' => V::enum(array_column(Vocab::ACTIVITY_OUTCOMES, 'code'))->optional(), 'occurredAt' => V::datetime()->optional(), 'durationMinutes' => V::int()->min(0)->max(1440)->optional(),
                    'nextFollowupAt' => V::datetime()->nullish(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(LeadsService::logActivity($c->actor, $c->params['id'], $c->body), 201)],
        ]);

        $stageBody = [
            'name' => self::text(60)->min(1), 'color' => V::str()->regex('/^#[0-9a-fA-F]{6}$/', 'Use a hex colour like #6B8F71')->optional(),
            'mapsToStatus' => V::enum(Vocab::STATUSES)->optional(), 'probability' => V::int()->min(0)->max(100)->optional(),
        ];
        Router::mount('/api/v1/pipelines', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'pipeline:read', 'handler' => fn(Ctx $c) => PipelinesService::list($c->actor)],
            ['method' => 'GET', 'path' => '/reason-codes', 'perm' => 'pipeline:read', 'handler' => fn(Ctx $c) => Vocab::REASON_CODES],
            ['method' => 'POST', 'path' => '/apply-recommended', 'perm' => 'pipeline:manage', 'status' => 200, 'body' => V::obj(['makeDefault' => V::bool()->optional()]),
                'handler' => function (Ctx $c) { $r = PipelinesService::applyRecommended($c->actor, $c->body); return new Reply($r, $r['created'] ? 201 : 200); }],
            ['method' => 'POST', 'path' => '/', 'perm' => 'pipeline:manage',
                'body' => V::obj(['name' => self::text(80)->min(1), 'description' => self::text(300)->nullish(), 'copyFromId' => V::uuid()->optional(), 'empty' => V::bool()->optional()]),
                'handler' => fn(Ctx $c) => new Reply(PipelinesService::create($c->actor, $c->body), 201)],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'pipeline:read', 'params' => $id, 'handler' => fn(Ctx $c) => PipelinesService::get($c->actor, $c->params['id'])],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'pipeline:manage', 'params' => $id,
                'body' => V::obj(['name' => self::text(80)->min(1)->optional(), 'description' => self::text(300)->nullish(), 'isDefault' => V::bool()->optional(), 'reasonCodesRequired' => V::bool()->optional(), 'dealValueRequired' => V::bool()->optional()]),
                'handler' => fn(Ctx $c) => PipelinesService::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'pipeline:manage', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { PipelinesService::archive($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'POST', 'path' => '/{id}/stages', 'perm' => 'pipeline:manage', 'params' => $id, 'body' => V::obj($stageBody + ['afterStageId' => V::uuid()->nullish()]),
                'handler' => fn(Ctx $c) => new Reply(PipelinesService::addStage($c->actor, $c->params['id'], $c->body), 201)],
            ['method' => 'PATCH', 'path' => '/{id}/stages/{stageId}', 'perm' => 'pipeline:manage', 'params' => V::obj(['id' => V::uuid(), 'stageId' => V::uuid()]),
                'body' => V::obj($stageBody)->partial(),
                'handler' => fn(Ctx $c) => PipelinesService::updateStage($c->actor, $c->params['id'], $c->params['stageId'], $c->body)],
            ['method' => 'DELETE', 'path' => '/{id}/stages/{stageId}', 'perm' => 'pipeline:manage', 'params' => V::obj(['id' => V::uuid(), 'stageId' => V::uuid()]), 'status' => 200,
                'query' => V::obj(['moveLeadsTo' => V::uuid()->optional()]),
                'handler' => fn(Ctx $c) => new Reply(PipelinesService::archiveStage($c->actor, $c->params['id'], $c->params['stageId'], $c->query['moveLeadsTo'] ?? null), 200)],
            ['method' => 'PUT', 'path' => '/{id}/stage-order', 'perm' => 'pipeline:manage', 'params' => $id, 'body' => V::obj(['stageIds' => V::arr(V::uuid())->min(2)->max(25)]),
                'handler' => fn(Ctx $c) => PipelinesService::reorder($c->actor, $c->params['id'], $c->body['stageIds'])],
            ['method' => 'GET', 'path' => '/{id}/transitions', 'perm' => 'pipeline:read', 'params' => $id, 'handler' => fn(Ctx $c) => PipelinesService::transitions($c->actor, $c->params['id'])],
            ['method' => 'PUT', 'path' => '/{id}/transitions', 'perm' => 'pipeline:manage', 'params' => $id,
                'body' => V::obj(['restricted' => V::bool(), 'transitions' => V::arr(V::obj(['fromStageId' => V::uuid(), 'toStageId' => V::uuid(), 'requiredFields' => V::arr(V::enum(Vocab::GATE_FIELDS))->max(count(Vocab::GATE_FIELDS))->default([])]))->max(700)]),
                'handler' => fn(Ctx $c) => PipelinesService::setTransitions($c->actor, $c->params['id'], $c->body)],
            ['method' => 'GET', 'path' => '/{id}/board', 'perm' => ['pipeline:read', 'leads:read'], 'params' => $id,
                'query' => V::obj(['ownerId' => V::union([V::uuid(), V::literal('unassigned')])->optional(), 'q' => self::text(100)->optional(), 'perStage' => V::coerceInt()->min(1)->max(50)->default(20)]),
                'handler' => fn(Ctx $c) => LeadsService::board($c->actor, $c->params['id'], $c->query)],
            ['method' => 'GET', 'path' => '/{id}/forecast', 'perm' => ['pipeline:read', 'leads:read'], 'params' => $id, 'handler' => fn(Ctx $c) => LeadsService::forecast($c->actor, $c->params['id'])],
        ]);

        $entity = V::enum(['lead', 'contact']);
        $filters = fn() => V::record(V::any())->refine(static fn($v) => strlen(json_encode($v)) <= 6000, 'Filters are too large');
        Router::mount('/api/v1/saved-views', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'saved_views:manage', 'query' => V::obj(['entity' => $entity]), 'handler' => fn(Ctx $c) => SavedViews::list($c->actor, $c->query['entity'])],
            ['method' => 'POST', 'path' => '/', 'perm' => 'saved_views:manage',
                'body' => V::obj(['entity' => V::enum(['lead', 'contact']), 'name' => self::text(80)->min(1), 'filters' => $filters(), 'sort' => self::text(40)->nullish(), 'isShared' => V::bool()->optional()]),
                'handler' => fn(Ctx $c) => new Reply(SavedViews::create($c->actor, $c->body), 201)],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'saved_views:manage', 'params' => $id,
                'body' => V::obj(['name' => self::text(80)->min(1)->optional(), 'filters' => $filters()->optional(), 'sort' => self::text(40)->nullish(), 'isShared' => V::bool()->optional()]),
                'handler' => fn(Ctx $c) => SavedViews::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'saved_views:manage', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { SavedViews::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);
    }
}
