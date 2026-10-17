<?php
declare(strict_types=1);

namespace App\Modules\Outbound;

use App\Core\Ctx;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class OutboundRoutes
{
    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $events = V::arr(V::enum(array_keys(Catalog::EVENTS)))->min(1)->max(20);
        Router::mount('/api/v1/webhooks', [
            ['method' => 'GET', 'path' => '/events', 'perm' => 'integrations:read', 'handler' => static fn(Ctx $c) => OutboundService::events($c->actor)],
            ['method' => 'GET', 'path' => '/', 'perm' => 'integrations:read', 'handler' => static fn(Ctx $c) => OutboundService::list($c->actor)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'integrations:manage', 'idempotent' => true, 'body' => V::obj([
                'name' => V::str()->trim()->min(1)->max(100), 'url' => V::str()->trim()->min(8)->max(2000), 'events' => $events,
            ]), 'handler' => static fn(Ctx $c) => new Reply(OutboundService::create($c->actor, $c->body), 201)],
            ['method' => 'GET', 'path' => '/deliveries', 'perm' => 'integrations:manage', 'query' => V::obj([
                'limit' => V::coerceInt()->min(1)->max(100)->default(25), 'cursor' => V::str()->max(512)->optional(),
                'endpointId' => V::uuid()->optional(), 'status' => V::enum(['pending', 'delivered', 'failed', 'dead', 'skipped'])->optional(),
            ]), 'handler' => static function (Ctx $c) {
                $r = OutboundService::deliveries($c->actor, $c->query);
                return new Reply($r['items'], 200, $r['page']);
            }],
            ['method' => 'POST', 'path' => '/deliveries/{id}/retry', 'perm' => 'integrations:manage', 'params' => $id, 'status' => 204,
                'handler' => static function (Ctx $c) { OutboundService::retry($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'integrations:read', 'params' => $id, 'handler' => static fn(Ctx $c) => OutboundService::get($c->actor, $c->params['id'])],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'integrations:manage', 'params' => $id, 'body' => V::obj([
                'name' => V::str()->trim()->min(1)->max(100)->optional(), 'url' => V::str()->trim()->min(8)->max(2000)->optional(),
                'events' => (clone $events)->optional(), 'status' => V::enum(['active', 'paused'])->optional(),
            ])->strict(), 'handler' => static fn(Ctx $c) => OutboundService::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'POST', 'path' => '/{id}/rotate-secret', 'perm' => 'integrations:manage', 'params' => $id,
                'handler' => static fn(Ctx $c) => OutboundService::rotateSecret($c->actor, $c->params['id'])],
            ['method' => 'POST', 'path' => '/{id}/test', 'perm' => 'integrations:manage', 'params' => $id, 'status' => 200,
                'handler' => static fn(Ctx $c) => OutboundService::sendTest($c->actor, $c->params['id'])],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'integrations:delete', 'params' => $id, 'status' => 204,
                'handler' => static function (Ctx $c) { OutboundService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);
    }
}
