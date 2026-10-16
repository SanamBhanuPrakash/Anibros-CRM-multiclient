<?php
declare(strict_types=1);

namespace App\Modules\Viewings;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Pagination;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Leads\Visibility;
use App\Modules\Tasks\TasksService;

/** Site visits: booking (no double-booking of a property or an agent), rescheduling, outcomes, and the reminder task that puts the visit in the agent's day. */
final class ViewingsService
{
    public const STATUSES = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];
    private const OPEN = ['scheduled', 'confirmed'];

    /** What happened at a completed visit. Stable codes (stored), plain labels (shown, may be reworded). */
    public const OUTCOMES = [
        ['code' => 'interested', 'label' => 'Interested and wants to go ahead'],
        ['code' => 'offer_expected', 'label' => 'Likely to make an offer'],
        ['code' => 'considering', 'label' => 'Needs time to think'],
        ['code' => 'wants_other_options', 'label' => 'Wants to see other options'],
        ['code' => 'not_interested', 'label' => 'Not interested'],
    ];

    private const COLUMNS = "v.id, v.lead_id AS leadId, v.property_id AS propertyId, v.agent_id AS agentId, v.scheduled_at AS scheduledAt, v.duration_minutes AS durationMinutes,
        v.status, v.notes, v.version, v.outcome_code AS outcomeCode, v.outcome_note AS outcomeNote, v.closed_at AS closedAt, v.cancel_reason AS cancelReason, v.reminder_task_id AS reminderTaskId,
        v.created_at AS createdAt, v.updated_at AS updatedAt, v.scheduled_at AS _k,
        p.title AS _p_title, p.price AS _p_price, COALESCE(p.location, NULLIF(CONCAT_WS(', ', p.locality, p.city), '')) AS _p_location,
        c.first_name AS _c_first, c.last_name AS _c_last, c.email AS _c_email, c.phone AS _c_phone, u.name AS _a_name";
    private const FROM = 'viewings v
        JOIN properties p ON p.id = v.property_id AND p.tenant_id = v.tenant_id
        JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id
        LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
        LEFT JOIN users u ON u.id = v.agent_id';

    private static function outcomeLabel(?string $code): ?string
    {
        foreach (self::OUTCOMES as $o) if ($o['code'] === $code) return $o['label'];
        return null;
    }

    public static function dto(array $r): array
    {
        return [
            'id' => $r['id'], 'leadId' => $r['leadId'], 'propertyId' => $r['propertyId'], 'agentId' => $r['agentId'], 'scheduledAt' => $r['scheduledAt'],
            'durationMinutes' => (int) $r['durationMinutes'], 'status' => $r['status'], 'notes' => $r['notes'], 'version' => (int) $r['version'],
            'outcome' => $r['outcomeCode'] ? ['code' => $r['outcomeCode'], 'label' => self::outcomeLabel($r['outcomeCode']) ?? $r['outcomeCode']] : null,
            'outcomeNote' => $r['outcomeNote'], 'closedAt' => $r['closedAt'], 'cancelReason' => $r['cancelReason'], 'reminderTaskId' => $r['reminderTaskId'], 'createdAt' => $r['createdAt'],
            'property' => ['id' => $r['propertyId'], 'title' => $r['_p_title'], 'price' => (float) $r['_p_price'], 'location' => $r['_p_location']],
            'leadContact' => ['firstName' => $r['_c_first'], 'lastName' => $r['_c_last'], 'email' => $r['_c_email'], 'phone' => $r['_c_phone']],
            'agent' => $r['agentId'] && $r['_a_name'] ? ['id' => $r['agentId'], 'name' => $r['_a_name']] : null,
        ];
    }

    private static function fmt(\DateTimeInterface|string $d): string
    {
        $d = $d instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($d) : Time::parse($d);
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
    }

    private static function mayAssign(Actor $actor): bool
    {
        return $actor->kind === 'system' || $actor->scopeIfAllowed('leads:assign') === 'all';
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Own-scope roles (sales agents) see the viewings they run or that belong to their own leads; everyone else with access sees all. */
    public static function list(Actor $actor, array $q): array
    {
        $scope = $actor->scopeFor('viewings:read');
        $sig = 'viewings:scheduled:asc';
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $scope, $sig, $cursor) {
            $params = [$actor->tenantId];
            $where = ['v.tenant_id = ?'];
            if ($scope === 'own') { $where[] = '(v.agent_id = ? OR l.owner_id = ?)'; array_push($params, $actor->userId, $actor->userId); }
            if (!empty($q['propertyId'])) { $where[] = 'v.property_id = ?'; $params[] = $q['propertyId']; }
            if (!empty($q['leadId'])) { $where[] = 'v.lead_id = ?'; $params[] = $q['leadId']; }
            if (!empty($q['status'])) { $where[] = 'v.status = ?'; $params[] = $q['status']; }
            if (!empty($q['from'])) { $where[] = 'v.scheduled_at >= ?'; $params[] = Time::db($q['from']); }
            if (!empty($q['to'])) { $where[] = 'v.scheduled_at < ?'; $params[] = Time::db($q['to']); }
            $pred = Pagination::predicate($cursor, $params, 'v.scheduled_at', 'v.id', 'asc', 'ts');
            if ($pred) $where[] = $pred;
            $params[] = $q['limit'] + 1;
            $rows = $tx->all('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY v.scheduled_at ASC, v.id ASC LIMIT ?', $params);
            $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
            return ['items' => array_map([self::class, 'dto'], $pg['items']), 'page' => $pg['page']];
        });
    }

    private static function readRow(Tx $tx, Actor $actor, string $id, string $scope): array
    {
        $params = [$actor->tenantId, $id];
        $own = '';
        if ($scope === 'own') { $own = ' AND (v.agent_id = ? OR l.owner_id = ?)'; array_push($params, $actor->userId, $actor->userId); }
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE v.tenant_id = ? AND v.id = ?' . $own, $params);
        if (!$r) throw Errors::notFound('Viewing');
        return $r;
    }

    public static function get(Actor $actor, string $id): array
    {
        $scope = $actor->scopeFor('viewings:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => self::dto(self::readRow($tx, $actor, $id, $scope)));
    }

    /** Calendar file for one viewing. */
    public static function ics(Actor $actor, string $id): array
    {
        $scope = $actor->scopeFor('viewings:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id, $scope) {
            $row = self::readRow($tx, $actor, $id, $scope);
            $buyer = trim(implode(' ', array_filter([$row['_c_first'], $row['_c_last']])));
            $body = Ics::build([
                'id' => $row['id'], 'scheduledAt' => $row['scheduledAt'], 'durationMinutes' => (int) $row['durationMinutes'], 'status' => $row['status'], 'notes' => $row['notes'],
                'propertyTitle' => $row['_p_title'], 'propertyLocation' => $row['_p_location'], 'buyerName' => $buyer !== '' ? $buyer : null, 'agentName' => $row['_a_name'],
                'updatedAt' => $row['updatedAt'], 'version' => (int) $row['version'],
            ]);
            return ['filename' => 'site-visit-' . substr((string) $row['scheduledAt'], 0, 10) . '.ics', 'body' => $body];
        });
    }

    // ── writes ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Does the property or the agent already have an open visit overlapping [$start, $end)? */
    private static function overlaps(Tx $tx, string $tenantId, string $column, string $value, ?string $exceptId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $sql = "SELECT 1 FROM viewings WHERE tenant_id = ? AND $column = ? AND status IN ('scheduled','confirmed') AND scheduled_at < ? AND DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?";
        $params = [$tenantId, $value, Time::db($end), Time::db($start)];
        if ($exceptId !== null) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        return $tx->exists($sql . ' LIMIT 1', $params);
    }

    private static function assertAgent(Tx $tx, string $tenantId, string $agentId): void
    {
        if (!$tx->exists("SELECT 1 FROM tenant_memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ? AND m.status = 'active' AND m.is_active = 1 AND u.is_active = 1", [$tenantId, $agentId])) {
            throw Errors::badRequest('The chosen agent is not an active member of this workspace');
        }
    }

    public static function schedule(Actor $actor, array $in): array
    {
        $actor->assertCan('viewings:create');
        // Only people who may assign work (managers, owners, admins) can book someone else's diary; everyone else books themselves.
        if (!empty($in['agentId']) && $in['agentId'] !== $actor->userId && !self::mayAssign($actor)) throw Errors::forbidden('Only managers can book a viewing for another agent');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            // The buyer must be one this person is allowed to see (an agent cannot book on a colleague's lead).
            $params = [$actor->tenantId, $in['leadId']];
            $vis = Visibility::lead($actor, 'l', $params);
            if ($vis === false) throw Errors::forbidden();
            if (!$tx->exists("SELECT 1 FROM leads l WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $params)) throw Errors::notFound('Lead');

            $duration = (int) ($in['durationMinutes'] ?? 30);
            if ($duration < 5 || $duration > 480) throw Errors::badRequest('Duration must be between 5 and 480 minutes');
            /** @var \DateTimeImmutable $start */
            $start = $in['scheduledAt'];
            $end = $start->modify("+$duration minutes");

            // lock the property; it must be live and open to visits (available or under offer)
            $prop = $tx->one('SELECT status FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$in['propertyId'], $actor->tenantId]);
            if (!$prop) throw Errors::notFound('Property');
            if (!in_array($prop['status'], ['available', 'under_offer'], true)) {
                throw Errors::unprocessable('This property is ' . str_replace('_', ' ', $prop['status']) . ', so a viewing cannot be booked. Only available or under-offer properties can be viewed.');
            }
            $agentId = $in['agentId'] ?? $actor->userId;
            if ($agentId) self::assertAgent($tx, $actor->tenantId, $agentId);
            if (self::overlaps($tx, $actor->tenantId, 'property_id', $in['propertyId'], null, $start, $end)) throw Errors::slotUnavailable('Property already has an overlapping appointment in this time window');
            if ($agentId && self::overlaps($tx, $actor->tenantId, 'agent_id', $agentId, null, $start, $end)) throw Errors::slotUnavailable('Assigned agent already has an overlapping viewing scheduled');

            try {
                $id = $tx->insert('viewings', [
                    'tenant_id' => $actor->tenantId, 'lead_id' => $in['leadId'], 'property_id' => $in['propertyId'], 'agent_id' => $agentId, 'scheduled_at' => $start,
                    'duration_minutes' => $duration, 'status' => 'scheduled', 'notes' => isset($in['notes']) && trim((string) $in['notes']) !== '' ? trim($in['notes']) : null,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::slotUnavailable('Property already has an overlapping appointment in this time window');
                throw $e;
            }
            $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $in['leadId'], 'user_id' => $actor->userId, 'type' => 'viewing', 'description' => 'Viewing appointment scheduled',
                'metadata' => Json::enc(['viewing_id' => $id, 'property_id' => $in['propertyId'], 'scheduled_at' => Time::iso($start), 'duration_minutes' => $duration]), 'created_at' => new Raw('NOW(3)')]);
            Audit::log($tx, 'viewing.schedule', 'viewing', $id, null, ['lead_id' => $in['leadId'], 'property_id' => $in['propertyId'], 'agent_id' => $agentId, 'scheduled_at' => Time::iso($start), 'duration_minutes' => $duration]);
            Events::emit($tx, 'viewing.scheduled', $id, ['leadId' => $in['leadId'], 'propertyId' => $in['propertyId'], 'agentId' => $agentId]);

            $dto = self::fetch($tx, $actor->tenantId, $id);
            self::createReminder($tx, $actor, $dto);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /** Move a visit, change its length, notes or agent. Same rules as booking: the unit must still be open to visits and nobody may be double-booked. */
    public static function reschedule(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('viewings:update');
        if (!empty($patch['agentId']) && $patch['agentId'] !== $actor->userId && !self::mayAssign($actor)) throw Errors::forbidden('Only managers can hand a viewing to another agent');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $cur = self::lock($tx, $actor, $id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $cur['version']) throw Errors::preconditionFailed();
            self::assertOpen($cur);
            if ($cur['lead_deleted']) throw Errors::conflict('This lead is archived. Restore it before changing its visits.');
            $start = isset($patch['scheduledAt']) ? $patch['scheduledAt'] : Time::parse($cur['scheduled_at']);
            $duration = (int) ($patch['durationMinutes'] ?? $cur['duration_minutes']);
            $agentId = array_key_exists('agentId', $patch) ? $patch['agentId'] : $cur['agent_id'];
            $notes = array_key_exists('notes', $patch) ? (trim((string) $patch['notes']) !== '' ? trim($patch['notes']) : null) : $cur['notes'];
            if ($duration < 5 || $duration > 480) throw Errors::badRequest('Duration must be between 5 and 480 minutes');
            $end = $start->modify("+$duration minutes");

            $prop = $tx->one('SELECT status FROM properties WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$cur['property_id'], $actor->tenantId]);
            if (!$prop) throw Errors::notFound('Property');
            if (!in_array($prop['status'], ['available', 'under_offer'], true)) {
                throw Errors::unprocessable('This property is ' . str_replace('_', ' ', $prop['status']) . ', so its visit cannot be moved. Only available or under-offer properties can be viewed.');
            }
            if ($agentId) self::assertAgent($tx, $actor->tenantId, $agentId);
            if (self::overlaps($tx, $actor->tenantId, 'property_id', $cur['property_id'], $id, $start, $end)) throw Errors::slotUnavailable('Property already has an overlapping appointment in this time window');
            if ($agentId && self::overlaps($tx, $actor->tenantId, 'agent_id', $agentId, $id, $start, $end)) throw Errors::slotUnavailable('That agent already has an overlapping viewing');

            try {
                $tx->run('UPDATE viewings SET scheduled_at = ?, duration_minutes = ?, agent_id = ?, notes = ?, version = version + 1 WHERE id = ? AND tenant_id = ?', [Time::db($start), $duration, $agentId, $notes, $id, $actor->tenantId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::slotUnavailable('Property already has an overlapping appointment in this time window');
                throw $e;
            }
            self::logActivity($tx, $actor, $cur['lead_id'], $id, 'Viewing rescheduled to ' . self::fmt($start));
            $dto = self::fetch($tx, $actor->tenantId, $id);
            self::syncReminder($tx, $actor, $cur, $dto);
            Audit::log($tx, 'viewing.reschedule', 'viewing', $id, ['scheduledAt' => $cur['scheduled_at'], 'durationMinutes' => (int) $cur['duration_minutes'], 'agentId' => $cur['agent_id']], ['scheduledAt' => Time::iso($start), 'durationMinutes' => $duration, 'agentId' => $agentId]);
            Events::emit($tx, 'viewing.updated', $id, ['change' => 'rescheduled', 'leadId' => $cur['lead_id']]);
            return $dto;
        });
    }

    public static function cancel(Actor $actor, string $id, ?string $reason = null): array
    {
        $actor->assertCan('viewings:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $reason) {
            $cur = self::lock($tx, $actor, $id);
            self::assertOpen($cur);
            $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $tx->run("UPDATE viewings SET status = 'cancelled', closed_at = NOW(3), cancel_reason = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$reason, $id, $actor->tenantId]);
            self::logActivity($tx, $actor, $cur['lead_id'], $id, 'Viewing cancelled' . ($reason !== null ? ": $reason" : ''));
            self::closeReminder($tx, $actor, $cur, 'cancelled');
            Audit::log($tx, 'viewing.cancel', 'viewing', $id, null, ['reason' => $reason]);
            Events::emit($tx, 'viewing.updated', $id, ['change' => 'cancelled', 'leadId' => $cur['lead_id']]);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /** The buyer did not come. Only after the visit was due to start. */
    public static function noShow(Actor $actor, string $id, ?string $note = null): array
    {
        $actor->assertCan('viewings:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $note) {
            $cur = self::lock($tx, $actor, $id);
            self::assertOpen($cur);
            if (Time::parse($cur['scheduled_at'])->getTimestamp() > time()) throw Errors::conflict('This visit has not started yet, so it cannot be marked as a no-show');
            $tx->run("UPDATE viewings SET status = 'no_show', closed_at = NOW(3), outcome_note = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$note !== null && trim($note) !== '' ? trim($note) : null, $id, $actor->tenantId]);
            self::logActivity($tx, $actor, $cur['lead_id'], $id, 'Buyer did not attend the viewing');
            self::closeReminder($tx, $actor, $cur, 'cancelled');
            Audit::log($tx, 'viewing.no_show', 'viewing', $id);
            Events::emit($tx, 'viewing.updated', $id, ['change' => 'no_show', 'leadId' => $cur['lead_id']]);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /**
     * The visit happened. Records what came of it, closes the reminder, optionally creates ONE follow-up task, and suggests (does not make)
     * the next stage for the lead: the person decides, the system only offers.
     */
    public static function complete(Actor $actor, string $id, array $in): array
    {
        $actor->assertCan('viewings:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $in) {
            $cur = self::lock($tx, $actor, $id);
            self::assertOpen($cur);
            if ($cur['lead_deleted']) throw Errors::conflict('This lead is archived. Restore it before recording the visit.');
            if (Time::parse($cur['scheduled_at'])->getTimestamp() - time() > 15 * 60) throw Errors::conflict('This visit has not started yet. You can record it once it begins.');
            $note = isset($in['note']) && trim((string) $in['note']) !== '' ? trim($in['note']) : null;
            $tx->run("UPDATE viewings SET status = 'completed', closed_at = NOW(3), outcome_code = ?, outcome_note = ?, version = version + 1 WHERE id = ? AND tenant_id = ?", [$in['outcome'], $note, $id, $actor->tenantId]);
            self::logActivity($tx, $actor, $cur['lead_id'], $id, 'Viewing completed: ' . self::outcomeLabel($in['outcome']) . ($note !== null ? ". $note" : ''));
            self::closeReminder($tx, $actor, $cur, 'completed');

            $followUp = null;
            if (!empty($in['followUp'])) {
                $buyer = $tx->val('SELECT c.display_name FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ?', [$cur['lead_id'], $actor->tenantId]);
                $title = isset($in['followUp']['title']) && trim((string) $in['followUp']['title']) !== '' ? trim($in['followUp']['title']) : 'Follow up after the visit: ' . ($buyer ?? 'buyer');
                $r = TasksService::createInTx($tx, $actor, [
                    'title' => mb_substr($title, 0, 200), 'kind' => 'follow_up', 'priority' => 'high', 'dueDate' => $in['followUp']['dueAt'],
                    'assignedTo' => $actor->scopeIfAllowed('tasks:update') === 'all' ? ($cur['owner_id'] ?? $cur['agent_id'] ?? $actor->userId) : $actor->userId, 'leadId' => $cur['lead_id'],
                ], ['dedupeKey' => "viewing:$id:followup"]);
                $followUp = ['id' => $r['task']['id'], 'title' => $r['task']['title'], 'dueDate' => $r['task']['dueDate']];
            }
            $suggested = self::suggestStage($tx, $actor, $cur['lead_id']);
            Audit::log($tx, 'viewing.complete', 'viewing', $id, null, ['outcome' => $in['outcome'], 'followUp' => $followUp !== null]);
            Events::emit($tx, 'viewing.updated', $id, ['change' => 'completed', 'leadId' => $cur['lead_id'], 'outcome' => $in['outcome']]);
            return ['viewing' => self::fetch($tx, $actor->tenantId, $id), 'followUp' => $followUp, 'suggestedStage' => $suggested];
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    private static function assertOpen(array $v): void
    {
        if (!in_array($v['status'], self::OPEN, true)) throw Errors::conflict('This visit is already ' . ($v['status'] === 'no_show' ? 'marked as a no-show' : $v['status']));
    }

    /** Row lock plus the same own-scope rule as reading: an agent only touches visits they run or that belong to their own leads (404 otherwise). */
    private static function lock(Tx $tx, Actor $actor, string $id): array
    {
        $scope = $actor->scopeFor('viewings:update');
        $row = $tx->one(
            'SELECT v.id, v.lead_id, v.property_id, v.agent_id, v.scheduled_at, v.duration_minutes, v.status, v.version, v.notes, v.reminder_task_id, l.owner_id, (l.deleted_at IS NOT NULL) AS lead_deleted
               FROM viewings v JOIN leads l ON l.id = v.lead_id AND l.tenant_id = v.tenant_id WHERE v.id = ? AND v.tenant_id = ? FOR UPDATE',
            [$id, $actor->tenantId]
        );
        if (!$row) throw Errors::notFound('Viewing');
        if ($scope === 'own' && $row['agent_id'] !== $actor->userId && $row['owner_id'] !== $actor->userId) throw Errors::notFound('Viewing');
        $row['lead_deleted'] = (bool) $row['lead_deleted'];
        return $row;
    }

    private static function fetch(Tx $tx, string $tenantId, string $id): array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE v.tenant_id = ? AND v.id = ?', [$tenantId, $id]);
        if (!$r) throw Errors::notFound('Viewing');
        return self::dto($r);
    }

    private static function logActivity(Tx $tx, Actor $actor, string $leadId, string $viewingId, string $description): void
    {
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $actor->userId, 'type' => 'viewing', 'description' => $description, 'metadata' => Json::enc(['viewing_id' => $viewingId]), 'created_at' => new Raw('NOW(3)')]);
    }

    /** A real task, so the visit shows up in "my day", reminds the agent through the normal notification path and counts as the lead's next follow-up. */
    private static function createReminder(Tx $tx, Actor $actor, array $v): void
    {
        $ownerId = $tx->val('SELECT owner_id FROM leads WHERE id = ? AND tenant_id = ?', [$v['leadId'], $actor->tenantId]);
        $start = Time::parse($v['scheduledAt']);
        $minutesAway = ($start->getTimestamp() - time()) / 60;
        $buyer = trim(implode(' ', array_filter([$v['leadContact']['firstName'], $v['leadContact']['lastName']]))) ?: 'buyer';
        $in = [
            'title' => mb_substr("Site visit: $buyer at {$v['property']['title']}", 0, 200), 'description' => $v['notes'], 'kind' => 'meeting', 'priority' => 'medium', 'dueDate' => $start,
            'assignedTo' => $v['agentId'] ?? $ownerId ?? null, 'leadId' => $v['leadId'],
        ];
        if ($minutesAway > 90) $in['remindBeforeMinutes'] = 60;
        elseif ($minutesAway > 30) $in['remindBeforeMinutes'] = 15;
        $r = TasksService::createInTx($tx, $actor, $in, ['dedupeKey' => "viewing:{$v['id']}:reminder"]);
        $tx->run('UPDATE viewings SET reminder_task_id = ? WHERE id = ? AND tenant_id = ?', [$r['task']['id'], $v['id'], $actor->tenantId]);
    }

    private static function syncReminder(Tx $tx, Actor $actor, array $cur, array $v): void
    {
        if (!$cur['reminder_task_id']) return;
        $due = Time::db(Time::parse($v['scheduledAt']));
        $tx->run(
            "UPDATE tasks SET due_date = ?, remind_at = CASE WHEN remind_before_minutes IS NULL THEN NULL ELSE DATE_SUB(?, INTERVAL remind_before_minutes MINUTE) END,
                    reminder_sent_at = NULL, overdue_notified_at = NULL, assigned_to = COALESCE(?, assigned_to), version = version + 1
              WHERE id = ? AND tenant_id = ? AND status IN ('pending','in_progress')",
            [$due, $due, $v['agentId'], $cur['reminder_task_id'], $actor->tenantId]
        );
        TasksService::syncLeadFollowup($tx, $actor->tenantId, $cur['lead_id']);
    }

    private static function closeReminder(Tx $tx, Actor $actor, array $cur, string $as): void
    {
        if (!$cur['reminder_task_id']) return;
        if ($as === 'completed') {
            $tx->run("UPDATE tasks SET status = 'completed', completed_at = NOW(3), completed_by = ?, version = version + 1 WHERE id = ? AND tenant_id = ? AND status IN ('pending','in_progress')", [$actor->userId, $cur['reminder_task_id'], $actor->tenantId]);
        } else {
            $tx->run("UPDATE tasks SET status = 'cancelled', version = version + 1 WHERE id = ? AND tenant_id = ? AND status IN ('pending','in_progress')", [$cur['reminder_task_id'], $actor->tenantId]);
        }
        TasksService::syncLeadFollowup($tx, $actor->tenantId, $cur['lead_id']);
    }

    /** "Site visit done" if the lead's pipeline has it and the lead has not reached it; otherwise nothing. Only an offer: the person moves the lead. */
    private static function suggestStage(Tx $tx, Actor $actor, string $leadId): ?array
    {
        return $tx->one(
            "SELECT s.id AS stageId, s.name FROM leads l
               JOIN pipeline_stages cur ON cur.id = l.stage_id AND cur.tenant_id = l.tenant_id
               JOIN pipeline_stages s ON s.pipeline_id = l.pipeline_id AND s.tenant_id = l.tenant_id AND s.stage_key = 'viewing_completed' AND s.archived_at IS NULL
              WHERE l.id = ? AND l.tenant_id = ? AND l.closed_at IS NULL AND cur.order_index < s.order_index",
            [$leadId, $actor->tenantId]
        );
    }
}
