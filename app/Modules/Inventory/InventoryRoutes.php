<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

/** /api/v1/properties, /api/v1/projects, /api/v1/files, /api/v1/matching */
final class InventoryRoutes
{
    private static function text(int $max): V
    {
        return V::str()->trim()->max($max);
    }

    private static function money(): V
    {
        return V::num()->min(0)->max(1e13);
    }

    /** @return array<string,V> */
    private static function propertyFields(): array
    {
        $list = static fn(int $max, int $each) => V::arr(self::text($each)->min(1))->max($max);
        return [
            'projectId' => V::uuid()->nullish(),
            'title' => self::text(200)->min(1),
            'description' => self::text(5000)->nullish(),
            'propertyType' => V::enum(PropertiesService::TYPES)->optional(),
            'listingType' => V::enum(PropertiesService::LISTING_TYPES)->optional(),
            'price' => self::money(),
            'currency' => V::str()->trim()->min(3)->max(3)->optional(),
            'negotiable' => V::bool()->optional(),
            'bedrooms' => V::int()->min(0)->max(50)->nullish(),
            'bathrooms' => V::int()->min(0)->max(50)->nullish(),
            'areaSqft' => V::num()->min(0)->max(10000000)->nullish(),
            'carpetAreaSqft' => V::num()->min(0)->max(10000000)->nullish(),
            'unitNumber' => self::text(40)->nullish(),
            'tower' => self::text(40)->nullish(),
            'floor' => V::int()->min(-5)->max(200)->nullish(),
            'facing' => self::text(40)->nullish(),
            'furnishing' => V::enum(PropertiesService::FURNISHINGS)->nullish(),
            'address' => self::text(300)->nullish(),
            'city' => self::text(100)->nullish(),
            'locality' => self::text(100)->nullish(),
            'possessionDate' => V::dateStr()->nullish(),
            'reraId' => self::text(60)->nullish(),
            'amenities' => $list(60, 60)->optional(),
            'tags' => $list(30, 40)->optional(),
            'ownerId' => V::uuid()->nullish(),
        ];
    }

    private static function pEtag(array $p): array
    {
        return Etag::make((int) $p['version'], $p['imageCount'], $p['coverFileId']);
    }

    private static function vEtag(array $p): array
    {
        return Etag::make((int) $p['version']);
    }

    private static function upload(Ctx $c, string $entity): Reply
    {
        return new Reply(FilesService::upload($c->actor, $entity, $c->params['id'], (string) $c->body, $c->req->header('content-type'), $c->query['name'] ?? null), 201);
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $nameQuery = V::obj(['name' => self::text(200)->optional()]);
        $statusSet = V::str()->optional()
            ->refine(static function ($v) {
                foreach (array_filter(array_map('trim', explode(',', (string) $v))) as $p) if (!in_array($p, PropertiesService::STATUSES, true)) return false;
                return true;
            }, 'Unknown availability status')
            ->transform(static fn($v) => $v === null || $v === '' ? null : array_values(array_filter(array_map('trim', explode(',', (string) $v)))));

        Router::mount('/api/v1/properties', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'properties:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(),
                    'q' => self::text(100)->optional(), 'projectId' => V::uuid()->optional(), 'status' => $statusSet,
                    'propertyType' => V::enum(PropertiesService::TYPES)->optional(), 'listingType' => V::enum(PropertiesService::LISTING_TYPES)->optional(),
                    'bedroomsMin' => V::coerceInt()->min(0)->max(50)->optional(), 'priceMin' => V::coerceNum()->min(0)->optional(), 'priceMax' => V::coerceNum()->min(0)->optional(),
                    'areaMin' => V::coerceNum()->min(0)->optional(), 'city' => self::text(100)->optional(), 'locality' => self::text(100)->optional(),
                    'ownerId' => V::uuid()->optional(), 'tag' => self::text(40)->optional(),
                    'sort' => V::enum(['created', 'updated', 'price', 'area'])->default('created'), 'order' => V::enum(['asc', 'desc'])->default('desc'),
                ]),
                'handler' => function (Ctx $c) { $r = PropertiesService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/summary', 'perm' => 'properties:read', 'handler' => fn(Ctx $c) => PropertiesService::summary($c->actor)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'properties:create', 'idempotent' => true, 'body' => V::obj(self::propertyFields()),
                'handler' => function (Ctx $c) { $p = PropertiesService::create($c->actor, $c->body); return new Reply($p, 201, null, self::pEtag($p)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'properties:read', 'params' => $id,
                'handler' => function (Ctx $c) { $p = PropertiesService::get($c->actor, $c->params['id']); return new Reply($p, 200, null, self::pEtag($p)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'properties:update', 'params' => $id,
                'body' => V::obj(array_merge(self::propertyFields(), ['title' => self::text(200)->min(1)->optional(), 'price' => self::money()->optional()])),
                'handler' => function (Ctx $c) { $p = PropertiesService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($p, 200, null, self::pEtag($p)); }],
            ['method' => 'POST', 'path' => '/{id}/status', 'perm' => 'properties:update', 'params' => $id, 'status' => 200,
                'body' => V::obj(['status' => V::enum(PropertiesService::STATUSES), 'reason' => self::text(300)->nullish()]),
                'handler' => fn(Ctx $c) => new Reply(PropertiesService::changeStatus($c->actor, $c->params['id'], $c->body['status'], $c->body['reason'] ?? null), 200)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'properties:delete', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { PropertiesService::archive($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'POST', 'path' => '/{id}/restore', 'perm' => 'properties:delete', 'params' => $id, 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(PropertiesService::restore($c->actor, $c->params['id']), 200)],
            ['method' => 'GET', 'path' => '/{id}/files', 'perm' => 'properties:read', 'params' => $id, 'handler' => fn(Ctx $c) => FilesService::list($c->actor, 'property', $c->params['id'])],
            ['method' => 'POST', 'path' => '/{id}/files', 'perm' => 'properties:update', 'params' => $id, 'query' => $nameQuery, 'raw' => true, 'rawLimit' => 10 * 1024 * 1024,
                'handler' => fn(Ctx $c) => self::upload($c, 'property')],
            ['method' => 'PUT', 'path' => '/{id}/cover', 'perm' => 'properties:update', 'params' => $id, 'status' => 200,
                'body' => V::obj(['fileId' => V::uuid()->nullable()]),
                'handler' => fn(Ctx $c) => new Reply(FilesService::setCover($c->actor, $c->params['id'], $c->body['fileId']), 200)],
        ]);

        $projectFields = [
            'name' => self::text(150)->min(1), 'description' => self::text(5000)->nullish(), 'developer' => self::text(150)->nullish(), 'city' => self::text(100)->nullish(),
            'locality' => self::text(100)->nullish(), 'address' => self::text(300)->nullish(), 'reraId' => self::text(60)->nullish(), 'possessionDate' => V::dateStr()->nullish(),
            'amenities' => V::arr(self::text(60)->min(1))->max(60)->optional(), 'status' => V::enum(ProjectsService::STATUSES)->optional(),
        ];
        Router::mount('/api/v1/projects', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'projects:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(), 'q' => self::text(100)->optional(),
                    'status' => V::enum(ProjectsService::STATUSES)->optional(), 'city' => self::text(100)->optional(),
                    'sort' => V::enum(['name', 'created'])->default('name'), 'order' => V::enum(['asc', 'desc'])->default('asc'),
                ]),
                'handler' => function (Ctx $c) { $r = ProjectsService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'POST', 'path' => '/', 'perm' => 'projects:create', 'idempotent' => true, 'body' => V::obj($projectFields),
                'handler' => function (Ctx $c) { $p = ProjectsService::create($c->actor, $c->body); return new Reply($p, 201, null, self::vEtag($p)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'projects:read', 'params' => $id,
                'handler' => function (Ctx $c) { $p = ProjectsService::get($c->actor, $c->params['id']); return new Reply($p, 200, null, self::vEtag($p)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'projects:update', 'params' => $id, 'body' => V::obj(array_merge($projectFields, ['name' => self::text(150)->min(1)->optional()])),
                'handler' => function (Ctx $c) { $p = ProjectsService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($p, 200, null, self::vEtag($p)); }],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'projects:delete', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { ProjectsService::archive($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'GET', 'path' => '/{id}/files', 'perm' => 'projects:read', 'params' => $id, 'handler' => fn(Ctx $c) => FilesService::list($c->actor, 'project', $c->params['id'])],
            ['method' => 'POST', 'path' => '/{id}/files', 'perm' => 'projects:update', 'params' => $id, 'query' => $nameQuery, 'raw' => true, 'rawLimit' => 10 * 1024 * 1024,
                'handler' => fn(Ctx $c) => self::upload($c, 'project')],
        ]);

        Router::mount('/api/v1/files', [
            ['method' => 'GET', 'path' => '/{id}', 'perm' => [], 'params' => $id,
                'handler' => function (Ctx $c) {
                    $f = FilesService::read($c->actor, $c->params['id']);
                    $etag = '"' . substr($f['sha256'], 0, 32) . '"';
                    if ($c->req->header('if-none-match') === $etag) return new RawReply('', $f['mime'], ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'], 304);
                    $ascii = str_replace('"', '', (string) preg_replace('/[^\x20-\x7e]/', '_', $f['name']));
                    return new RawReply($f['data'], $f['mime'], [
                        'ETag' => $etag, 'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff',
                        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                        'Cross-Origin-Resource-Policy' => 'same-site',
                        'Content-Disposition' => "inline; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($f['name']),
                    ]);
                }],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => [], 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { FilesService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);

        Router::mount('/api/v1/matching', [
            ['method' => 'POST', 'path' => '/properties', 'perm' => 'matching:use', 'status' => 200,
                'body' => V::obj([
                    'budgetMin' => self::money()->optional(), 'budgetMax' => self::money()->optional(), 'location' => self::text(100)->optional(),
                    'propertyType' => V::enum(PropertiesService::TYPES)->optional(), 'bedrooms' => V::int()->min(0)->max(50)->optional(), 'bathrooms' => V::int()->min(0)->max(50)->optional(),
                    'listingType' => V::enum(PropertiesService::LISTING_TYPES)->optional(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(Matching::match($c->actor, $c->body), 200)],
        ]);
    }
}
