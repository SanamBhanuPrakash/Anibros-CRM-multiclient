<?php
declare(strict_types=1);

namespace App\Modules\Tasks;

use App\Core\Actor;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Etag;
use App\Core\Pagination;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Tx;
use App\Core\V;

/** /api/v1/tasks and /api/v1/notifications */
final class TasksRoutes
{
    private static function text(int $max): V
    {
        return V::str()->trim()->max($max);
    }

    /** @return array<string,V> */
    private static function fields(): array
    {
        return [
            'title' => self::text(200)->min(1),
            'description' => self::text(4000)->nullish(),
            'kind' => V::enum(TasksService::KINDS)->optional(),
            'priority' => V::enum(TasksService::PRIORITIES)->optional(),
            'dueDate' => V::datetime()->nullish(),
            'remindBeforeMinutes' => V::int()->min(0)->max(43200)->nullish(),
            'recurrence' => V::obj(['freq' => V::enum(['daily', 'weekly', 'monthly']), 'interval' => V::int()->min(1)->max(30)])->nullish(),
            'assignedTo' => V::uuid()->nullish(),
            'leadId' => V::uuid()->nullish(),
            'contactId' => V::uuid()->nullish(),
        ];
    }

    private static function etag(array $t): array
    {
        return Etag::make((int) $t['version'], $t['isOverdue']);
    }

    // ── notifications (strictly personal: every query is pinned to the caller's own user id) ─────────────────────

    private static function who(Actor $a): string
    {
        if (!$a->userId) throw Errors::forbidden('Notifications belong to people, not API keys');
        return $a->userId;
    }

    private static function listNotifications(Actor $actor, array $q): array
    {
        $actor->assertCan('notifications:read');
        $userId = self::who($actor);
        $sig = 'notifications:' . ($q['unreadOnly'] ? 'unread' : 'all');
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        $params = [$actor->tenantId, $userId];
        $pred = Pagination::predicate($cursor, $params, 'n.created_at', 'n.id', 'desc', 'ts');
        $params[] = $q['limit'] + 1;
        $rows = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->all(
            'SELECT n.id, n.title, n.message, n.type, n.link, n.entity_type AS entityType, n.entity_id AS entityId, n.is_read AS isRead, n.read_at AS readAt, n.created_at AS createdAt
               FROM notifications n WHERE n.tenant_id = ? AND n.user_id = ? ' . ($q['unreadOnly'] ? 'AND n.is_read = 0 ' : '') . ($pred ? "AND $pred " : '') . 'ORDER BY n.created_at DESC, n.id DESC LIMIT ?',
            $params
        ));
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['createdAt']);
        $items = array_map(static function ($r) { $r['isRead'] = (bool) $r['isRead']; return $r; }, $pg['items']);
        return ['items' => $items, 'page' => $pg['page']];
    }

    public static function register(): void
    {
        $id = V::obj(['id' => V::uuid()]);
        Router::mount('/api/v1/tasks', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'tasks:read',
                'query' => V::obj([
                    'limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(), 'q' => self::text(100)->optional(),
                    'assignee' => V::union([V::literal('me'), V::literal('unassigned'), V::literal('any'), V::uuid()])->optional(),
                    'status' => V::enum(['open', 'done', 'all'])->default('open'), 'priority' => V::enum(TasksService::PRIORITIES)->optional(), 'kind' => V::enum(TasksService::KINDS)->optional(),
                    'leadId' => V::uuid()->optional(), 'contactId' => V::uuid()->optional(), 'due' => V::enum(['overdue', 'today', 'week', 'none'])->optional(),
                    'dueFrom' => V::datetime()->optional(), 'dueTo' => V::datetime()->optional(),
                    'sort' => V::enum(['due', 'created', 'priority'])->default('due'), 'order' => V::enum(['asc', 'desc'])->default('asc'),
                ]),
                'handler' => function (Ctx $c) { $r = TasksService::list($c->actor, $c->query); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/summary', 'perm' => 'tasks:read', 'handler' => fn(Ctx $c) => TasksService::summary($c->actor)],
            ['method' => 'POST', 'path' => '/bulk', 'perm' => 'tasks:update', 'status' => 200,
                'body' => V::obj(['ids' => V::arr(V::uuid())->min(1)->max(100), 'action' => V::enum(['complete', 'cancel', 'reassign']), 'assignedTo' => V::uuid()->nullish()]),
                'handler' => fn(Ctx $c) => new Reply(TasksService::bulk($c->actor, $c->body), 200)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'tasks:create', 'idempotent' => true, 'body' => V::obj(self::fields()),
                'handler' => function (Ctx $c) { $t = TasksService::create($c->actor, $c->body); return new Reply($t, 201, null, self::etag($t)); }],
            ['method' => 'GET', 'path' => '/{id}', 'perm' => 'tasks:read', 'params' => $id,
                'handler' => function (Ctx $c) { $t = TasksService::get($c->actor, $c->params['id']); return new Reply($t, 200, null, self::etag($t)); }],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'tasks:update', 'params' => $id,
                'body' => V::obj(array_merge(self::fields(), ['title' => self::text(200)->min(1)->optional(), 'status' => V::enum(['pending', 'in_progress'])->optional()])),
                'handler' => function (Ctx $c) { $t = TasksService::update($c->actor, $c->params['id'], $c->body, Etag::ifMatchVersion($c->req->header('if-match'))); return new Reply($t, 200, null, self::etag($t)); }],
            ['method' => 'POST', 'path' => '/{id}/complete', 'perm' => 'tasks:update', 'params' => $id, 'status' => 200,
                'handler' => fn(Ctx $c) => new Reply(TasksService::complete($c->actor, $c->params['id'], Etag::ifMatchVersion($c->req->header('if-match'))), 200)],
            ['method' => 'POST', 'path' => '/{id}/reopen', 'perm' => 'tasks:update', 'params' => $id, 'status' => 200, 'handler' => fn(Ctx $c) => new Reply(TasksService::reopen($c->actor, $c->params['id']), 200)],
            ['method' => 'POST', 'path' => '/{id}/cancel', 'perm' => 'tasks:update', 'params' => $id, 'status' => 200, 'handler' => fn(Ctx $c) => new Reply(TasksService::cancel($c->actor, $c->params['id']), 200)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'tasks:delete', 'params' => $id, 'status' => 204,
                'handler' => function (Ctx $c) { TasksService::remove($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);

        Router::mount('/api/v1/notifications', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'notifications:read',
                'query' => V::obj(['limit' => V::coerceInt()->min(1)->max(200)->default(50), 'cursor' => V::str()->max(512)->optional(), 'unread' => V::enum(['true', 'false'])->optional()]),
                'handler' => function (Ctx $c) { $r = self::listNotifications($c->actor, ['limit' => $c->query['limit'], 'cursor' => $c->query['cursor'] ?? null, 'unreadOnly' => ($c->query['unread'] ?? '') === 'true']); return new Reply($r['items'], 200, $r['page']); }],
            ['method' => 'GET', 'path' => '/unread-count', 'perm' => 'notifications:read',
                'handler' => function (Ctx $c) {
                    $u = self::who($c->actor);
                    return ['count' => (int) Db::tenant($c->actor)->read(static fn(Tx $tx) => $tx->val('SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND user_id = ? AND is_read = 0', [$c->actor->tenantId, $u]))];
                }],
            ['method' => 'POST', 'path' => '/read', 'perm' => 'notifications:read', 'status' => 200,
                'body' => V::union([V::obj(['ids' => V::arr(V::uuid())->min(1)->max(200)]), V::obj(['all' => V::literal(true)])]),
                'handler' => function (Ctx $c) {
                    $u = self::who($c->actor);
                    $all = isset($c->body['all']);
                    $n = Db::tenant($c->actor)->tx(static fn(Tx $tx) => $tx->run(
                        'UPDATE notifications SET is_read = 1, read_at = NOW(3) WHERE tenant_id = ? AND user_id = ? AND is_read = 0' . ($all ? '' : ' AND id IN (?)'),
                        $all ? [$c->actor->tenantId, $u] : [$c->actor->tenantId, $u, $c->body['ids']]
                    ));
                    return new Reply(['updated' => $n], 200);
                }],
        ]);
    }
}
