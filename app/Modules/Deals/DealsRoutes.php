<?php
declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Ctx;
use App\Core\Errors;
use App\Core\Etag;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

/** /api/v1/reservations (offers, reservations and sales) and /api/v1/shortlist */
final class DealsRoutes
{
    private static function etag(array $r): array
    {
        return Etag::make((int) $r['version']);
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $money = static fn() => V::num()->min(0)->max(1e13);
        $text = static fn(int $max) => V::str()->trim()->max($max);
        $day = static fn() => V::str()->regex('/^\d{4}-\d{2}-\d{2}$/', 'Use a date like 2026-10-14');
        $ifMatch = static fn(Ctx $c) => Etag::ifMatchVersion($c->req->header('if-match'));

        Router::mount('/api/v1/reservations', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'reservations:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(), 'leadId' => V::uuid()->optional(), 'propertyId' => V::uuid()->optional(),
                    'status' => V::str()->max(80)->optional(), 'completedFrom' => $day()->optional(), 'completedTo' => $day()->optional(), 'test' => V::enum(['exclude'])->optional(),
                ]),
                'handler' => function (Ctx $c) {
                    $q = $c->query;
                    $status = isset($q['status']) && $q['status'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $q['status'])))) : null;
                    foreach ($status ?? [] as $s) {
                        if (!in_array($s, DealsService::STATUSES, true)) throw Errors::validation([['path' => 'query.status', 'message' => 'Use any of: ' . implode(', ', DealsService::STATUSES)]]);
                    }
                    $q['status'] = $status;
                    $r = DealsService::list($c->actor, $q);
                    return new Reply($r['items'], 200, $r['page']);
                }],
            ['method' => 'GET', 'path' => '/close-reasons', 'perm' => 'reservations:read', 'handler' => fn(Ctx $c) => DealsService::CLOSE_REASONS],
            ['method' => 'GET', 'path' => '/by-property/{propertyId}', 'perm' => 'properties:read', 'params' => V::obj(['propertyId' => V::uuid()]),
                'handler' => fn(Ctx $c) => DealsService::holder($c->actor, $c->params['propertyId'])],
            ['method' => 'POST', 'path' => '/', 'perm' => 'reservations:create', 'idempotent' => true,
                'body' => V::obj(['leadId' => V::uuid(), 'propertyId' => V::uuid(), 'offerPrice' => $money()->nullish(), 'holdUntil' => V::datetime()->nullish(), 'note' => $text(1000)->nullish()]),
                'handler' => function (Ctx $c) { $r = DealsService::offer($c->actor, $c->body); return new Reply($r, 201, null, self::etag($r['reservation'])); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'reservations:read', 'params' => $id,
                'handler' => function (Ctx $c) { $r = DealsService::get($c->actor, $c->params['id']); return new Reply($r, 200, null, self::etag($r)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'reservations:update', 'params' => $id,
                'body' => V::obj(['offerPrice' => $money()->nullish(), 'agreedPrice' => $money()->nullish(), 'holdUntil' => V::datetime()->nullish(), 'note' => $text(1000)->nullish()])->strict(),
                'handler' => function (Ctx $c) use ($ifMatch) { $r = DealsService::update($c->actor, $c->params['id'], $c->body, $ifMatch($c)); return new Reply($r, 200, null, self::etag($r)); }],
            ['method' => 'POST', 'path' => '/{id}/reserve', 'perm' => 'reservations:update', 'params' => $id, 'status' => 200,
                'body' => V::obj(['tokenAmount' => $money(), 'agreedPrice' => V::num()->min(0.01)->max(1e13), 'holdUntil' => V::datetime()->nullish(), 'note' => $text(1000)->nullish()]),
                'handler' => function (Ctx $c) use ($ifMatch) { $r = DealsService::reserve($c->actor, $c->params['id'], $c->body, $ifMatch($c)); return new Reply($r, 200, null, self::etag($r['reservation'])); }],
            ['method' => 'POST', 'path' => '/{id}/complete', 'perm' => 'reservations:update', 'params' => $id, 'status' => 200,
                'body' => V::obj(['dealValue' => V::num()->min(0.01)->max(1e13)]),
                'handler' => function (Ctx $c) use ($ifMatch) { $r = DealsService::complete($c->actor, $c->params['id'], $c->body, $ifMatch($c)); return new Reply($r, 200, null, self::etag($r['reservation'])); }],
            ['method' => 'POST', 'path' => '/{id}/close', 'perm' => 'reservations:update', 'params' => $id, 'status' => 200,
                'body' => V::obj(['reason' => V::enum(array_column(DealsService::CLOSE_REASONS, 'code')), 'note' => $text(500)->nullish()]),
                'handler' => function (Ctx $c) use ($ifMatch) { $r = DealsService::close($c->actor, $c->params['id'], $c->body, $ifMatch($c)); return new Reply($r, 200, null, self::etag($r['reservation'])); }],
        ]);

        Router::mount('/api/v1/shortlist', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'leads:read', 'query' => V::obj(['leadId' => V::uuid()]), 'handler' => fn(Ctx $c) => ShortlistService::list($c->actor, $c->query['leadId'])],
            ['method' => 'POST', 'path' => '/', 'perm' => 'leads:update',
                'body' => V::obj(['leadId' => V::uuid(), 'propertyId' => V::uuid(), 'source' => V::enum(DealsService::INTEREST_SOURCES)->default('manual'), 'matchScore' => V::int()->min(0)->max(100)->nullish(), 'note' => $text(500)->nullish()]),
                'handler' => function (Ctx $c) { $r = ShortlistService::add($c->actor, $c->body); return new Reply($r['item'], $r['created'] ? 201 : 200); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'leads:update', 'params' => $id,
                'body' => V::obj(['status' => V::enum(DealsService::INTEREST_STATUSES)->optional(), 'note' => $text(500)->nullish()])->strict(),
                'handler' => fn(Ctx $c) => ShortlistService::update($c->actor, $c->params['id'], $c->body)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'leads:update', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { ShortlistService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);
    }
}
