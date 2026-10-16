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

/**
 * Properties: one sellable or rentable unit, optionally inside a project. Availability follows a state machine; price and availability changes are kept in
 * history (PropertyWriter); while a deal owns a unit its status is not editable by hand.
 */
final class PropertiesService
{
    public const TYPES = ['apartment', 'villa', 'townhouse', 'penthouse', 'office', 'retail', 'plot', 'other'];
    public const LISTING_TYPES = ['sale', 'rent', 'lease'];
    public const STATUSES = ['available', 'under_offer', 'reserved', 'sold', 'rented', 'inactive'];
    public const FURNISHINGS = ['unfurnished', 'semi_furnished', 'furnished'];

    /** Availability state machine. Anything not listed is refused; leaving sold/rented is allowed back to available only, and needs a reason. */
    public const TRANSITIONS = [
        'available' => ['under_offer', 'reserved', 'sold', 'rented', 'inactive'],
        'under_offer' => ['available', 'reserved', 'sold', 'rented', 'inactive'],
        'reserved' => ['available', 'under_offer', 'sold', 'rented'],
        'sold' => ['available'],
        'rented' => ['available'],
        'inactive' => ['available'],
    ];

    private const SORTS = ['created' => ['p.created_at', 'ts'], 'updated' => ['p.updated_at', 'ts'], 'price' => ['p.price', 'num'], 'area' => ['COALESCE(p.area_sqft, 0)', 'num']];

    private const COLUMNS = "p.id, p.project_id AS projectId, pr.name AS projectName, p.title, p.description, p.property_type AS propertyType, p.listing_type AS listingType,
        p.status, p.price, p.currency, p.price_per_sqft AS pricePerSqft, p.negotiable, p.bedrooms, p.bathrooms, p.area_sqft AS areaSqft, p.carpet_area_sqft AS carpetAreaSqft,
        p.unit_number AS unitNumber, p.tower, p.floor, p.facing, p.furnishing, p.address, p.city, p.locality, p.location, p.possession_date AS possessionDate, p.rera_id AS reraId,
        p.amenities, p.tags, p.owner_id AS ownerId, u.name AS ownerName, p.cover_file_id AS coverFileId,
        (SELECT COUNT(*) FROM files f WHERE f.entity_type = 'property' AND f.entity_id = p.id AND f.kind = 'image' AND f.deleted_at IS NULL) AS imageCount,
        p.status_changed_at AS statusChangedAt, p.version, p.created_at AS createdAt, p.updated_at AS updatedAt";
    private const FROM = 'properties p LEFT JOIN projects pr ON pr.id = p.project_id AND pr.tenant_id = p.tenant_id LEFT JOIN users u ON u.id = p.owner_id';

    public static function dto(array $r): array
    {
        return Map::row($r, [
            'json' => ['amenities' => [], 'tags' => []], 'bool' => ['negotiable'], 'float' => ['price', 'pricePerSqft', 'areaSqft', 'carpetAreaSqft'],
            'int' => ['bedrooms', 'bathrooms', 'floor', 'imageCount', 'version'],
        ]);
    }

    private static function blank(?string $v): ?string
    {
        return $v !== null && trim($v) !== '' ? trim($v) : null;
    }

    public static function fetch(Tx $tx, string $tenantId, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE p.id = ? AND p.tenant_id = ? AND p.deleted_at IS NULL', [$id, $tenantId]);
        return $r ? self::dto($r) : null;
    }

    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('properties:read');
        $sig = "properties:{$q['sort']}:{$q['order']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        [$key, $kt] = self::SORTS[$q['sort']];
        $params = [$actor->tenantId];
        $where = ['p.tenant_id = ?', 'p.deleted_at IS NULL'];
        if (!empty($q['projectId'])) { $where[] = 'p.project_id = ?'; $params[] = $q['projectId']; }
        if (!empty($q['status'])) { $where[] = 'p.status IN (?)'; $params[] = $q['status']; }
        if (!empty($q['propertyType'])) { $where[] = 'p.property_type = ?'; $params[] = $q['propertyType']; }
        if (!empty($q['listingType'])) { $where[] = 'p.listing_type = ?'; $params[] = $q['listingType']; }
        if (isset($q['bedroomsMin'])) { $where[] = 'p.bedrooms >= ?'; $params[] = $q['bedroomsMin']; }
        if (isset($q['priceMin'])) { $where[] = 'p.price >= ?'; $params[] = $q['priceMin']; }
        if (isset($q['priceMax'])) { $where[] = 'p.price <= ?'; $params[] = $q['priceMax']; }
        if (isset($q['areaMin'])) { $where[] = 'p.area_sqft >= ?'; $params[] = $q['areaMin']; }
        if (!empty($q['city'])) { $where[] = 'p.city LIKE ?'; $params[] = '%' . Normalize::escapeLike($q['city']) . '%'; }
        if (!empty($q['locality'])) { $where[] = 'p.locality LIKE ?'; $params[] = '%' . Normalize::escapeLike($q['locality']) . '%'; }
        if (!empty($q['ownerId'])) { $where[] = 'p.owner_id = ?'; $params[] = $q['ownerId']; }
        if (!empty($q['tag'])) foreach (Normalize::tags([$q['tag']]) as $t) { $where[] = 'JSON_CONTAINS(p.tags, ?)'; $params[] = Json::enc($t); }
        if (isset($q['q']) && trim($q['q']) !== '') {
            $like = '%' . Normalize::escapeLike(mb_substr(trim($q['q']), 0, 100)) . '%';
            $where[] = '(p.title LIKE ? OR p.locality LIKE ? OR p.city LIKE ? OR p.location LIKE ? OR p.unit_number LIKE ? OR pr.name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $pred = Pagination::predicate($cursor, $params, $key, 'p.id', $q['order'], $kt);
        if ($pred) $where[] = $pred;
        $dir = $q['order'] === 'asc' ? 'ASC' : 'DESC';
        $params[] = $q['limit'] + 1;
        $rows = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->all('SELECT ' . self::COLUMNS . ", $key AS _k FROM " . self::FROM . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $key $dir, p.id $dir LIMIT ?", $params));
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
        return ['items' => array_map(static function ($r) { unset($r['_k']); return self::dto($r); }, $pg['items']), 'page' => $pg['page']];
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('properties:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $p = self::fetch($tx, $actor->tenantId, $id);
            if (!$p) throw Errors::notFound('Property');
            $files = $tx->all(
                "SELECT id, kind, name, mime, size_bytes AS sizeBytes, sort_order AS sortOrder, created_at AS createdAt FROM files
                  WHERE tenant_id = ? AND entity_type = 'property' AND entity_id = ? AND deleted_at IS NULL ORDER BY (id = ?) DESC, sort_order, created_at",
                [$actor->tenantId, $id, $p['coverFileId']]
            );
            $files = array_map(static function ($f) { $f['sizeBytes'] = (int) $f['sizeBytes']; $f['sortOrder'] = (int) $f['sortOrder']; return $f; }, $files);
            $price = array_map(static function ($r) { $r['price'] = (float) $r['price']; return $r; }, $tx->all('SELECT h.price, h.currency, h.created_at AS `at`, u.name AS changedBy FROM property_price_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.property_id = ? AND h.tenant_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 25', [$id, $actor->tenantId]));
            $status = $tx->all('SELECT h.from_status AS `from`, h.to_status AS `to`, h.reason, h.created_at AS `at`, u.name AS changedBy FROM property_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.property_id = ? AND h.tenant_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 25', [$id, $actor->tenantId]);
            // While a deal owns the unit's status, the status buttons are not offered (the server would refuse them anyway)
            $deal = $tx->one(
                "SELECT status FROM reservations WHERE tenant_id = ? AND property_id = ? AND (status IN ('offered','reserved') OR (status = 'completed' AND ? = 1)) ORDER BY (status = 'reserved') DESC LIMIT 1",
                [$actor->tenantId, $id, ($p['status'] === 'sold' || $p['status'] === 'rented') ? 1 : 0]
            );
            $lock = !$deal ? null : ($deal['status'] === 'completed'
                ? 'A sale is recorded against this property. Reopen that lead (a manager can) to reverse it.'
                : ($deal['status'] === 'reserved' ? 'This property is reserved for a buyer. Release the reservation or record the sale from the deal.' : 'This property has an open offer. Close the offer from the deal to change its status.'));
            return $p + [
                'images' => array_values(array_filter($files, static fn($f) => $f['kind'] === 'image')),
                'documents' => array_values(array_filter($files, static fn($f) => $f['kind'] === 'document')),
                'priceHistory' => $price, 'statusHistory' => $status,
                'allowedStatuses' => $lock ? [] : self::TRANSITIONS[$p['status']], 'statusLock' => $lock,
            ];
        });
    }

    public static function summary(Actor $actor): array
    {
        $actor->assertCan('properties:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $byStatus = $tx->all('SELECT status, COUNT(*) AS n FROM properties WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY status', [$actor->tenantId]);
            $byType = $tx->all('SELECT property_type AS type, COUNT(*) AS n FROM properties WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY property_type ORDER BY n DESC', [$actor->tenantId]);
            $avail = $tx->one("SELECT COALESCE(SUM(price), 0) AS value, AVG(price_per_sqft) AS avgPricePerSqft, COUNT(*) AS n FROM properties WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'available'", [$actor->tenantId]);
            $counts = array_fill_keys(self::STATUSES, 0);
            foreach ($byStatus as $r) $counts[$r['status']] = (int) $r['n'];
            return [
                'total' => array_sum($counts), 'byStatus' => $counts, 'byType' => array_map(static fn($r) => ['type' => $r['type'], 'n' => (int) $r['n']], $byType),
                'available' => ['count' => (int) $avail['n'], 'value' => (float) $avail['value'], 'avgPricePerSqft' => $avail['avgPricePerSqft'] !== null ? (float) $avail['avgPricePerSqft'] : null],
            ];
        });
    }

    private static function location(?string $locality, ?string $city): ?string
    {
        $loc = self::blank($locality);
        $c = self::blank($city);
        return $loc && $c ? "$loc, $c" : ($loc ?? $c);
    }

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('properties:create');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            self::assertRefs($tx, $actor, $in['projectId'] ?? null, $in['ownerId'] ?? null);
            $currency = strtoupper($in['currency'] ?? 'INR');
            try {
                $id = $tx->insert('properties', [
                    'tenant_id' => $actor->tenantId, 'project_id' => $in['projectId'] ?? null, 'title' => trim($in['title']), 'description' => self::blank($in['description'] ?? null),
                    'property_type' => $in['propertyType'] ?? 'apartment', 'listing_type' => $in['listingType'] ?? 'sale', 'price' => $in['price'], 'currency' => $currency,
                    'negotiable' => ($in['negotiable'] ?? true) ? 1 : 0, 'bedrooms' => $in['bedrooms'] ?? null, 'bathrooms' => $in['bathrooms'] ?? null, 'area_sqft' => $in['areaSqft'] ?? null,
                    'carpet_area_sqft' => $in['carpetAreaSqft'] ?? null, 'unit_number' => self::blank($in['unitNumber'] ?? null), 'tower' => self::blank($in['tower'] ?? null), 'floor' => $in['floor'] ?? null,
                    'facing' => self::blank($in['facing'] ?? null), 'furnishing' => $in['furnishing'] ?? null, 'address' => self::blank($in['address'] ?? null), 'city' => self::blank($in['city'] ?? null),
                    'locality' => self::blank($in['locality'] ?? null), 'location' => self::location($in['locality'] ?? null, $in['city'] ?? null), 'possession_date' => $in['possessionDate'] ?? null,
                    'rera_id' => self::blank($in['reraId'] ?? null), 'amenities' => Json::enc(array_values(array_filter(array_map('trim', $in['amenities'] ?? []), static fn($a) => $a !== ''))),
                    'tags' => Json::enc(Normalize::tags($in['tags'] ?? [])), 'owner_id' => array_key_exists('ownerId', $in) ? $in['ownerId'] : $actor->userId, 'created_by' => $actor->userId, 'metadata' => '{}',
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_property_unit_live')) throw Errors::conflict('That unit already exists in this project');
                throw $e;
            }
            PropertyWriter::recordListed($tx, $actor->tenantId, $id, 'available', $in['price'], $currency);
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'property.create', 'property', $id, null, ['title' => $dto['title'], 'price' => $dto['price'], 'projectId' => $dto['projectId']]);
            Events::emit($tx, 'property.created', $id, ['projectId' => $dto['projectId']]);
            return $dto;
        });
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('properties:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $cur = $tx->one('SELECT version, status FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Property');
            if ($expectedVersion !== null && $expectedVersion !== (int) $cur['version']) throw Errors::preconditionFailed();
            if (array_key_exists('projectId', $patch) || array_key_exists('ownerId', $patch)) self::assertRefs($tx, $actor, $patch['projectId'] ?? null, $patch['ownerId'] ?? null);
            $before = self::fetch($tx, $actor->tenantId, $id);
            $set = [];
            $plain = ['projectId' => 'project_id', 'propertyType' => 'property_type', 'listingType' => 'listing_type', 'price' => 'price', 'bedrooms' => 'bedrooms', 'bathrooms' => 'bathrooms', 'areaSqft' => 'area_sqft',
                'carpetAreaSqft' => 'carpet_area_sqft', 'floor' => 'floor', 'furnishing' => 'furnishing', 'possessionDate' => 'possession_date', 'ownerId' => 'owner_id'];
            foreach ($plain as $k => $col) if (array_key_exists($k, $patch)) $set[$col] = $patch[$k];
            foreach (['description' => 'description', 'unitNumber' => 'unit_number', 'tower' => 'tower', 'facing' => 'facing', 'address' => 'address', 'city' => 'city', 'locality' => 'locality', 'reraId' => 'rera_id'] as $k => $col) {
                if (array_key_exists($k, $patch)) $set[$col] = self::blank($patch[$k]);
            }
            if (array_key_exists('title', $patch)) $set['title'] = trim($patch['title']);
            if (array_key_exists('currency', $patch)) $set['currency'] = strtoupper($patch['currency']);
            if (array_key_exists('negotiable', $patch)) $set['negotiable'] = $patch['negotiable'] ? 1 : 0;
            if (array_key_exists('amenities', $patch)) $set['amenities'] = Json::enc(array_values(array_filter(array_map('trim', $patch['amenities']), static fn($a) => $a !== '')));
            if (array_key_exists('tags', $patch)) $set['tags'] = Json::enc(Normalize::tags($patch['tags']));
            if (array_key_exists('locality', $patch) || array_key_exists('city', $patch)) {
                $set['location'] = self::location(array_key_exists('locality', $patch) ? $patch['locality'] : $before['locality'], array_key_exists('city', $patch) ? $patch['city'] : $before['city']);
            }
            if (array_key_exists('listingType', $patch)) {
                if ($before['status'] === 'sold' && $patch['listingType'] !== 'sale') throw Errors::conflict('A sold property cannot be switched to rent or lease. Make it available first.');
                if ($before['status'] === 'rented' && $patch['listingType'] === 'sale') throw Errors::conflict('A rented property cannot be switched to sale. Make it available first.');
            }
            try {
                if ($set) PropertyWriter::update($tx, $actor->tenantId, $id, $set);
                else $tx->run('UPDATE properties SET version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_property_unit_live')) throw Errors::conflict('That unit already exists in this project');
                throw $e;
            }
            $after = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'property.update', 'property', $id, $before, $after);
            Events::emit($tx, 'property.updated', $id, ['version' => $after['version'], 'priceChanged' => $after['price'] !== $before['price']]);
            return $after;
        });
    }

    public static function changeStatus(Actor $actor, string $id, string $to, ?string $reason = null): array
    {
        $actor->assertCan('properties:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $to, $reason) {
            $row = $tx->one('SELECT status, listing_type FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Property');
            $from = $row['status'];
            if ($from === $to) throw Errors::conflict('This property is already ' . str_replace('_', ' ', $to));
            self::assertNoDeal($tx, $actor, $id, $from);
            if (!in_array($to, self::TRANSITIONS[$from], true)) throw Errors::invalidTransition('A ' . str_replace('_', ' ', $from) . ' property can become: ' . implode(', ', array_map(static fn($s) => str_replace('_', ' ', $s), self::TRANSITIONS[$from])));
            if ($to === 'sold' && $row['listing_type'] !== 'sale') throw Errors::conflict('Only properties listed for sale can be marked sold. Use “rented” for rentals and leases.');
            if ($to === 'rented' && $row['listing_type'] === 'sale') throw Errors::conflict('Only properties listed for rent or lease can be marked rented. Use “sold” for sales.');
            if (($from === 'sold' || $from === 'rented') && ($reason === null || trim($reason) === '')) throw Errors::validation([['path' => 'body.reason', 'message' => 'Say why this ' . ($from === 'sold' ? 'sale' : 'rental') . ' is being reversed']]);
            PropertyWriter::update($tx, $actor->tenantId, $id, ['status' => $to], $reason !== null ? trim($reason) : null);
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'property.status_change', 'property', $id, ['status' => $from], ['status' => $to, 'reason' => $reason]);
            Events::emit($tx, 'property.status_changed', $id, ['from' => $from, 'to' => $to]);
            return $dto;
        });
    }

    /**
     * While a buyer has an open offer or reservation on a unit, or a sale is recorded against it, its status belongs to that deal: changing it by hand would
     * leave the deal and the unit telling different stories. The deal is ended from the deal.
     */
    private static function assertNoDeal(Tx $tx, Actor $actor, string $id, ?string $from = null): void
    {
        $open = $tx->one("SELECT status FROM reservations WHERE tenant_id = ? AND property_id = ? AND status IN ('offered','reserved') ORDER BY (status = 'reserved') DESC LIMIT 1", [$actor->tenantId, $id]);
        if ($open) throw Errors::conflict($open['status'] === 'reserved' ? 'This unit is reserved for a buyer. Release or complete that reservation from the deal instead of changing the status here.' : 'This unit has an open offer. Close the offer from the deal instead of changing the status here.', ['reason' => 'open_deal']);
        if (($from === 'sold' || $from === 'rented') && $tx->exists("SELECT 1 FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'completed'", [$actor->tenantId, $id])) {
            throw Errors::conflict('A sale is recorded against this unit. Reopen that lead (a manager can) to reverse the sale; the unit then becomes available again.', ['reason' => 'recorded_sale']);
        }
    }

    public static function archive(Actor $actor, string $id): void
    {
        $actor->assertCan('properties:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            self::assertNoDeal($tx, $actor, $id);
            $title = $tx->val('SELECT title FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if ($title === null) throw Errors::notFound('Property');
            $tx->run('UPDATE properties SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'property.delete', 'property', $id, ['title' => $title]);
            Events::emit($tx, 'property.updated', $id, ['archived' => true]);
        });
    }

    public static function restore(Actor $actor, string $id): array
    {
        $actor->assertCan('properties:delete');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            try {
                $n = $tx->run('UPDATE properties SET deleted_at = NULL, version = version + 1 WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL', [$id, $actor->tenantId]);
                if (!$n) throw Errors::notFound('Archived property');
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('Another property now uses that unit number in this project');
                throw $e;
            }
            Audit::log($tx, 'property.restore', 'property', $id);
            Events::emit($tx, 'property.updated', $id, ['restored' => true]);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    private static function assertRefs(Tx $tx, Actor $actor, ?string $projectId, ?string $ownerId): void
    {
        if ($projectId && !$tx->exists('SELECT 1 FROM projects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$projectId, $actor->tenantId])) throw Errors::validation([['path' => 'body.projectId', 'message' => 'Project not found']]);
        if ($ownerId && !$tx->exists("SELECT 1 FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND u.is_active = 1", [$actor->tenantId, $ownerId])) {
            throw Errors::validation([['path' => 'body.ownerId', 'message' => 'Listing agent must be an active member of this workspace']]);
        }
    }
}
