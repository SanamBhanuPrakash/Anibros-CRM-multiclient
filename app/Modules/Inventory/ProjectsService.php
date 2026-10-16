<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Map;
use App\Core\Pagination;
use App\Core\Tx;
use App\Modules\Contacts\Normalize;

/** Projects (developments / buildings) with live unit counts and the price range of what is still available. */
final class ProjectsService
{
    public const STATUSES = ['upcoming', 'active', 'on_hold', 'completed'];

    private const COLUMNS = "pr.id, pr.name, pr.description, pr.developer, pr.city, pr.locality, pr.address, pr.rera_id AS reraId, pr.possession_date AS possessionDate,
        pr.amenities, pr.status, pr.version, pr.created_at AS createdAt, pr.updated_at AS updatedAt,
        COALESCE(s.total, 0) AS _total, COALESCE(s.available, 0) AS _available, COALESCE(s.held, 0) AS _held, COALESCE(s.closed, 0) AS _closed, COALESCE(s.inactive, 0) AS _inactive,
        s.min_price AS _min, s.max_price AS _max, COALESCE(s.available_value, 0) AS _value";
    private const FROM = "projects pr LEFT JOIN (
        SELECT p.project_id, p.tenant_id, COUNT(*) AS total,
               SUM(p.status = 'available') AS available,
               SUM(p.status IN ('under_offer', 'reserved')) AS held,
               SUM(p.status IN ('sold', 'rented')) AS closed,
               SUM(p.status = 'inactive') AS inactive,
               MIN(CASE WHEN p.status = 'available' THEN p.price END) AS min_price,
               MAX(CASE WHEN p.status = 'available' THEN p.price END) AS max_price,
               COALESCE(SUM(CASE WHEN p.status = 'available' THEN p.price END), 0) AS available_value
          FROM properties p WHERE p.deleted_at IS NULL AND p.project_id IS NOT NULL GROUP BY p.project_id, p.tenant_id) s
        ON s.project_id = pr.id AND s.tenant_id = pr.tenant_id";

    private static function blank(?string $v): ?string
    {
        return $v !== null && trim($v) !== '' ? trim($v) : null;
    }

    public static function dto(array $row): array
    {
        $r = Map::row($row, ['json' => ['amenities' => []], 'int' => ['version', '_total', '_available', '_held', '_closed', '_inactive'], 'float' => ['_min', '_max', '_value']]);
        $units = ['total' => $r['_total'], 'available' => $r['_available'], 'held' => $r['_held'], 'closed' => $r['_closed'], 'inactive' => $r['_inactive'], 'minPrice' => $r['_min'], 'maxPrice' => $r['_max'], 'availableValue' => $r['_value']];
        unset($r['_total'], $r['_available'], $r['_held'], $r['_closed'], $r['_inactive'], $r['_min'], $r['_max'], $r['_value'], $r['_k']);
        return $r + ['units' => $units];
    }

    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('projects:read');
        $sig = "projects:{$q['sort']}:{$q['order']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        $params = [$actor->tenantId];
        $where = ['pr.tenant_id = ?', 'pr.deleted_at IS NULL'];
        if (!empty($q['status'])) { $where[] = 'pr.status = ?'; $params[] = $q['status']; }
        if (!empty($q['city'])) { $where[] = 'pr.city LIKE ?'; $params[] = '%' . Normalize::escapeLike($q['city']) . '%'; }
        if (isset($q['q']) && trim($q['q']) !== '') {
            $like = '%' . Normalize::escapeLike(mb_substr(trim($q['q']), 0, 100)) . '%';
            $where[] = '(pr.name LIKE ? OR pr.developer LIKE ? OR pr.locality LIKE ? OR pr.city LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        [$key, $kt] = $q['sort'] === 'name' ? ['LOWER(pr.name)', 'str'] : ['pr.created_at', 'ts'];
        $pred = Pagination::predicate($cursor, $params, $key, 'pr.id', $q['order'], $kt);
        if ($pred) $where[] = $pred;
        $dir = $q['order'] === 'asc' ? 'ASC' : 'DESC';
        $params[] = $q['limit'] + 1;
        $rows = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->all('SELECT ' . self::COLUMNS . ", $key AS _k FROM " . self::FROM . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $key $dir, pr.id $dir LIMIT ?", $params));
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
        return ['items' => array_map([self::class, 'dto'], $pg['items']), 'page' => $pg['page']];
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('projects:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $p = self::fetch($tx, $actor->tenantId, $id);
            if (!$p) throw Errors::notFound('Project');
            return $p;
        });
    }

    public static function fetch(Tx $tx, string $tenantId, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE pr.id = ? AND pr.tenant_id = ? AND pr.deleted_at IS NULL', [$id, $tenantId]);
        return $r ? self::dto($r) : null;
    }

    private static function cleanList(array $items): string
    {
        return Json::enc(array_values(array_filter(array_map('trim', $items), static fn($a) => $a !== '')));
    }

    private static function nameClash(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_project_name_live');
    }

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('projects:create');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            try {
                $id = $tx->insert('projects', [
                    'tenant_id' => $actor->tenantId, 'name' => trim($in['name']), 'description' => self::blank($in['description'] ?? null), 'developer' => self::blank($in['developer'] ?? null),
                    'city' => self::blank($in['city'] ?? null), 'locality' => self::blank($in['locality'] ?? null), 'address' => self::blank($in['address'] ?? null),
                    'rera_id' => self::blank($in['reraId'] ?? null), 'possession_date' => $in['possessionDate'] ?? null, 'amenities' => self::cleanList($in['amenities'] ?? []),
                    'status' => $in['status'] ?? 'active', 'created_by' => $actor->userId,
                ]);
            } catch (\PDOException $e) {
                if (self::nameClash($e)) throw Errors::conflict('You already have a project with that name');
                throw $e;
            }
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'project.create', 'project', $id, null, ['name' => $dto['name']]);
            Events::emit($tx, 'project.updated', $id);
            return $dto;
        });
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('projects:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $cur = $tx->one('SELECT version FROM projects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Project');
            if ($expectedVersion !== null && $expectedVersion !== (int) $cur['version']) throw Errors::preconditionFailed();
            $before = self::fetch($tx, $actor->tenantId, $id);
            $set = [];
            if (array_key_exists('name', $patch)) $set['name'] = trim($patch['name']);
            foreach (['description' => 'description', 'developer' => 'developer', 'city' => 'city', 'locality' => 'locality', 'address' => 'address', 'reraId' => 'rera_id'] as $k => $col) {
                if (array_key_exists($k, $patch)) $set[$col] = self::blank($patch[$k]);
            }
            if (array_key_exists('possessionDate', $patch)) $set['possession_date'] = $patch['possessionDate'];
            if (array_key_exists('amenities', $patch)) $set['amenities'] = self::cleanList($patch['amenities']);
            if (array_key_exists('status', $patch)) $set['status'] = $patch['status'];
            if ($set) {
                try {
                    $tx->update('projects', $set + ['version' => new \App\Core\Raw('version + 1')], 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
                } catch (\PDOException $e) {
                    if (self::nameClash($e)) throw Errors::conflict('You already have a project with that name');
                    throw $e;
                }
            }
            $after = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'project.update', 'project', $id, $before, $after);
            Events::emit($tx, 'project.updated', $id, ['version' => $after['version']]);
            return $after;
        });
    }

    public static function archive(Actor $actor, string $id): void
    {
        $actor->assertCan('projects:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $cur = $tx->one('SELECT name FROM projects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Project');
            $n = (int) $tx->val('SELECT COUNT(*) FROM properties WHERE project_id = ? AND tenant_id = ? AND deleted_at IS NULL', [$id, $actor->tenantId]);
            if ($n > 0) throw Errors::conflict("This project still has $n propert" . ($n === 1 ? 'y' : 'ies') . '. Archive or move them first.', ['reason' => 'project_in_use', 'propertyCount' => $n]);
            $tx->run('UPDATE projects SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'project.delete', 'project', $id, $cur);
            Events::emit($tx, 'project.updated', $id, ['archived' => true]);
        });
    }
}
