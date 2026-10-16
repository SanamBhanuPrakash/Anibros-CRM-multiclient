<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Tx;

/**
 * Saved views: a named filter + sort a person (or the whole team) comes back to ("My hot leads", "Unassigned today").
 * Private by default; managers may share a view with the workspace. Only the owner edits or deletes it.
 */
final class SavedViews
{
    private const MAX_VIEWS = 50;
    private const COLUMNS = 'v.id, v.entity, v.name, v.filters, v.sort, v.is_shared AS isShared, (v.owner_id = ?) AS isMine, u.name AS ownerName, v.updated_at AS updatedAt';

    private static function dto(array $r): array
    {
        $r['filters'] = Json::obj(Json::dec($r['filters'], []));
        $r['isShared'] = (bool) $r['isShared'];
        $r['isMine'] = (bool) $r['isMine'];
        return $r;
    }

    public static function list(Actor $actor, string $entity): array
    {
        $actor->assertCan('saved_views:manage');
        return Db::tenant($actor)->read(static fn(Tx $tx) => array_map([self::class, 'dto'], $tx->all(
            'SELECT ' . self::COLUMNS . ' FROM saved_views v JOIN users u ON u.id = v.owner_id WHERE v.tenant_id = ? AND v.entity = ? AND (v.owner_id = ? OR v.is_shared = 1) ORDER BY (v.owner_id = ?) DESC, v.name',
            [$actor->userId, $actor->tenantId, $entity, $actor->userId, $actor->userId]
        )));
    }

    private static function assertMaySharing(Actor $actor, ?bool $wantsShared): void
    {
        if ($wantsShared && $actor->scopeIfAllowed('leads:bulk') !== 'all') throw Errors::forbidden('Only managers can share a view with the workspace');
    }

    private static function fetch(Tx $tx, Actor $actor, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM saved_views v JOIN users u ON u.id = v.owner_id WHERE v.id = ? AND v.tenant_id = ?', [$actor->userId, $id, $actor->tenantId]);
        return $r ? self::dto($r) : null;
    }

    /** @param array{entity:string,name:string,filters:array,sort?:?string,isShared?:?bool} $in */
    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('saved_views:manage');
        self::assertMaySharing($actor, $in['isShared'] ?? null);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $n = (int) $tx->val('SELECT COUNT(*) FROM saved_views WHERE tenant_id = ? AND owner_id = ? AND entity = ?', [$actor->tenantId, $actor->userId, $in['entity']]);
            if ($n >= self::MAX_VIEWS) throw Errors::conflict('You can keep at most ' . self::MAX_VIEWS . ' saved views. Delete one you no longer use.');
            try {
                $id = $tx->insert('saved_views', ['tenant_id' => $actor->tenantId, 'owner_id' => $actor->userId, 'entity' => $in['entity'], 'name' => trim($in['name']), 'filters' => Json::encObj($in['filters']), 'sort' => $in['sort'] ?? null, 'is_shared' => !empty($in['isShared']) ? 1 : 0]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('You already have a view with that name');
                throw $e;
            }
            Audit::log($tx, 'saved_view.create', 'saved_view', $id, null, ['name' => $in['name'], 'entity' => $in['entity'], 'shared' => !empty($in['isShared'])]);
            return self::fetch($tx, $actor, $id);
        });
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $actor->assertCan('saved_views:manage');
        self::assertMaySharing($actor, $patch['isShared'] ?? null);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch) {
            if (!$tx->exists('SELECT 1 FROM saved_views WHERE id = ? AND tenant_id = ? AND owner_id = ?', [$id, $actor->tenantId, $actor->userId])) throw Errors::notFound('Saved view');
            $set = [];
            if (isset($patch['name'])) $set['name'] = trim($patch['name']);
            if (isset($patch['filters'])) $set['filters'] = Json::encObj($patch['filters']);
            if (array_key_exists('sort', $patch)) $set['sort'] = $patch['sort'];
            if (isset($patch['isShared'])) $set['is_shared'] = $patch['isShared'] ? 1 : 0;
            try {
                if ($set) $tx->update('saved_views', $set, 'id = ? AND tenant_id = ? AND owner_id = ?', [$id, $actor->tenantId, $actor->userId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('You already have a view with that name');
                throw $e;
            }
            Audit::log($tx, 'saved_view.update', 'saved_view', $id, null, $patch);
            return self::fetch($tx, $actor, $id);
        });
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('saved_views:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $name = $tx->val('SELECT name FROM saved_views WHERE id = ? AND tenant_id = ? AND owner_id = ?', [$id, $actor->tenantId, $actor->userId]);
            if ($name === null) throw Errors::notFound('Saved view');
            $tx->run('DELETE FROM saved_views WHERE id = ? AND tenant_id = ? AND owner_id = ?', [$id, $actor->tenantId, $actor->userId]);
            Audit::log($tx, 'saved_view.delete', 'saved_view', $id, ['name' => $name]);
        });
    }
}
