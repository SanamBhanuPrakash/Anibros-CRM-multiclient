<?php
declare(strict_types=1);

namespace App\Modules\Inbound;

use App\Core\Ctx;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class InboundRoutes
{
    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $config = V::any()->optional();
        $shape = [
            'provider' => V::enum(Providers::PROVIDERS)->optional(),
            'name' => V::str()->trim()->min(1)->max(120)->optional(),
            'status' => V::enum(['active', 'paused'])->optional(),
            'config' => $config,
            'appSecret' => V::str()->max(500)->optional(),
            'pageAccessToken' => V::str()->max(5000)->nullable()->optional(),
        ];
        Router::mount('/api/v1/lead-sources', [
            [
                'method' => 'GET',
                'path' => '/',
                'perm' => 'integrations:read',
                'handler' => fn(Ctx $c) => InboundService::list($c->actor, $c->req),
            ],
            [
                'method' => 'POST',
                'path' => '/',
                'perm' => 'integrations:manage',
                'body' => V::obj($shape),
                'handler' => fn(Ctx $c) => InboundService::create($c->actor, $c->req, $c->body),
            ],
            [
                'method' => 'GET',
                'path' => '/events',
                'perm' => 'integrations:manage',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(100)->default(25),
                    'cursor' => V::str()->max(512)->optional(),
                    'endpointId' => V::uuid()->optional(),
                    'status' => V::enum(Providers::STATUSES)->optional(),
                ]),
                'handler' => function (Ctx $c) {
                    $page = InboundService::events($c->actor, $c->query);
                    return new Reply($page['items'], 200, $page['page']);
                },
            ],
            [
                'method' => 'PATCH',
                'path' => '/{id}',
                'perm' => 'integrations:manage',
                'params' => $id,
                'body' => V::obj($shape)->partial(),
                'handler' => fn(Ctx $c) => InboundService::update($c->actor, $c->req, $c->params['id'], $c->body),
            ],
            [
                'method' => 'DELETE',
                'path' => '/{id}',
                'perm' => 'integrations:delete',
                'params' => $id,
                'handler' => function (Ctx $c) {
                    InboundService::remove($c->actor, $c->params['id']);
                    return new Reply(null, 204);
                },
            ],
            [
                'method' => 'POST',
                'path' => '/{id}/rotate-secret',
                'perm' => 'integrations:manage',
                'params' => $id,
                'handler' => fn(Ctx $c) => InboundService::rotateSecret($c->actor, $c->req, $c->params['id']),
            ],
            [
                'method' => 'POST',
                'path' => '/{id}/test',
                'perm' => 'integrations:manage',
                'params' => $id,
                'handler' => fn(Ctx $c) => InboundService::sendTest($c->actor, $c->params['id']),
            ],
            [
                'method' => 'POST',
                'path' => '/events/{id}/retry',
                'perm' => 'integrations:manage',
                'params' => $id,
                'handler' => function (Ctx $c) {
                    InboundService::retry($c->actor, $c->params['id']);
                    return ['ok' => true];
                },
            ],
            [
                'method' => 'POST',
                'path' => '/events/{id}/ignore',
                'perm' => 'integrations:manage',
                'params' => $id,
                'handler' => function (Ctx $c) {
                    InboundService::ignore($c->actor, $c->params['id']);
                    return ['ok' => true];
                },
            ],
        ]);
    }
}
