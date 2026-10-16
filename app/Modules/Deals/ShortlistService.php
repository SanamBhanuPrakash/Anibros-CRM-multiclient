<?php
declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Tx;
use App\Modules\Leads\Visibility;

/**
 * A buyer's shortlist: the properties a lead is considering. It records interest only: it never reserves, holds or changes a unit.
 * Reading follows the lead's visibility; changing it follows the right to update the lead (a salesperson shortlists for their own buyers).
 */
final class ShortlistService
{
    private const COLUMNS = "i.id, i.lead_id AS leadId, i.property_id AS propertyId, i.status, i.source, i.match_score AS matchScore, i.note, i.created_at AS createdAt,
        p.title AS _title, p.status AS _status, p.price AS _price, p.currency AS _currency, COALESCE(p.location, NULLIF(CONCAT_WS(', ', p.locality, p.city), '')) AS _location,
        p.bedrooms AS _beds, p.property_type AS _type, (p.deleted_at IS NOT NULL) AS _archived,
        (SELECT r.id FROM reservations r WHERE r.tenant_id = i.tenant_id AND r.lead_id = i.lead_id AND r.property_id = i.property_id AND r.status IN ('offered','reserved') LIMIT 1) AS _deal_id,
        (SELECT r.status FROM reservations r WHERE r.tenant_id = i.tenant_id AND r.lead_id = i.lead_id AND r.property_id = i.property_id AND r.status IN ('offered','reserved') LIMIT 1) AS _deal_status";
    private const FROM = 'lead_properties i JOIN properties p ON p.id = i.property_id AND p.tenant_id = i.tenant_id';

    public static function dto(array $r): array
    {
        return [
            'id' => $r['id'], 'leadId' => $r['leadId'], 'propertyId' => $r['propertyId'], 'status' => $r['status'], 'source' => $r['source'],
            'matchScore' => $r['matchScore'] !== null ? (int) $r['matchScore'] : null, 'note' => $r['note'], 'createdAt' => $r['createdAt'],
            'property' => [
                'id' => $r['propertyId'], 'title' => $r['_title'], 'status' => $r['_status'], 'price' => (float) $r['_price'], 'currency' => $r['_currency'], 'location' => $r['_location'],
                'bedrooms' => $r['_beds'] !== null ? (int) $r['_beds'] : null, 'propertyType' => $r['_type'], 'archived' => (bool) $r['_archived'],
            ],
            'deal' => $r['_deal_id'] ? ['id' => $r['_deal_id'], 'status' => $r['_deal_status']] : null,
        ];
    }

    public static function list(Actor $actor, string $leadId): array
    {
        $actor->assertCan('leads:read');
        $actor->assertCan('properties:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $leadId) {
            self::visibleLead($tx, $actor, $leadId);
            return array_map([self::class, 'dto'], $tx->all('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE i.tenant_id = ? AND i.lead_id = ? ORDER BY i.created_at DESC, i.id DESC LIMIT 100', [$actor->tenantId, $leadId]));
        });
    }

    /** Adds a property to a buyer's shortlist. Adding the same property again is not an error: it returns the existing entry (and un-rejects it). */
    public static function add(Actor $actor, array $in): array
    {
        $actor->assertCan('leads:update');
        $actor->assertCan('properties:read');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $lead = $tx->one('SELECT id, owner_id FROM leads WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$in['leadId'], $actor->tenantId]);
            if (!$lead) throw Errors::notFound('Lead');
            $actor->assertCanActOn('leads:update', $lead['owner_id']);
            if (!$tx->exists('SELECT 1 FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$in['propertyId'], $actor->tenantId])) throw Errors::validation([['path' => 'body.propertyId', 'message' => 'Property not found']]);
            $note = isset($in['note']) && trim((string) $in['note']) !== '' ? trim($in['note']) : null;
            $existing = $tx->one('SELECT id, status FROM lead_properties WHERE tenant_id = ? AND lead_id = ? AND property_id = ? FOR UPDATE', [$actor->tenantId, $in['leadId'], $in['propertyId']]);
            $created = false;
            if ($existing) {
                $id = $existing['id'];
                if ($existing['status'] === 'rejected') $tx->run("UPDATE lead_properties SET status = 'shortlisted' WHERE id = ? AND tenant_id = ?", [$id, $actor->tenantId]);
            } else {
                try {
                    $id = $tx->insert('lead_properties', [
                        'tenant_id' => $actor->tenantId, 'lead_id' => $in['leadId'], 'property_id' => $in['propertyId'], 'status' => 'shortlisted', 'source' => $in['source'] ?? 'manual',
                        'match_score' => $in['matchScore'] ?? null, 'note' => $note, 'created_by' => $actor->userId,
                    ]);
                    $created = true;
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                    $id = (string) $tx->val('SELECT id FROM lead_properties WHERE tenant_id = ? AND lead_id = ? AND property_id = ?', [$actor->tenantId, $in['leadId'], $in['propertyId']]);
                }
            }
            if ($created) {
                Audit::log($tx, 'lead.shortlist_add', 'lead', $in['leadId'], null, ['propertyId' => $in['propertyId'], 'source' => $in['source'] ?? 'manual']);
                Events::emit($tx, 'shortlist.changed', $in['leadId'], ['propertyId' => $in['propertyId'], 'change' => 'added']);
            }
            return ['item' => self::fetch($tx, $actor->tenantId, $id), 'created' => $created];
        });
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $actor->assertCan('leads:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch) {
            $row = self::lock($tx, $actor, $id);
            $set = [];
            if (!empty($patch['status'])) $set['status'] = $patch['status'];
            if (array_key_exists('note', $patch)) $set['note'] = $patch['note'] !== null && trim($patch['note']) !== '' ? trim($patch['note']) : null;
            if ($set) $tx->update('lead_properties', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'lead.shortlist_update', 'lead', $row['lead_id'], null, ['propertyId' => $row['property_id']] + $patch);
            Events::emit($tx, 'shortlist.changed', $row['lead_id'], ['propertyId' => $row['property_id'], 'change' => 'updated']);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /** Removing is refused while the buyer has an open offer or reservation on the property: end that first. */
    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('leads:update');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = self::lock($tx, $actor, $id);
            if ($tx->exists("SELECT 1 FROM reservations WHERE tenant_id = ? AND lead_id = ? AND property_id = ? AND status IN ('offered','reserved')", [$actor->tenantId, $row['lead_id'], $row['property_id']])) {
                throw Errors::conflict('This buyer has an open offer or reservation on this property. Close it first.', ['reason' => 'open_deal']);
            }
            $tx->run('DELETE FROM lead_properties WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'lead.shortlist_remove', 'lead', $row['lead_id'], ['propertyId' => $row['property_id']]);
            Events::emit($tx, 'shortlist.changed', $row['lead_id'], ['propertyId' => $row['property_id'], 'change' => 'removed']);
        });
    }

    private static function visibleLead(Tx $tx, Actor $actor, string $leadId): void
    {
        $params = [$actor->tenantId, $leadId];
        $vis = Visibility::lead($actor, 'l', $params);
        if ($vis === false) throw Errors::forbidden();
        if (!$tx->exists("SELECT 1 FROM leads l WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $params)) throw Errors::notFound('Lead');
    }

    private static function lock(Tx $tx, Actor $actor, string $id): array
    {
        $row = $tx->one('SELECT i.id, i.lead_id, i.property_id, l.owner_id FROM lead_properties i JOIN leads l ON l.id = i.lead_id AND l.tenant_id = i.tenant_id WHERE i.id = ? AND i.tenant_id = ? AND l.deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
        if (!$row) throw Errors::notFound('Shortlist entry');
        $actor->assertCanActOn('leads:update', $row['owner_id']);
        return $row;
    }

    private static function fetch(Tx $tx, string $tenantId, string $id): array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE i.tenant_id = ? AND i.id = ?', [$tenantId, $id]);
        if (!$r) throw Errors::notFound('Shortlist entry');
        return self::dto($r);
    }
}
