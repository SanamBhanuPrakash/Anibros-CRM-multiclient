<?php
declare(strict_types=1);

namespace App\Modules\Viewings;

use App\Core\Ctx;
use App\Core\Etag;
use App\Core\RawReply;
use App\Core\Reply;
use App\Core\Router;
use App\Core\V;

/** /api/v1/viewings */
final class ViewingsRoutes
{
    private static function etag(array $v): array
    {
        return Etag::make((int) $v['version']);
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        $text = static fn(int $max) => V::str()->trim()->max($max);
        Router::mount('/api/v1/viewings', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'viewings:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(),
                    'propertyId' => V::uuid()->optional(), 'leadId' => V::uuid()->optional(), 'status' => V::enum(ViewingsService::STATUSES)->optional(),
                    'from' => V::datetime()->optional(), 'to' => V::datetime()->optional(),
                ]),
                'handler' => function (Ctx $c) { $r = ViewingsService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/outcomes', 'perm' => 'viewings:read', 'handler' => fn(Ctx $c) => ViewingsService::OUTCOMES],
            ['method' => 'POST', 'path' => '/', 'perm' => 'viewings:create', 'idempotent' => true,
                'body' => V::obj([
                    'leadId' => V::uuid(), 'propertyId' => V::uuid(), 'scheduledAt' => V::datetime(), 'durationMinutes' => V::int()->min(5)->max(480)->default(30),
                    'notes' => $text(1000)->nullish(), 'agentId' => V::uuid()->nullish(),
                ]),
                'handler' => function (Ctx $c) { $v = ViewingsService::schedule($c->actor, $c->body); return new Reply($v, 201, null, self::etag($v)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'viewings:read', 'params' => $id,
                'handler' => function (Ctx $c) { $v = ViewingsService::get($c->actor, $c->params['id']); return new Reply($v, 200, null, self::etag($v)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'viewings:update', 'params' => $id,
                'body' => V::obj([
                    'scheduledAt' => V::datetime()->optional(), 'durationMinutes' => V::int()->min(5)->max(480)->optional(), 'notes' => $text(1000)->nullish(), 'agentId' => V::uuid()->nullish(),
                ])->strict(),
                'handler' => function (Ctx $c) { $v = ViewingsService::reschedule($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($v, 200, null, self::etag($v)); }],
            ['method' => 'POST', 'path' => '/{id}/cancel', 'perm' => 'viewings:update', 'params' => $id, 'status' => 200, 'body' => V::obj(['reason' => $text(500)->nullish()]),
                'handler' => fn(Ctx $c) => new Reply(ViewingsService::cancel($c->actor, $c->params['id'], $c->body['reason'] ?? null), 200)],
            ['method' => 'POST', 'path' => '/{id}/no-show', 'perm' => 'viewings:update', 'params' => $id, 'status' => 200, 'body' => V::obj(['note' => $text(1000)->nullish()]),
                'handler' => fn(Ctx $c) => new Reply(ViewingsService::noShow($c->actor, $c->params['id'], $c->body['note'] ?? null), 200)],
            ['method' => 'POST', 'path' => '/{id}/complete', 'perm' => 'viewings:update', 'params' => $id, 'status' => 200,
                'body' => V::obj([
                    'outcome' => V::enum(array_column(ViewingsService::OUTCOMES, 'code')), 'note' => $text(1000)->nullish(),
                    'followUp' => V::obj(['dueAt' => V::datetime(), 'title' => $text(200)->nullish()])->nullish(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(ViewingsService::complete($c->actor, $c->params['id'], $c->body), 200)],
            ['method' => 'GET', 'path' => '/{id}/ics', 'perm' => 'viewings:read', 'params' => $id,
                'handler' => function (Ctx $c) {
                    $f = ViewingsService::ics($c->actor, $c->params['id']);
                    return new RawReply($f['body'], 'text/calendar; charset=utf-8', ['Content-Disposition' => 'attachment; filename="' . $f['filename'] . '"', 'Cache-Control' => 'private, no-store']);
                }],
        ]);
    }
}
