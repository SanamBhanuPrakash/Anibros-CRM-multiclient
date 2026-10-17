<?php
declare(strict_types=1);

namespace App\Modules\Realtime;

use App\Core\Actor;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Events;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Time;
use App\Core\Tx;
use App\Core\V;

/** cPanel-safe replacement for the Node WebSocket gateway: short authenticated polling over the event outbox. */
final class RealtimeRoutes
{
    public static function register(): void
    {
        Router::mount('/api/v1/realtime', [
            ['method' => 'GET', 'path' => '/events', 'perm' => 'notifications:read', 'query' => V::obj([
                'after' => V::coerceInt()->min(0)->optional(),
                'limit' => V::coerceInt()->min(1)->max(200)->default(100),
            ]), 'handler' => static fn(Ctx $c) => new Reply(self::events($c->actor, $c->query))],
        ]);
    }

    /** @return array{events:array<int,array<string,mixed>>,cursor:int} */
    private static function events(Actor $actor, array $q): array
    {
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q): array {
            // The initial browser refresh already fetches authorized data. Returning a current cursor prevents
            // replaying the entire tenant event history merely to establish a polling position.
            if (!array_key_exists('after', $q) || $q['after'] === null) {
                return ['events' => [], 'cursor' => (int) ($tx->val('SELECT COALESCE(MAX(id), 0) FROM domain_events') ?? 0)];
            }
            $rows = $tx->all('SELECT id, type, aggregate_type, aggregate_id, occurred_at FROM domain_events WHERE id > ? ORDER BY id ASC LIMIT ?', [(int) $q['after'], (int) $q['limit']]);
            $events = [];
            $cursor = (int) $q['after'];
            foreach ($rows as $row) {
                $cursor = max($cursor, (int) $row['id']);
                $meta = Events::CATALOG[$row['type']] ?? null;
                // A notification hint must not leak a module the active tenant member cannot read.
                if (!$meta || !$actor->permitted($meta[2])) continue;
                $events[] = ['type' => 'event', 'event' => $row['type'], 'aggregate' => ['type' => $row['aggregate_type'], 'id' => $row['aggregate_id']], 'cursor' => (int) $row['id'], 'at' => Time::iso($row['occurred_at'])];
            }
            return ['events' => $events, 'cursor' => $cursor];
        });
    }
}
