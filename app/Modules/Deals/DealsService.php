<?php
declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Notify;
use App\Core\Pagination;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Leads\LeadsService;
use App\Modules\Leads\Visibility;

/**
 * Offers, reservations and sales. offered → reserved → completed (the sale), or closed. Only completed deals count as won deal value.
 * Lock order everywhere: lead → property → reservation, so two people working on the same unit queue up instead of deadlocking.
 */
final class DealsService
{
    public const STATUSES = ['offered', 'reserved', 'completed', 'closed'];
    public const INTEREST_STATUSES = ['shortlisted', 'interested', 'rejected'];
    public const INTEREST_SOURCES = ['manual', 'matching', 'viewing', 'offer'];

    /** Why a deal attempt ended. `unit_taken` and `deal_reversed` are set by the system, never chosen. */
    public const CLOSE_REASONS = [
        ['code' => 'buyer_withdrew', 'label' => 'The buyer withdrew'],
        ['code' => 'offer_rejected', 'label' => 'The offer was not accepted'],
        ['code' => 'price_not_agreed', 'label' => 'Price could not be agreed'],
        ['code' => 'financing_failed', 'label' => 'Financing fell through'],
        ['code' => 'chose_other_unit', 'label' => 'The buyer chose another unit'],
        ['code' => 'hold_expired', 'label' => 'The hold ran out'],
        ['code' => 'other', 'label' => 'Other (say why)'],
    ];
    private const SYSTEM_REASONS = [['code' => 'unit_taken', 'label' => 'The unit went to another buyer'], ['code' => 'deal_reversed', 'label' => 'The sale was reversed']];

    private const NO_EFFECT = ['moved' => false, 'stageName' => null, 'note' => null];

    private const COLUMNS = "r.id, r.lead_id AS leadId, r.property_id AS propertyId, r.status, r.offer_price AS offerPrice, r.agreed_price AS agreedPrice, r.token_amount AS tokenAmount,
        r.deal_value AS dealValue, r.currency, r.hold_until AS holdUntil, r.note, r.closed_reason AS closedReason, r.closed_note AS closedNote, r.offered_at AS offeredAt,
        r.reserved_at AS reservedAt, r.completed_at AS completedAt, r.closed_at AS closedAt, r.version, r.created_at AS createdAt, r.created_at AS _k,
        l.owner_id AS _owner_id, ou.name AS _owner_name, c.first_name AS _c_first, c.last_name AS _c_last,
        p.title AS _p_title, p.status AS _p_status, p.price AS _p_price, p.listing_type AS _p_listing";
    private const FROM = 'reservations r
        JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id
        JOIN properties p ON p.id = r.property_id AND p.tenant_id = r.tenant_id
        LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
        LEFT JOIN users ou ON ou.id = l.owner_id';

    public static function closeReasonLabel(?string $code): ?string
    {
        foreach (array_merge(self::CLOSE_REASONS, self::SYSTEM_REASONS) as $r) if ($r['code'] === $code) return $r['label'];
        return null;
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    public static function dto(array $r, Actor $actor): array
    {
        $open = in_array($r['status'], ['offered', 'reserved'], true);
        $write = $actor->kind === 'system' ? 'all' : $actor->scopeIfAllowed('reservations:update');
        $canWrite = $write !== null;
        $all = $write === 'all';
        // an offer on a unit that another buyer holds can only be edited or closed: reserving or selling it would be refused
        $blocked = $r['status'] === 'offered' && $r['_p_status'] === 'reserved';
        $allowed = [];
        if ($canWrite && $r['status'] === 'offered' && !$blocked) $allowed[] = 'reserve';
        if ($canWrite && $open && !$blocked) $allowed[] = 'complete';
        if ($canWrite && $open) { $allowed[] = 'close'; $allowed[] = 'edit'; }
        if ($all && $r['status'] === 'completed') $allowed[] = 'reverse';
        $name = trim(implode(' ', array_filter([$r['_c_first'], $r['_c_last']])));
        return [
            'id' => $r['id'], 'leadId' => $r['leadId'], 'propertyId' => $r['propertyId'], 'status' => $r['status'], 'offerPrice' => self::num($r['offerPrice']), 'agreedPrice' => self::num($r['agreedPrice']),
            'tokenAmount' => self::num($r['tokenAmount']), 'dealValue' => self::num($r['dealValue']), 'currency' => $r['currency'], 'holdUntil' => $r['holdUntil'],
            'holdOverdue' => $r['status'] === 'reserved' && $r['holdUntil'] !== null && Time::parse($r['holdUntil'])->getTimestamp() < time(), 'note' => $r['note'],
            'closedReason' => $r['closedReason'] ? ['code' => $r['closedReason'], 'label' => self::closeReasonLabel($r['closedReason']) ?? $r['closedReason']] : null, 'closedNote' => $r['closedNote'],
            'offeredAt' => $r['offeredAt'], 'reservedAt' => $r['reservedAt'], 'completedAt' => $r['completedAt'], 'closedAt' => $r['closedAt'], 'version' => (int) $r['version'], 'createdAt' => $r['createdAt'],
            'lead' => ['id' => $r['leadId'], 'name' => $name !== '' ? $name : 'Unnamed buyer', 'ownerId' => $r['_owner_id'], 'ownerName' => $r['_owner_name']],
            'property' => ['id' => $r['propertyId'], 'title' => $r['_p_title'], 'status' => $r['_p_status'], 'price' => (float) $r['_p_price'], 'listingType' => $r['_p_listing']],
            'allowed' => $allowed,
        ];
    }

    // ── reading ───────────────────────────────────────────────────────────────────────────────────

    public static function list(Actor $actor, array $q): array
    {
        $scope = $actor->scopeFor('reservations:read');
        $sig = 'reservations:created:desc';
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $scope, $sig, $cursor) {
            $params = [$actor->tenantId];
            $where = ['r.tenant_id = ?', 'l.deleted_at IS NULL'];
            if ($scope === 'own') { $where[] = 'l.owner_id = ?'; $params[] = $actor->userId; }
            if (!empty($q['leadId'])) { $where[] = 'r.lead_id = ?'; $params[] = $q['leadId']; }
            if (!empty($q['propertyId'])) { $where[] = 'r.property_id = ?'; $params[] = $q['propertyId']; }
            if (!empty($q['status'])) { $where[] = 'r.status IN (?)'; $params[] = $q['status']; }
            if (!empty($q['completedFrom']) || !empty($q['completedTo'])) {
                // whole local days in the workspace time zone
                $tz = (string) ($tx->val('SELECT timezone FROM tenants WHERE id = ?', [$actor->tenantId]) ?: 'UTC');
                try { $zone = new \DateTimeZone($tz); } catch (\Throwable) { $zone = new \DateTimeZone('UTC'); }
                $utc = new \DateTimeZone('UTC');
                if (!empty($q['completedFrom'])) {
                    $where[] = "r.status = 'completed' AND r.completed_at >= ?";
                    $params[] = Time::db((new \DateTimeImmutable($q['completedFrom'] . ' 00:00:00', $zone))->setTimezone($utc));
                }
                if (!empty($q['completedTo'])) {
                    $where[] = "r.status = 'completed' AND r.completed_at < ?";
                    $params[] = Time::db((new \DateTimeImmutable($q['completedTo'] . ' 00:00:00', $zone))->modify('+1 day')->setTimezone($utc));
                }
            }
            if (($q['test'] ?? null) === 'exclude') $where[] = "NOT JSON_CONTAINS(l.tags, '\"test\"')";
            $pred = Pagination::predicate($cursor, $params, 'r.created_at', 'r.id', 'desc', 'ts');
            if ($pred) $where[] = $pred;
            $params[] = $q['limit'] + 1;
            $rows = $tx->all('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ?', $params);
            $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
            return ['items' => array_map(static fn($r) => self::dto($r, $actor), $pg['items']), 'page' => $pg['page']];
        });
    }

    public static function get(Actor $actor, string $id): array
    {
        $scope = $actor->scopeFor('reservations:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => self::fetch($tx, $actor, $id, $scope === 'own'));
    }

    /**
     * "Who holds this unit?" for the unit page. Anyone who may read inventory sees THAT it is held, since which agent holds it matters to
     * their own work; the buyer's name and the money are only for people who may see that buyer.
     */
    public static function holder(Actor $actor, string $propertyId): array
    {
        $actor->assertCan('properties:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $propertyId) {
            $status = $tx->val('SELECT status FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$propertyId, $actor->tenantId]);
            if ($status === null) throw Errors::notFound('Property');
            $visParams = [];
            $vis = Visibility::lead($actor, 'l', $visParams);
            $res = $tx->one(
                'SELECT r.id, r.reserved_at AS reservedAt, r.hold_until AS holdUntil, r.token_amount AS tokenAmount, r.agreed_price AS agreedPrice, r.currency, r.lead_id AS leadId,
                        ' . ($vis === false ? '0' : "($vis)") . ' AS visible, ou.name AS agent, c.first_name AS f, c.last_name AS s
                   FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id LEFT JOIN users ou ON ou.id = l.owner_id LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                  WHERE r.tenant_id = ? AND r.property_id = ? AND r.status = \'reserved\'',
                array_merge($visParams, [$actor->tenantId, $propertyId])
            );
            $offers = (int) $tx->val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'offered'", [$actor->tenantId, $propertyId]);
            $holder = null;
            if ($res) {
                $visible = (bool) $res['visible'];
                $name = trim(implode(' ', array_filter([$res['f'], $res['s']])));
                $holder = [
                    'reservationId' => $visible ? $res['id'] : null, 'leadId' => $visible ? $res['leadId'] : null, 'buyer' => $visible ? ($name !== '' ? $name : 'Unnamed buyer') : null,
                    'agent' => $res['agent'], 'reservedAt' => $res['reservedAt'], 'holdUntil' => $res['holdUntil'],
                    'holdOverdue' => $res['holdUntil'] !== null && Time::parse($res['holdUntil'])->getTimestamp() < time(),
                    'tokenAmount' => $visible ? self::num($res['tokenAmount']) : null, 'agreedPrice' => $visible ? self::num($res['agreedPrice']) : null, 'currency' => $res['currency'], 'visible' => $visible,
                ];
            }
            return ['propertyId' => $propertyId, 'status' => $status, 'openOffers' => $offers, 'holder' => $holder];
        });
    }

    // ── offer → reserve → complete / close ─────────────────────────────────────────────────────────

    /** A buyer makes an offer on a unit. The unit shows "under offer"; several buyers may offer, one may reserve. */
    public static function offer(Actor $actor, array $in): array
    {
        $actor->assertCan('reservations:create');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $lead = self::lockLead($tx, $actor, $in['leadId'], 'reservations:create');
            if ($lead['closed_at']) throw Errors::unprocessable('This lead is closed. Reopen it before recording an offer.', ['reason' => 'lead_closed']);
            $prop = Ledger::lockProperty($tx, $actor, $in['propertyId']);
            if ($prop['status'] === 'reserved') throw Errors::conflict('This unit is reserved for a buyer. It can take offers again if that reservation is released.', ['reason' => 'already_reserved']);
            if (!in_array($prop['status'], ['available', 'under_offer'], true)) throw Errors::unprocessable('This unit is ' . str_replace('_', ' ', $prop['status']) . ', so it cannot take an offer', ['reason' => 'unit_unavailable']);
            try {
                $id = $tx->insert('reservations', [
                    'tenant_id' => $actor->tenantId, 'lead_id' => $lead['id'], 'property_id' => $prop['id'], 'status' => 'offered', 'offer_price' => $in['offerPrice'] ?? null, 'currency' => $prop['currency'],
                    'hold_until' => $in['holdUntil'] ?? null, 'note' => isset($in['note']) && trim((string) $in['note']) !== '' ? trim($in['note']) : null, 'created_by' => $actor->userId,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('This buyer already has an open offer or reservation on this unit', ['reason' => 'already_open']);
                throw $e;
            }
            if ($prop['status'] === 'available') Ledger::setPropertyStatus($tx, $actor, $prop['id'], 'under_offer', 'Offer received');
            $tx->insert('lead_properties', ['tenant_id' => $actor->tenantId, 'lead_id' => $lead['id'], 'property_id' => $prop['id'], 'status' => 'interested', 'source' => 'offer', 'created_by' => $actor->userId], ['onDuplicate' => ['status' => 'interested']]);
            Ledger::activity($tx, $actor, $lead['id'], "Offer recorded: {$prop['title']}", ['reservationId' => $id, 'propertyId' => $prop['id'], 'offerPrice' => $in['offerPrice'] ?? null]);
            Audit::log($tx, 'reservation.offer', 'reservation', $id, null, ['leadId' => $lead['id'], 'propertyId' => $prop['id'], 'offerPrice' => $in['offerPrice'] ?? null]);
            Events::emit($tx, 'reservation.created', $id, ['leadId' => $lead['id'], 'propertyId' => $prop['id']]);
            $leadStage = self::advanceLead($tx, $actor, $lead, 'negotiation', 'Offer recorded');
            return ['reservation' => self::fetch($tx, $actor, $id), 'leadStage' => $leadStage];
        });
    }

    /** Token received: the unit is held for this buyer and nobody else can reserve it (database-enforced). Booking is a milestone, not a sale. */
    public static function reserve(Actor $actor, string $id, array $in, ?int $expectedVersion = null): array
    {
        $actor->assertCan('reservations:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $in, $expectedVersion) {
            ['res' => $res, 'lead' => $lead] = self::lockAll($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $res['version']) throw Errors::preconditionFailed();
            if ($res['status'] !== 'offered') throw Errors::invalidTransition($res['status'] === 'reserved' ? 'This unit is already reserved for this buyer' : "A {$res['status']} deal cannot be reserved");
            if ($lead['closed_at']) throw Errors::unprocessable('This lead is closed. Reopen it before reserving a unit.', ['reason' => 'lead_closed']);
            $prop = Ledger::lockProperty($tx, $actor, $res['property_id']);
            if ($tx->exists("SELECT 1 FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'reserved' AND id <> ?", [$actor->tenantId, $prop['id'], $id])) {
                throw Errors::conflict('This unit is already reserved for another buyer', ['reason' => 'already_reserved']);
            }
            if (!in_array($prop['status'], ['available', 'under_offer', 'reserved'], true)) throw Errors::unprocessable('This unit is ' . str_replace('_', ' ', $prop['status']) . ', so it cannot be reserved', ['reason' => 'unit_unavailable']);
            $note = isset($in['note']) && trim((string) $in['note']) !== '' ? trim($in['note']) : null;
            try {
                $tx->run(
                    "UPDATE reservations SET status = 'reserved', token_amount = ?, agreed_price = ?, hold_until = ?, note = COALESCE(?, note), reserved_at = NOW(3), reserved_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?",
                    [$in['tokenAmount'], $in['agreedPrice'], isset($in['holdUntil']) ? Time::db($in['holdUntil']) : null, $note, $actor->userId, $id, $actor->tenantId]
                );
            } catch (\PDOException $e) {
                // the unique key is the last line of defence; the lock above means it should not be reached
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('This unit has just been reserved for another buyer', ['reason' => 'already_reserved']);
                throw $e;
            }
            if ($prop['status'] !== 'reserved') Ledger::setPropertyStatus($tx, $actor, $prop['id'], 'reserved', 'Reserved');
            self::notifyOtherOffers($tx, $actor, $prop['id'], $prop['title'], $id);
            Ledger::activity($tx, $actor, $lead['id'], "Unit reserved: {$prop['title']}", ['reservationId' => $id, 'propertyId' => $prop['id'], 'tokenAmount' => $in['tokenAmount'], 'agreedPrice' => $in['agreedPrice']]);
            Audit::log($tx, 'reservation.reserve', 'reservation', $id, null, ['leadId' => $lead['id'], 'propertyId' => $prop['id'], 'tokenAmount' => $in['tokenAmount'], 'agreedPrice' => $in['agreedPrice']]);
            Events::emit($tx, 'reservation.updated', $id, ['leadId' => $lead['id'], 'propertyId' => $prop['id'], 'change' => 'reserved']);
            $leadStage = self::advanceLead($tx, $actor, $lead, 'booking_reserved', 'Unit reserved');
            return ['reservation' => self::fetch($tx, $actor, $id), 'leadStage' => $leadStage];
        });
    }

    /** The sale: records the final deal value, marks the unit sold or rented and moves the lead to Won, all in one transaction. */
    public static function complete(Actor $actor, string $id, array $in, ?int $expectedVersion = null): array
    {
        $actor->assertCan('reservations:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $in, $expectedVersion) {
            ['res' => $res, 'lead' => $lead] = self::lockAll($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $res['version']) throw Errors::preconditionFailed();
            if ($res['status'] === 'completed') throw Errors::invalidTransition('This sale is already recorded');
            if ($res['status'] === 'closed') throw Errors::invalidTransition('This deal was closed. Make a new offer to continue.');
            $leadStage = self::NO_EFFECT;
            if ($lead['status'] === 'won') {
                // already marked Won before deal values were recorded: attach the sale without moving anything
                Ledger::completeDeal($tx, $actor, $lead, ['reservationId' => $id, 'dealValue' => $in['dealValue']]);
            } else {
                $won = $tx->one("SELECT id, name FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND maps_to_status = 'won' AND archived_at IS NULL ORDER BY order_index LIMIT 1", [$lead['pipeline_id'], $actor->tenantId]);
                if (!$won) throw Errors::unprocessable('This lead’s pipeline has no Won step. Add one in the pipeline settings first.', ['reason' => 'no_won_stage']);
                if ($res['status'] === 'reserved') self::advanceLead($tx, $actor, $lead, 'booking_reserved', 'Unit reserved'); // a lead moved back since reserving still counts as booked
                LeadsService::moveInTx($tx, $actor, self::freshLead($tx, $actor, $lead['id']), ['stageId' => $won['id'], 'deal' => ['reservationId' => $id, 'dealValue' => $in['dealValue']]]);
                $leadStage = ['moved' => true, 'stageName' => $won['name'], 'note' => 'Marked as won'];
            }
            return ['reservation' => self::fetch($tx, $actor, $id), 'leadStage' => $leadStage];
        });
    }

    /** Ends an offer or reservation without a sale. The unit goes back on the market (or stays under offer if other buyers have offers open). */
    public static function close(Actor $actor, string $id, array $in, ?int $expectedVersion = null): array
    {
        $actor->assertCan('reservations:update');
        $reason = null;
        foreach (self::CLOSE_REASONS as $r) if ($r['code'] === $in['reason']) $reason = $r;
        if (!$reason) throw Errors::validation([['path' => 'body.reason', 'message' => 'Choose one of the listed reasons']]);
        $note = isset($in['note']) && trim((string) $in['note']) !== '' ? trim($in['note']) : null;
        if ($reason['code'] === 'other' && ($note === null || mb_strlen($note) < 2)) throw Errors::validation([['path' => 'body.note', 'message' => 'Say why in a few words']]);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $expectedVersion, $reason, $note) {
            ['res' => $res, 'lead' => $lead] = self::lockAll($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $res['version']) throw Errors::preconditionFailed();
            if (!in_array($res['status'], ['offered', 'reserved'], true)) throw Errors::invalidTransition($res['status'] === 'completed' ? 'A recorded sale cannot be closed here. Reverse it by reopening the lead.' : 'This deal is already closed');
            $prop = Ledger::lockProperty($tx, $actor, $res['property_id']);
            $tx->run("UPDATE reservations SET status = 'closed', closed_reason = ?, closed_note = ?, closed_at = NOW(3), closed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$reason['code'], $note, $actor->userId, $id, $actor->tenantId]);
            $stillOpen = (int) $tx->val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND property_id = ? AND status IN ('offered','reserved')", [$actor->tenantId, $prop['id']]);
            $stillReserved = (int) $tx->val("SELECT COUNT(*) FROM reservations WHERE tenant_id = ? AND property_id = ? AND status = 'reserved'", [$actor->tenantId, $prop['id']]);
            if ($prop['status'] === 'reserved' && !$stillReserved) Ledger::setPropertyStatus($tx, $actor, $prop['id'], $stillOpen ? 'under_offer' : 'available', 'Reservation released');
            elseif ($prop['status'] === 'under_offer' && !$stillOpen) Ledger::setPropertyStatus($tx, $actor, $prop['id'], 'available', 'No open offers');
            Ledger::activity($tx, $actor, $lead['id'], ($res['status'] === 'reserved' ? 'Reservation released' : 'Offer closed') . ": {$prop['title']} ({$reason['label']})", ['reservationId' => $id, 'propertyId' => $prop['id'], 'reason' => $reason['code'], 'note' => $note]);
            Audit::log($tx, 'reservation.close', 'reservation', $id, null, ['leadId' => $lead['id'], 'propertyId' => $prop['id'], 'reason' => $reason['code'], 'wasReserved' => $res['status'] === 'reserved']);
            Events::emit($tx, 'reservation.closed', $id, ['leadId' => $lead['id'], 'propertyId' => $prop['id'], 'reason' => $reason['code']]);
            return ['reservation' => self::fetch($tx, $actor, $id), 'leadStage' => ['moved' => false, 'stageName' => null, 'note' => 'The lead stays where it is. Move it yourself if the deal is over.']];
        });
    }

    /** Change the figures of an offer or reservation that is still open. */
    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('reservations:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            ['res' => $res] = self::lockAll($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $res['version']) throw Errors::preconditionFailed();
            if (!in_array($res['status'], ['offered', 'reserved'], true)) throw Errors::conflict('Only an open offer or reservation can be edited');
            if (array_key_exists('agreedPrice', $patch) && $res['status'] !== 'reserved') throw Errors::validation([['path' => 'body.agreedPrice', 'message' => 'The agreed price is set when the unit is reserved']]);
            $set = [];
            if (array_key_exists('offerPrice', $patch)) $set['offer_price'] = $patch['offerPrice'];
            if (array_key_exists('agreedPrice', $patch)) {
                if ($patch['agreedPrice'] === null) throw Errors::validation([['path' => 'body.agreedPrice', 'message' => 'A reservation needs an agreed price']]);
                $set['agreed_price'] = $patch['agreedPrice'];
            }
            if (array_key_exists('holdUntil', $patch)) $set['hold_until'] = $patch['holdUntil'];
            if (array_key_exists('note', $patch)) $set['note'] = $patch['note'] !== null && trim($patch['note']) !== '' ? trim($patch['note']) : null;
            if (!$set) return self::fetch($tx, $actor, $id);
            $set['version'] = new \App\Core\Raw('version + 1');
            $tx->update('reservations', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'reservation.update', 'reservation', $id, ['status' => $res['status']], array_map(static fn($v) => $v instanceof \DateTimeInterface ? Time::iso($v) : $v, $patch));
            Events::emit($tx, 'reservation.updated', $id, ['leadId' => $res['lead_id'], 'change' => 'edited']);
            return self::fetch($tx, $actor, $id);
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────────

    private static function fetch(Tx $tx, Actor $actor, string $id, bool $ownOnly = false): array
    {
        $params = [$actor->tenantId, $id];
        if ($ownOnly) $params[] = $actor->userId;
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE r.tenant_id = ? AND r.id = ?' . ($ownOnly ? ' AND l.owner_id = ?' : ''), $params);
        if (!$r) throw Errors::notFound('Reservation');
        return self::dto($r, $actor);
    }

    private static function lockLead(Tx $tx, Actor $actor, string $leadId, string $permission): array
    {
        $r = $tx->one('SELECT l.id, l.owner_id, l.stage_id, l.pipeline_id, l.closed_at, l.deleted_at, l.contact_id, l.status FROM leads l WHERE l.id = ? AND l.tenant_id = ? AND l.deleted_at IS NULL FOR UPDATE', [$leadId, $actor->tenantId]);
        if (!$r) throw Errors::notFound('Lead');
        $actor->assertCanActOn($permission, $r['owner_id']);
        return $r;
    }

    private static function freshLead(Tx $tx, Actor $actor, string $leadId): array
    {
        return $tx->one('SELECT id, stage_id, pipeline_id, closed_at, owner_id, contact_id FROM leads WHERE id = ? AND tenant_id = ?', [$leadId, $actor->tenantId]);
    }

    /**
     * Lock order everywhere: lead → property → reservation. Two people working on the same unit queue on the property instead of
     * deadlocking on each other's reservations. The reservation is re-read under the lock.
     *
     * @return array{res:array,lead:array}
     */
    private static function lockAll(Tx $tx, Actor $actor, string $id): array
    {
        $peek = $tx->one('SELECT lead_id, property_id FROM reservations WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
        if (!$peek) throw Errors::notFound('Reservation');
        $lead = self::lockLead($tx, $actor, $peek['lead_id'], 'reservations:update');
        $tx->one('SELECT 1 FROM properties WHERE id = ? AND tenant_id = ? FOR UPDATE', [$peek['property_id'], $actor->tenantId]);
        $res = $tx->one('SELECT id, lead_id, property_id, status, version, currency, offer_price FROM reservations WHERE id = ? AND tenant_id = ? FOR UPDATE', [$id, $actor->tenantId]);
        if (!$res) throw Errors::notFound('Reservation');
        return ['res' => $res, 'lead' => $lead];
    }

    private static function notifyOtherOffers(Tx $tx, Actor $actor, string $propertyId, string $title, string $exceptId): void
    {
        $others = $tx->all("SELECT r.id, l.owner_id FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND r.property_id = ? AND r.status = 'offered' AND r.id <> ?", [$actor->tenantId, $propertyId, $exceptId]);
        foreach ($others as $o) {
            if ($o['owner_id'] && $o['owner_id'] !== $actor->userId) {
                Notify::send($tx, ['userId' => $o['owner_id'], 'title' => 'A unit with your offer was reserved', 'message' => "$title is now reserved for another buyer.", 'type' => 'reservation_held', 'link' => '/deals', 'entityType' => 'reservation', 'entityId' => $o['id'], 'dedupeKey' => "reservation-held:$exceptId:{$o['id']}"]);
            }
        }
    }

    /**
     * Moves the lead forward to the pipeline step with this key, if it has one and the lead is behind it. Never backwards, never on a
     * closed lead, and a pipeline rule that refuses the move does not fail the deal: the answer says what happened.
     */
    private static function advanceLead(Tx $tx, Actor $actor, array $lead, string $key, string $reason): array
    {
        $cur = $tx->one('SELECT l.stage_id, l.closed_at, s.order_index FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ?', [$lead['id'], $actor->tenantId]);
        if (!$cur || $cur['closed_at']) return self::NO_EFFECT;
        $target = $tx->one('SELECT id, name, order_index FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND stage_key = ? AND archived_at IS NULL', [$lead['pipeline_id'], $actor->tenantId, $key]);
        if (!$target) return ['moved' => false, 'stageName' => null, 'note' => 'This pipeline has no matching step, so the lead was not moved.'];
        if ((int) $target['order_index'] <= (int) $cur['order_index']) return self::NO_EFFECT;
        $tx->run('SAVEPOINT advance_lead');
        try {
            LeadsService::moveInTx($tx, $actor, self::freshLead($tx, $actor, $lead['id']), ['stageId' => $target['id'], 'reason' => $reason]);
            $tx->run('RELEASE SAVEPOINT advance_lead');
            return ['moved' => true, 'stageName' => $target['name'], 'note' => $reason];
        } catch (AppError $e) {
            $tx->run('ROLLBACK TO SAVEPOINT advance_lead');
            if ($e->status >= 500) throw $e;
            return ['moved' => false, 'stageName' => null, 'note' => "The lead was not moved to “{$target['name']}”: " . ($e->detail ?? $e->getMessage())];
        }
    }
}
