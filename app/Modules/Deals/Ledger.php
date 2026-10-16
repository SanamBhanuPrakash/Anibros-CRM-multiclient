<?php
declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Notify;
use App\Core\Raw;
use App\Core\Tx;
use App\Modules\Inventory\PropertyWriter;

/**
 * The deal ledger: the only code that records a sale or takes it back. It works inside the caller's transaction and never moves the lead's stage
 * itself (the caller does, in the same transaction), so "Won" and "a recorded deal value tied to a unit" cannot drift apart.
 * Lock order everywhere in the deals code is lead -> property -> reservation, so two people working on the same buyer and unit queue up instead of deadlocking.
 */
final class Ledger
{
    /** Property status changes made by the ledger carry a plain reason in the unit's own history (never a buyer's name). */
    public static function setPropertyStatus(Tx $tx, Actor $actor, string $propertyId, string $to, string $reason): void
    {
        PropertyWriter::update($tx, $actor->tenantId, $propertyId, ['status' => $to], $reason);
        Events::emit($tx, 'property.status_changed', $propertyId, ['to' => $to, 'reason' => $reason]);
    }

    /** @return array{id:string,title:string,status:string,listing_type:string,currency:string,price:float} */
    public static function lockProperty(Tx $tx, Actor $actor, string $propertyId): array
    {
        $p = $tx->one('SELECT id, title, status, listing_type, currency, price FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$propertyId, $actor->tenantId]);
        if (!$p) throw Errors::validation([['path' => 'body.propertyId', 'message' => 'Property not found']]);
        $p['price'] = (float) $p['price'];
        return $p;
    }

    public static function activity(Tx $tx, Actor $actor, string $leadId, string $description, array $metadata): void
    {
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $actor->userId, 'type' => 'note', 'description' => $description, 'metadata' => Json::enc($metadata), 'created_at' => new Raw('NOW(3)')]);
    }

    /** Ends the other buyers' open offers on a unit that has just been sold, and tells their owners. */
    private static function closeCompetingOffers(Tx $tx, Actor $actor, string $propertyId, string $exceptId, string $title): void
    {
        $rows = $tx->all("SELECT id, lead_id FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'offered' AND id <> ? FOR UPDATE", [$actor->tenantId, $propertyId, $exceptId]);
        foreach ($rows as $row) {
            $tx->run("UPDATE reservations SET status = 'closed', closed_reason = 'unit_taken', closed_at = NOW(3), closed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$actor->userId, $row['id'], $actor->tenantId]);
            self::activity($tx, $actor, $row['lead_id'], "Offer closed: $title went to another buyer", ['reservationId' => $row['id'], 'propertyId' => $propertyId]);
            $owner = $tx->val('SELECT owner_id FROM leads WHERE id = ? AND tenant_id = ?', [$row['lead_id'], $actor->tenantId]);
            if ($owner && $owner !== $actor->userId) {
                Notify::send($tx, ['userId' => $owner, 'title' => 'An offer was closed', 'message' => "$title went to another buyer.", 'type' => 'reservation_closed', 'link' => '/leads/' . $row['lead_id'], 'entityType' => 'reservation', 'entityId' => $row['id'], 'dedupeKey' => 'reservation-closed:' . $row['id']]);
            }
            Events::emit($tx, 'reservation.closed', $row['id'], ['leadId' => $row['lead_id'], 'propertyId' => $propertyId, 'reason' => 'unit_taken']);
        }
    }

    /**
     * Records the sale: reservation -> completed with the deal value, the unit -> sold/rented, competing offers closed. Returns the reservation id.
     * The lead must already be locked by the caller.
     *
     * @param array{id:string,owner_id:?string} $lead
     * @param array{reservationId?:?string,propertyId?:?string,dealValue:float} $deal
     */
    public static function completeDeal(Tx $tx, Actor $actor, array $lead, array $deal): string
    {
        if (!($deal['dealValue'] > 0) || !is_finite((float) $deal['dealValue'])) throw Errors::validation([['path' => 'body.deal.dealValue', 'message' => 'Enter the final deal value (more than zero)']]);
        $propertyId = $deal['propertyId'] ?? null;
        if (!empty($deal['reservationId'])) {
            $r = $tx->one('SELECT property_id, lead_id FROM reservations WHERE id = ? AND tenant_id = ?', [$deal['reservationId'], $actor->tenantId]);
            if (!$r || $r['lead_id'] !== $lead['id']) throw Errors::validation([['path' => 'body.deal.reservationId', 'message' => 'That reservation does not belong to this lead']]);
            if ($propertyId && $propertyId !== $r['property_id']) throw Errors::validation([['path' => 'body.deal.propertyId', 'message' => 'That is not the unit of this reservation']]);
            $propertyId = $r['property_id'];
        }
        if (!$propertyId) throw Errors::validation([['path' => 'body.deal.propertyId', 'message' => 'Choose the unit that was sold']]);

        $prop = self::lockProperty($tx, $actor, $propertyId);
        $open = $tx->one("SELECT id, status FROM reservations WHERE tenant_id = ? AND lead_id = ? AND property_id = ? AND status IN ('offered','reserved') FOR UPDATE", [$actor->tenantId, $lead['id'], $propertyId]);
        if (!empty($deal['reservationId']) && (!$open || $open['id'] !== $deal['reservationId'])) throw Errors::conflict('That offer or reservation is no longer open', ['reason' => 'reservation_not_open']);

        if ($open && $open['status'] === 'reserved') {
            if ($prop['status'] !== 'reserved') throw Errors::conflict('This unit is ' . str_replace('_', ' ', $prop['status']) . ', not reserved. Check the unit before recording the sale.');
        } elseif ($prop['status'] === 'reserved') {
            $holder = $tx->exists("SELECT 1 FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'reserved'", [$actor->tenantId, $propertyId]);
            if ($holder) throw Errors::conflict('This unit is reserved for another buyer', ['reason' => 'already_reserved']);
            // reserved by hand, with no reservation on record: allowed, the person recording the sale is the one who knows
        } elseif (!in_array($prop['status'], ['available', 'under_offer'], true)) {
            throw Errors::unprocessable('This unit is ' . str_replace('_', ' ', $prop['status']) . ', so a sale cannot be recorded against it', ['reason' => 'unit_unavailable']);
        }

        if ($open) {
            $id = $open['id'];
            $tx->run("UPDATE reservations SET status = 'completed', deal_value = ?, completed_at = NOW(3), completed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$deal['dealValue'], $actor->userId, $id, $actor->tenantId]);
        } else {
            $id = $tx->insert('reservations', ['tenant_id' => $actor->tenantId, 'lead_id' => $lead['id'], 'property_id' => $propertyId, 'status' => 'completed', 'deal_value' => $deal['dealValue'], 'currency' => $prop['currency'], 'completed_at' => new Raw('NOW(3)'), 'completed_by' => $actor->userId, 'created_by' => $actor->userId]);
        }
        $sale = $prop['listing_type'] === 'sale';
        self::setPropertyStatus($tx, $actor, $propertyId, $sale ? 'sold' : 'rented', $sale ? 'Sale recorded' : 'Rental recorded');
        self::closeCompetingOffers($tx, $actor, $propertyId, $id, $prop['title']);
        $tx->insert('lead_properties', ['tenant_id' => $actor->tenantId, 'lead_id' => $lead['id'], 'property_id' => $propertyId, 'status' => 'interested', 'source' => 'offer', 'created_by' => $actor->userId], ['onDuplicate' => ['status' => 'interested']]);
        self::activity($tx, $actor, $lead['id'], ($sale ? 'Sale' : 'Rental') . ' recorded: ' . $prop['title'], ['reservationId' => $id, 'propertyId' => $propertyId, 'dealValue' => $deal['dealValue'], 'currency' => $prop['currency']]);
        Audit::log($tx, 'reservation.complete', 'reservation', $id, null, ['leadId' => $lead['id'], 'propertyId' => $propertyId, 'dealValue' => $deal['dealValue']]);
        Events::emit($tx, 'reservation.completed', $id, ['leadId' => $lead['id'], 'propertyId' => $propertyId]);
        return $id;
    }

    /** Takes a recorded sale back (the lead is being reopened): the deal stops counting and the unit is available again. */
    public static function reverseDeals(Tx $tx, Actor $actor, string $leadId, string $reason): int
    {
        $deals = $tx->all("SELECT id, property_id FROM reservations WHERE tenant_id = ? AND lead_id = ? AND status = 'completed' ORDER BY property_id, id FOR UPDATE", [$actor->tenantId, $leadId]);
        foreach ($deals as $d) {
            $prop = self::lockProperty($tx, $actor, $d['property_id']);
            if ($prop['status'] === 'sold' || $prop['status'] === 'rented') self::setPropertyStatus($tx, $actor, $d['property_id'], 'available', mb_substr("Reversed: $reason", 0, 300));
            $tx->run("UPDATE reservations SET status = 'closed', closed_reason = 'deal_reversed', closed_note = ?, closed_at = NOW(3), closed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [mb_substr($reason, 0, 500), $actor->userId, $d['id'], $actor->tenantId]);
            self::activity($tx, $actor, $leadId, 'Recorded sale reversed: ' . $prop['title'], ['reservationId' => $d['id'], 'propertyId' => $d['property_id'], 'reason' => $reason]);
            Audit::log($tx, 'reservation.reverse', 'reservation', $d['id'], null, ['leadId' => $leadId, 'propertyId' => $d['property_id'], 'reason' => $reason]);
            Events::emit($tx, 'reservation.closed', $d['id'], ['leadId' => $leadId, 'propertyId' => $d['property_id'], 'reason' => 'deal_reversed']);
        }
        return count($deals);
    }

    /** Does this lead have a recorded sale? */
    public static function hasCompletedDeal(Tx $tx, string $tenantId, string $leadId): bool
    {
        return $tx->exists("SELECT 1 FROM reservations WHERE tenant_id = ? AND lead_id = ? AND status = 'completed' LIMIT 1", [$tenantId, $leadId]);
    }
}
