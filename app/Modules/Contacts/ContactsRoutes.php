<?php
declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\Json;
use App\Core\Pagination;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

final class ContactsRoutes
{
    private static function text(int $max): V
    {
        return V::str()->trim()->max($max);
    }

    /** @return array<string,V> */
    private static function bodyShape(): array
    {
        return [
            'firstName' => self::text(100)->min(1),
            'lastName' => self::text(100)->nullish(),
            'email' => self::text(254)->nullish(),
            'phone' => self::text(40)->nullish(),
            'alternateEmails' => V::arr(self::text(254))->max(5)->optional(),
            'alternatePhones' => V::arr(self::text(40))->max(5)->optional(),
            'company' => self::text(150)->nullish(),
            'jobTitle' => self::text(100)->nullish(),
            'city' => self::text(100)->nullish(),
            'ownerId' => V::uuid()->nullish(),
            'tags' => V::arr(self::text(40))->max(30)->optional(),
            'customFields' => V::record(V::any())->refine(static fn($v) => strlen(Json::enc($v)) <= 12000, 'Custom fields are too large (12 kB maximum)')->optional(),
        ];
    }

    private static function etag(array $c): array
    {
        return Etag::make((int) $c['version'], $c['leadCount']);
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        Router::mount('/api/v1/contacts', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'contacts:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(),
                    'q' => self::text(100)->optional(), 'ownerId' => V::uuid()->optional(), 'tag' => self::text(40)->optional(),
                    'sort' => V::enum(['created', 'updated', 'name'])->default('created'), 'order' => V::enum(['asc', 'desc'])->default('desc'),
                ]),
                'handler' => function (Ctx $c) { $r = ContactsService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/lookup', 'perm' => 'contacts:read',
                'query' => V::obj(['email' => self::text(254)->optional(), 'phone' => self::text(40)->optional()]),
                'handler' => fn(Ctx $c) => ContactsService::lookup($c->actor, $c->query)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'contacts:create', 'idempotent' => true, 'body' => V::obj(self::bodyShape()),
                'handler' => function (Ctx $c) { $x = ContactsService::create($c->actor, $c->body); return new Reply($x, 201, null, self::etag($x)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'contacts:read', 'params' => $id,
                'handler' => function (Ctx $c) { $x = ContactsService::get($c->actor, $c->params['id']); return new Reply($x, 200, null, self::etag($x)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'contacts:update', 'params' => $id, 'body' => V::obj(self::bodyShape())->partial(),
                'handler' => function (Ctx $c) { $x = ContactsService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($x, 200, null, self::etag($x)); }],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'contacts:delete', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { ContactsService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
            ['method' => 'POST', 'path' => '/{id}/restore', 'perm' => 'contacts:delete', 'params' => $id, 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(ContactsService::restore($c->actor, $c->params['id']), 200)],
            ['method' => 'POST', 'path' => '/{id}/merge', 'perm' => 'contacts:merge', 'params' => $id, 'status' => 200,
                'body' => V::obj(['duplicateIds' => V::arr(V::uuid())->min(1)->max(5)]),
                'handler' => fn(Ctx $c) => new Reply(ContactsService::merge($c->actor, $c->params['id'], $c->body['duplicateIds']), 200)],
            ['method' => 'POST', 'path' => '/merges/{id}/undo', 'perm' => 'contacts:merge', 'params' => $id, 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(ContactsService::undoMerge($c->actor, $c->params['id']), 200)],
            ['method' => 'GET', 'path' => '/{id}/consents', 'perm' => 'contacts:read', 'params' => $id,
                'handler' => fn(Ctx $c) => ContactsService::consents($c->actor, $c->params['id'])],
            ['method' => 'POST', 'path' => '/{id}/consents', 'perm' => 'contacts:update', 'params' => $id, 'status' => 200,
                'body' => V::obj([
                    'channel' => V::enum(['email', 'sms', 'whatsapp', 'push', 'phone_call']), 'action' => V::enum(['grant', 'revoke']),
                    'source' => self::text(40)->default('manual'), 'legalBasis' => V::enum(['consent', 'legitimate_interest', 'contract'])->optional(),
                    'proofUrl' => V::url()->max(500)->nullish(), 'reason' => self::text(300)->nullish(), 'expiresAt' => V::datetime()->nullish(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(ContactsService::recordConsent($c->actor, $c->params['id'], $c->body), 200)],
        ]);
    }
}
