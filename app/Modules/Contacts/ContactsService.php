<?php
declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Map;
use App\Core\Matrix;
use App\Core\Pagination;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;

/**
 * Contacts: one record per person, even when they enquire through several channels. Identity keys (e-mail / phone) are unique per live
 * contact (database constraint); duplicates are reported, merged and unmerged (30 days). Consent is append-only; revoking closes the grants.
 */
final class ContactsService
{
    private const MERGE_UNDO_WINDOW_DAYS = 30;
    private const MAX_MERGE_BATCH = 5;

    private const COLUMNS = "c.id, c.first_name AS firstName, c.last_name AS lastName, c.display_name AS displayName, c.email, c.phone, c.company,
        c.job_title AS jobTitle, c.city, c.owner_id AS ownerId, c.tags, c.custom_fields AS customFields, c.version, c.created_at AS createdAt, c.updated_at AS updatedAt,
        (SELECT GROUP_CONCAT(i.value ORDER BY i.created_at, i.id SEPARATOR CHAR(10)) FROM contact_identifiers i WHERE i.contact_id = c.id AND i.kind = 'email' AND i.is_primary = 0 AND i.released_at IS NULL) AS alternateEmails,
        (SELECT GROUP_CONCAT(i.value ORDER BY i.created_at, i.id SEPARATOR CHAR(10)) FROM contact_identifiers i WHERE i.contact_id = c.id AND i.kind = 'phone' AND i.is_primary = 0 AND i.released_at IS NULL) AS alternatePhones,
        (SELECT COUNT(*) FROM leads l WHERE l.contact_id = c.id AND l.tenant_id = c.tenant_id AND l.deleted_at IS NULL) AS leadCount";

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function dto(array $r): array
    {
        $r['alternateEmails'] = ($r['alternateEmails'] ?? '') === '' || $r['alternateEmails'] === null ? [] : explode("\n", $r['alternateEmails']);
        $r['alternatePhones'] = ($r['alternatePhones'] ?? '') === '' || $r['alternatePhones'] === null ? [] : explode("\n", $r['alternatePhones']);
        $r = Map::row($r, ['json' => ['tags' => []], 'obj' => ['customFields'], 'int' => ['version', 'leadCount']]);
        return $r;
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{limit:int,cursor?:string,q?:string,ownerId?:string,tag?:string,sort:string,order:string} $q */
    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('contacts:read');
        $sig = "contacts:{$q['sort']}:{$q['order']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        $params = [];
        $where = ['c.tenant_id = ?', 'c.deleted_at IS NULL'];
        $params[] = $actor->tenantId;
        if (!empty($q['ownerId'])) { $where[] = 'c.owner_id = ?'; $params[] = $q['ownerId']; }
        if (!empty($q['tag'])) {
            foreach (Normalize::tags([$q['tag']]) as $t) { $where[] = 'JSON_CONTAINS(c.tags, ?)'; $params[] = Json::enc($t); }
        }
        if (isset($q['q']) && trim($q['q']) !== '') {
            $term = mb_substr(trim($q['q']), 0, 100);
            $like = '%' . Normalize::escapeLike($term) . '%';
            $parts = ['c.display_name LIKE ?', 'c.company LIKE ?', 'EXISTS (SELECT 1 FROM contact_identifiers i WHERE i.contact_id = c.id AND i.released_at IS NULL AND i.value LIKE ?)'];
            array_push($params, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $term);
            if (strlen($digits) >= 3) {
                $parts[] = "EXISTS (SELECT 1 FROM contact_identifiers i WHERE i.contact_id = c.id AND i.kind = 'phone' AND i.released_at IS NULL AND i.value LIKE ?)";
                $params[] = "%$digits%";
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $keyExpr = ['created' => 'c.created_at', 'updated' => 'c.updated_at', 'name' => 'c.display_name'][$q['sort']];
        $pred = Pagination::predicate($cursor, $params, $keyExpr, 'c.id', $q['order'], $q['sort'] === 'name' ? 'str' : 'ts');
        if ($pred) $where[] = $pred;
        $dir = $q['order'] === 'asc' ? 'ASC' : 'DESC';
        $params[] = $q['limit'] + 1;
        $rows = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->all('SELECT ' . self::COLUMNS . ' FROM contacts c WHERE ' . implode(' AND ', $where) . " ORDER BY $keyExpr $dir, c.id $dir LIMIT ?", $params));
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $q['sort'] === 'created' ? $r['createdAt'] : ($q['sort'] === 'updated' ? $r['updatedAt'] : $r['displayName']));
        return ['items' => array_map([self::class, 'dto'], $pg['items']), 'page' => $pg['page']];
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('contacts:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $c = self::fetch($tx, $actor->tenantId, $id);
            if (!$c) throw Errors::notFound('Contact');
            return $c;
        });
    }

    /** Who already uses these identifiers? Used by the UI while typing and by integrations to upsert. */
    public static function lookup(Actor $actor, array $input): array
    {
        $actor->assertCan('contacts:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $input) {
            $country = self::defaultCountry($tx, $actor->tenantId);
            $idents = [];
            if (!empty($input['email']) && ($e = Normalize::email($input['email']))) $idents[] = ['kind' => 'email', 'value' => $e, 'primary' => false];
            if (!empty($input['phone']) && ($p = Normalize::phone($input['phone'], $country))) $idents[] = ['kind' => 'phone', 'value' => $p, 'primary' => false];
            return self::findDuplicates($tx, $actor->tenantId, $idents);
        });
    }

    // ── writes ────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('contacts:create');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $country = self::defaultCountry($tx, $actor->tenantId);
            $issues = [];
            $email = self::parseOptional($in['email'] ?? null, 'email', static fn($v) => Normalize::email($v), 'Enter a valid email address', $issues);
            $phone = self::parseOptional($in['phone'] ?? null, 'phone', static fn($v) => Normalize::phone($v, $country), 'Enter a valid phone number (include the country code if it is not local)', $issues);
            $altEmails = self::parseMany($in['alternateEmails'] ?? null, 'alternateEmails', static fn($v) => Normalize::email($v), 'Enter a valid email address', $issues);
            $altPhones = self::parseMany($in['alternatePhones'] ?? null, 'alternatePhones', static fn($v) => Normalize::phone($v, $country), 'Enter a valid phone number', $issues);
            if ($issues) throw Errors::validation($issues);
            $idents = self::composeIdentifiers($email, $altEmails, $phone, $altPhones);
            $ownerId = self::resolveOwner($tx, $actor, array_key_exists('ownerId', $in) ? $in['ownerId'] : false, $actor->userId);
            self::assertNoDuplicates($tx, $actor->tenantId, $idents);
            $id = $tx->insert('contacts', [
                'tenant_id' => $actor->tenantId, 'first_name' => trim($in['firstName']), 'last_name' => Normalize::blank($in['lastName'] ?? null), 'email' => $email, 'phone' => $phone,
                'company' => Normalize::blank($in['company'] ?? null), 'job_title' => Normalize::blank($in['jobTitle'] ?? null), 'city' => Normalize::blank($in['city'] ?? null),
                'owner_id' => $ownerId, 'created_by' => $actor->userId, 'tags' => Json::enc(Normalize::tags($in['tags'] ?? [])),
                'custom_fields' => Json::encObj($in['customFields'] ?? []), 'source_summary' => '{}',
            ]);
            self::insertIdentifiers($tx, $actor->tenantId, $id, $idents);
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'contact.create', 'contact', $id, null, $dto);
            Events::emit($tx, 'contact.created', $id, ['ownerId' => $ownerId]);
            return $dto;
        });
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('contacts:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $row = $tx->one('SELECT id, owner_id, version, email, phone FROM contacts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Contact');
            $actor->assertCanActOn('contacts:update', $row['owner_id']);
            if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) throw Errors::preconditionFailed();
            $before = self::fetch($tx, $actor->tenantId, $id);

            $set = ['version' => new Raw('version + 1')];
            if (array_key_exists('firstName', $patch)) $set['first_name'] = trim($patch['firstName']);
            if (array_key_exists('lastName', $patch)) $set['last_name'] = Normalize::blank($patch['lastName']);
            if (array_key_exists('company', $patch)) $set['company'] = Normalize::blank($patch['company']);
            if (array_key_exists('jobTitle', $patch)) $set['job_title'] = Normalize::blank($patch['jobTitle']);
            if (array_key_exists('city', $patch)) $set['city'] = Normalize::blank($patch['city']);
            if (array_key_exists('tags', $patch)) $set['tags'] = Json::enc(Normalize::tags($patch['tags']));
            if (array_key_exists('customFields', $patch)) $set['custom_fields'] = Json::encObj($patch['customFields'] ?? []);
            if (array_key_exists('ownerId', $patch)) $set['owner_id'] = self::resolveOwner($tx, $actor, $patch['ownerId'], null);

            $emailTouched = array_key_exists('email', $patch) || array_key_exists('alternateEmails', $patch);
            $phoneTouched = array_key_exists('phone', $patch) || array_key_exists('alternatePhones', $patch);
            if ($emailTouched || $phoneTouched) {
                $country = self::defaultCountry($tx, $actor->tenantId);
                $next = [];
                $issues = [];
                if ($emailTouched) {
                    $primary = array_key_exists('email', $patch) ? self::parseOptional($patch['email'], 'email', static fn($v) => Normalize::email($v), 'Enter a valid email address', $issues) : $before['email'];
                    $alts = array_key_exists('alternateEmails', $patch) ? self::parseMany($patch['alternateEmails'], 'alternateEmails', static fn($v) => Normalize::email($v), 'Enter a valid email address', $issues) : $before['alternateEmails'];
                    array_push($next, ...self::composeIdentifiers($primary, $alts, null, []));
                    $set['email'] = $primary;
                }
                if ($phoneTouched) {
                    $primary = array_key_exists('phone', $patch) ? self::parseOptional($patch['phone'], 'phone', static fn($v) => Normalize::phone($v, $country), 'Enter a valid phone number', $issues) : $before['phone'];
                    $alts = array_key_exists('alternatePhones', $patch) ? self::parseMany($patch['alternatePhones'], 'alternatePhones', static fn($v) => Normalize::phone($v, $country), 'Enter a valid phone number', $issues) : $before['alternatePhones'];
                    array_push($next, ...self::composeIdentifiers(null, [], $primary, $alts));
                    $set['phone'] = $primary;
                }
                if ($issues) throw Errors::validation($issues);
                self::assertNoDuplicates($tx, $actor->tenantId, $next, $id);
                self::replaceIdentifiers($tx, $actor->tenantId, $id, $next, $emailTouched, $phoneTouched);
            }
            $tx->update('contacts', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            $after = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'contact.update', 'contact', $id, $before, $after);
            Events::emit($tx, 'contact.updated', $id, ['version' => $after['version']]);
            return $after;
        });
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('contacts:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $before = self::fetch($tx, $actor->tenantId, $id);
            if (!$before) throw Errors::notFound('Contact');
            $tx->run('UPDATE contacts SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            $tx->run('UPDATE contact_identifiers SET released_at = NOW(3) WHERE contact_id = ? AND tenant_id = ? AND released_at IS NULL', [$id, $actor->tenantId]);
            Audit::log($tx, 'contact.delete', 'contact', $id, $before);
            Events::emit($tx, 'contact.deleted', $id);
        });
    }

    public static function restore(Actor $actor, string $id): array
    {
        $actor->assertCan('contacts:delete');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $n = $tx->run('UPDATE contacts SET deleted_at = NULL, version = version + 1 WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL AND merged_into_id IS NULL', [$id, $actor->tenantId]);
            if (!$n) throw Errors::notFound('Archived contact');
            try {
                $tx->run('UPDATE contact_identifiers SET released_at = NULL WHERE contact_id = ? AND tenant_id = ? AND released_at IS NOT NULL', [$id, $actor->tenantId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('An email or phone number of this contact now belongs to another contact; merge or edit that contact first');
                throw $e;
            }
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'contact.restore', 'contact', $id, null, $dto);
            Events::emit($tx, 'contact.updated', $id, ['restored' => true]);
            return $dto;
        });
    }

    // ── merge / unmerge ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Fold $duplicateIds into $survivorId. Blank fields on the survivor are filled from the duplicates (never overwritten), tags are
     * unioned, identifiers / leads / consents are re-parented. A snapshot is stored so the merge can be undone for 30 days.
     *
     * @param string[] $duplicateIds
     */
    public static function merge(Actor $actor, string $survivorId, array $duplicateIds): array
    {
        $actor->assertCan('contacts:merge');
        $dupes = array_values(array_filter(array_unique($duplicateIds), static fn($d) => $d !== $survivorId));
        if (!$dupes) throw Errors::badRequest('Choose at least one other contact to merge');
        if (count($dupes) > self::MAX_MERGE_BATCH) throw Errors::badRequest('Merge at most ' . self::MAX_MERGE_BATCH . ' contacts at a time');

        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $survivorId, $dupes) {
            // Lock in a stable order so two concurrent merges over the same pair cannot deadlock.
            $ids = array_merge([$survivorId], $dupes);
            sort($ids);
            $locked = $tx->all('SELECT id FROM contacts WHERE id IN (?) AND tenant_id = ? AND deleted_at IS NULL ORDER BY id FOR UPDATE', [$ids, $actor->tenantId]);
            if (count($locked) !== count($ids)) throw Errors::notFound('Contact');

            $survivor = self::fetch($tx, $actor->tenantId, $survivorId);
            $merges = [];
            $tags = $survivor['tags'];
            $fill = [];
            foreach ($dupes as $dupId) {
                $dup = self::fetch($tx, $actor->tenantId, $dupId);
                $identIds = $tx->col('SELECT id FROM contact_identifiers WHERE contact_id = ? AND tenant_id = ? AND released_at IS NULL', [$dupId, $actor->tenantId]);
                $leadIds = $tx->col('SELECT id FROM leads WHERE contact_id = ? AND tenant_id = ?', [$dupId, $actor->tenantId]);
                $consentIds = $tx->col('SELECT id FROM communication_consents WHERE contact_id = ? AND tenant_id = ?', [$dupId, $actor->tenantId]);

                // Re-parent. Moved identifiers become secondary: the survivor keeps its own primaries.
                $tx->run('UPDATE contact_identifiers SET contact_id = ?, is_primary = 0 WHERE contact_id = ? AND tenant_id = ? AND released_at IS NULL', [$survivorId, $dupId, $actor->tenantId]);
                $tx->run('UPDATE leads SET contact_id = ?, version = version + 1 WHERE contact_id = ? AND tenant_id = ?', [$survivorId, $dupId, $actor->tenantId]);
                $tx->run('UPDATE communication_consents SET contact_id = ? WHERE contact_id = ? AND tenant_id = ?', [$survivorId, $dupId, $actor->tenantId]);
                $tx->run('UPDATE contacts SET deleted_at = NOW(3), merged_into_id = ?, email = NULL, phone = NULL, version = version + 1 WHERE id = ? AND tenant_id = ?', [$survivorId, $dupId, $actor->tenantId]);

                $tags = Normalize::tags(array_merge($tags, $dup['tags']));
                foreach (['lastName', 'company', 'jobTitle', 'city'] as $f) if (empty($survivor[$f]) && empty($fill[$f]) && !empty($dup[$f])) $fill[$f] = $dup[$f];
                if (empty($survivor['ownerId']) && empty($fill['ownerId']) && !empty($dup['ownerId'])) $fill['ownerId'] = $dup['ownerId'];

                $snap = ['before' => ['email' => $dup['email'], 'phone' => $dup['phone'], 'ownerId' => $dup['ownerId'], 'tags' => $dup['tags']], 'identifierIds' => $identIds, 'leadIds' => $leadIds, 'consentIds' => $consentIds];
                $mid = $tx->insert('contact_merges', ['tenant_id' => $actor->tenantId, 'survivor_id' => $survivorId, 'merged_id' => $dupId, 'snapshot' => Json::enc($snap), 'merged_by' => $actor->userId]);
                $merges[] = ['id' => $mid, 'mergedId' => $dupId];
            }
            $set = ['tags' => Json::enc($tags), 'version' => new Raw('version + 1')];
            $colOf = ['lastName' => 'last_name', 'company' => 'company', 'jobTitle' => 'job_title', 'city' => 'city', 'ownerId' => 'owner_id'];
            foreach ($fill as $k => $v) $set[$colOf[$k]] = $v;
            $tx->update('contacts', $set, 'id = ? AND tenant_id = ?', [$survivorId, $actor->tenantId]);
            // A survivor without a primary email/phone adopts one of the moved identifiers.
            self::promotePrimaries($tx, $actor->tenantId, $survivorId);
            $after = self::fetch($tx, $actor->tenantId, $survivorId);
            Audit::log($tx, 'contact.merge', 'contact', $survivorId, $survivor, $after + ['mergedIds' => $dupes]);
            Events::emit($tx, 'contact.merged', $survivorId, ['mergedIds' => $dupes]);
            return ['contact' => $after, 'merges' => $merges];
        });
    }

    public static function undoMerge(Actor $actor, string $mergeId): array
    {
        $actor->assertCan('contacts:merge');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $mergeId) {
            $row = $tx->one('SELECT * FROM contact_merges WHERE id = ? AND tenant_id = ? FOR UPDATE', [$mergeId, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Merge');
            if ($row['undone_at']) throw Errors::conflict('This merge was already undone');
            if (Time::nowMs() - (int) Time::ms($row['created_at']) > self::MERGE_UNDO_WINDOW_DAYS * 86400000) throw Errors::conflict('Merges can only be undone within ' . self::MERGE_UNDO_WINDOW_DAYS . ' days');
            $snap = Json::dec($row['snapshot'], []);
            $b = $snap['before'] ?? [];

            // Bring the record back, then give it its own things back (only those still held by the survivor).
            $tx->run('UPDATE contacts SET deleted_at = NULL, merged_into_id = NULL, email = ?, phone = ?, version = version + 1 WHERE id = ? AND tenant_id = ?', [$b['email'] ?? null, $b['phone'] ?? null, $row['merged_id'], $actor->tenantId]);
            if (!empty($snap['identifierIds'])) $tx->run('UPDATE contact_identifiers SET contact_id = ? WHERE id IN (?) AND contact_id = ? AND tenant_id = ?', [$row['merged_id'], $snap['identifierIds'], $row['survivor_id'], $actor->tenantId]);
            if (!empty($snap['leadIds'])) $tx->run('UPDATE leads SET contact_id = ?, version = version + 1 WHERE id IN (?) AND contact_id = ? AND tenant_id = ?', [$row['merged_id'], $snap['leadIds'], $row['survivor_id'], $actor->tenantId]);
            if (!empty($snap['consentIds'])) $tx->run('UPDATE communication_consents SET contact_id = ? WHERE id IN (?) AND contact_id = ? AND tenant_id = ?', [$row['merged_id'], $snap['consentIds'], $row['survivor_id'], $actor->tenantId]);
            self::promotePrimaries($tx, $actor->tenantId, $row['merged_id']);
            self::promotePrimaries($tx, $actor->tenantId, $row['survivor_id']);
            $tx->run('UPDATE contact_merges SET undone_at = NOW(3) WHERE id = ?', [$mergeId]);

            $survivor = self::fetch($tx, $actor->tenantId, $row['survivor_id']);
            $restored = self::fetch($tx, $actor->tenantId, $row['merged_id']);
            Audit::log($tx, 'contact.unmerge', 'contact', $row['survivor_id'], null, ['restoredId' => $row['merged_id']]);
            Events::emit($tx, 'contact.updated', $row['survivor_id'], ['unmerged' => $row['merged_id']]);
            Events::emit($tx, 'contact.created', $row['merged_id'], ['unmerged' => true]);
            return ['survivor' => $survivor, 'restored' => $restored];
        });
    }

    // ── consent (append-only grants; revocation closes the open grants) ───────────────────────────────────────────

    public static function consents(Actor $actor, string $contactId): array
    {
        $actor->assertCan('contacts:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $contactId) {
            if (!self::fetch($tx, $actor->tenantId, $contactId)) throw Errors::notFound('Contact');
            $rows = $tx->all(
                'SELECT id, channel, status, source, legal_basis AS legalBasis, proof_url AS proofUrl, expires_at AS expiresAt, revoked_at AS revokedAt, revoke_reason AS revokeReason, created_at AS createdAt
                   FROM communication_consents WHERE contact_id = ? AND tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT 200',
                [$contactId, $actor->tenantId]
            );
            $current = [];
            foreach (['email', 'sms', 'whatsapp', 'push', 'phone_call'] as $channel) {
                $g = null;
                foreach ($rows as $r) {
                    if ($r['channel'] === $channel && $r['status'] === 'granted' && (!$r['expiresAt'] || Time::ms($r['expiresAt']) > Time::nowMs())) { $g = $r; break; }
                }
                $current[] = ['channel' => $channel, 'granted' => $g !== null, 'since' => $g['createdAt'] ?? null, 'source' => $g['source'] ?? null];
            }
            return ['current' => $current, 'history' => $rows];
        });
    }

    /** @param array{channel:string,action:string,source:string,legalBasis?:?string,proofUrl?:?string,reason?:?string,expiresAt?:?\DateTimeImmutable} $in */
    public static function recordConsent(Actor $actor, string $contactId, array $in): array
    {
        $actor->assertCan('contacts:update');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $contactId, $in) {
            $c = $tx->one('SELECT owner_id FROM contacts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$contactId, $actor->tenantId]);
            if (!$c) throw Errors::notFound('Contact');
            $actor->assertCanActOn('contacts:update', $c['owner_id']);
            if ($in['action'] === 'grant') {
                $tx->insert('communication_consents', [
                    'tenant_id' => $actor->tenantId, 'contact_id' => $contactId, 'channel' => $in['channel'], 'status' => 'granted', 'source' => $in['source'],
                    'legal_basis' => $in['legalBasis'] ?? 'consent', 'proof_url' => $in['proofUrl'] ?? null, 'expires_at' => $in['expiresAt'] ?? null, 'ip_address' => $actor->ip,
                ]);
            } else {
                $tx->run("UPDATE communication_consents SET status = 'revoked', revoked_at = NOW(3), revoke_reason = ? WHERE contact_id = ? AND tenant_id = ? AND channel = ? AND status = 'granted'", [$in['reason'] ?? null, $contactId, $actor->tenantId, $in['channel']]);
            }
            Audit::log($tx, 'consent.' . $in['action'], 'contact', $contactId, null, ['channel' => $in['channel'], 'source' => $in['source']]);
            Events::emit($tx, 'contact.updated', $contactId, ['consent' => $in['channel']]);
        });
        return self::consents($actor, $contactId);
    }

    // ── intake (leads, webhooks, imports) ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve "who is this?" for an inbound lead: reuse the contact that already owns the e-mail / phone number, or create one. Runs inside
     * the caller's workspace transaction and is safe under concurrent intake of the same person (a duplicate-key error re-reads). Unparsable
     * phone / e-mail values are kept as plain text on the contact but never become identity keys. If the e-mail and the phone belong to two
     * different contacts the e-mail match wins.
     *
     * @param array{tenantId:string,userId:?string} $ctx
     * @param array{firstName:string,lastName?:?string,email?:?string,phone?:?string} $input
     * @return array{id:string,created:bool}
     */
    public static function findOrCreateInTx(Tx $tx, array $ctx, array $input): array
    {
        $country = self::defaultCountry($tx, $ctx['tenantId']);
        $email = Normalize::email($input['email'] ?? null);
        $phone = Normalize::phone($input['phone'] ?? null, $country);
        $idents = self::composeIdentifiers($email, [], $phone, []);
        $existing = static function () use ($tx, $ctx, $idents) {
            $hits = self::findDuplicates($tx, $ctx['tenantId'], $idents);
            foreach ($hits as $h) if (in_array('email', $h['matchedOn'], true)) return $h;
            return $hits[0] ?? null;
        };
        if ($found = $existing()) return ['id' => $found['id'], 'created' => false];
        try {
            $id = $tx->insert('contacts', [
                'tenant_id' => $ctx['tenantId'], 'first_name' => trim($input['firstName']) !== '' ? trim($input['firstName']) : 'Unknown', 'last_name' => Normalize::blank($input['lastName'] ?? null),
                'email' => $email ?? Normalize::blank($input['email'] ?? null), 'phone' => $phone ?? Normalize::blank($input['phone'] ?? null),
                'owner_id' => $ctx['userId'], 'created_by' => $ctx['userId'], 'tags' => '[]', 'custom_fields' => '{}', 'source_summary' => '{}',
            ]);
            self::insertIdentifiers($tx, $ctx['tenantId'], $id, $idents);
            return ['id' => $id, 'created' => true];
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            // InnoDB rolls back only the failed statement: a concurrent intake of the same person won, so the contact exists now
            $raced = $existing();
            if (!$raced) throw $e;
            return ['id' => $raced['id'], 'created' => false];
        }
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function fetch(Tx $tx, string $tenantId, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM contacts c WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL', [$id, $tenantId]);
        return $r ? self::dto($r) : null;
    }

    private static function defaultCountry(Tx $tx, string $tenantId): string
    {
        $s = Json::dec((string) $tx->val('SELECT settings FROM tenants WHERE id = ?', [$tenantId]), []);
        $c = $s['defaultCountry'] ?? null;
        return is_string($c) && preg_match('/^[A-Za-z]{2}$/', $c) ? strtoupper($c) : 'IN';
    }

    /** @param array<int,array{path:string,message:string,code?:string}> $issues */
    private static function parseOptional(?string $raw, string $path, callable $norm, string $message, array &$issues): ?string
    {
        if ($raw === null || trim($raw) === '') return null;
        $v = $norm($raw);
        if ($v === null) $issues[] = ['path' => "body.$path", 'message' => $message, 'code' => 'invalid_format'];
        return $v;
    }

    /** @return string[] */
    private static function parseMany(?array $raw, string $path, callable $norm, string $message, array &$issues): array
    {
        $out = [];
        foreach (($raw ?? []) as $i => $r) {
            $v = $norm((string) $r);
            if ($v === null) $issues[] = ['path' => "body.$path.$i", 'message' => $message, 'code' => 'invalid_format'];
            elseif (!in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    /** @return array<int,array{kind:string,value:string,primary:bool}> */
    private static function composeIdentifiers(?string $email, array $altEmails, ?string $phone, array $altPhones): array
    {
        $out = [];
        $add = static function (string $kind, ?string $primary, array $alts) use (&$out): void {
            if ($primary) $out[] = ['kind' => $kind, 'value' => $primary, 'primary' => true];
            foreach ($alts as $a) {
                if ($a === $primary) continue;
                foreach ($out as $o) if ($o['kind'] === $kind && $o['value'] === $a) continue 2;
                $out[] = ['kind' => $kind, 'value' => $a, 'primary' => false];
            }
        };
        $add('email', $email, $altEmails);
        $add('phone', $phone, $altPhones);
        if (count($out) > 12) throw Errors::validation([['path' => 'body', 'message' => 'A contact can have at most 12 emails and phone numbers in total']]);
        return $out;
    }

    /** @return array<int,array{id:string,displayName:string,matchedOn:string[]}> */
    private static function findDuplicates(Tx $tx, string $tenantId, array $idents, ?string $exceptContactId = null): array
    {
        if (!$idents) return [];
        $conds = [];
        $params = [$tenantId];
        foreach ($idents as $i) { $conds[] = '(i.kind = ? AND i.value = ?)'; $params[] = $i['kind']; $params[] = $i['value']; }
        $sql = 'SELECT c.id, c.display_name AS displayName, i.kind FROM contact_identifiers i JOIN contacts c ON c.id = i.contact_id AND c.tenant_id = i.tenant_id
                 WHERE i.tenant_id = ? AND i.released_at IS NULL AND c.deleted_at IS NULL AND (' . implode(' OR ', $conds) . ')';
        if ($exceptContactId !== null) { $sql .= ' AND c.id <> ?'; $params[] = $exceptContactId; }
        $by = [];
        foreach ($tx->all($sql, $params) as $r) {
            $by[$r['id']] ??= ['id' => $r['id'], 'displayName' => $r['displayName'], 'matchedOn' => []];
            if (!in_array($r['kind'], $by[$r['id']]['matchedOn'], true)) $by[$r['id']]['matchedOn'][] = $r['kind'];
        }
        return array_values($by);
    }

    private static function assertNoDuplicates(Tx $tx, string $tenantId, array $idents, ?string $exceptContactId = null): void
    {
        $dupes = self::findDuplicates($tx, $tenantId, $idents, $exceptContactId);
        if ($dupes) {
            $kinds = array_values(array_unique(array_merge(...array_column($dupes, 'matchedOn'))));
            $what = implode(' and ', array_map(static fn($k) => $k === 'email' ? 'email address' : 'phone number', $kinds));
            throw Errors::conflict("A contact with this $what already exists", ['reason' => 'duplicate_contact', 'duplicates' => $dupes]);
        }
    }

    private static function insertIdentifiers(Tx $tx, string $tenantId, string $contactId, array $idents): void
    {
        foreach ($idents as $i) $tx->insert('contact_identifiers', ['tenant_id' => $tenantId, 'contact_id' => $contactId, 'kind' => $i['kind'], 'value' => $i['value'], 'is_primary' => $i['primary'] ? 1 : 0]);
    }

    /** Make the live identifiers of the touched kinds exactly equal $next (delete / insert / re-flag). */
    private static function replaceIdentifiers(Tx $tx, string $tenantId, string $contactId, array $next, bool $email, bool $phone): void
    {
        $kinds = array_values(array_filter([$email ? 'email' : null, $phone ? 'phone' : null]));
        $cur = $tx->all('SELECT id, kind, value FROM contact_identifiers WHERE contact_id = ? AND tenant_id = ? AND released_at IS NULL AND kind IN (?) FOR UPDATE', [$contactId, $tenantId, $kinds]);
        $wanted = [];
        foreach ($next as $n) if (in_array($n['kind'], $kinds, true)) $wanted[$n['kind'] . ':' . $n['value']] = true;
        $have = [];
        foreach ($cur as $r) $have[$r['kind'] . ':' . $r['value']] = true;
        $drop = [];
        foreach ($cur as $r) if (!isset($wanted[$r['kind'] . ':' . $r['value']])) $drop[] = $r['id'];
        if ($drop) $tx->run('DELETE FROM contact_identifiers WHERE id IN (?)', [$drop]);
        $tx->run('UPDATE contact_identifiers SET is_primary = 0 WHERE contact_id = ? AND tenant_id = ? AND kind IN (?) AND released_at IS NULL', [$contactId, $tenantId, $kinds]);
        $add = [];
        foreach ($next as $n) if (in_array($n['kind'], $kinds, true) && !isset($have[$n['kind'] . ':' . $n['value']])) $add[] = ['kind' => $n['kind'], 'value' => $n['value'], 'primary' => false];
        self::insertIdentifiers($tx, $tenantId, $contactId, $add);
        foreach ($next as $p) {
            if ($p['primary'] && in_array($p['kind'], $kinds, true)) $tx->run('UPDATE contact_identifiers SET is_primary = 1 WHERE contact_id = ? AND tenant_id = ? AND kind = ? AND value = ? AND released_at IS NULL', [$contactId, $tenantId, $p['kind'], $p['value']]);
        }
    }

    /** Keep contacts.email/phone (display copy) and the is_primary flags consistent after identifiers moved. */
    private static function promotePrimaries(Tx $tx, string $tenantId, string $contactId): void
    {
        foreach (['email', 'phone'] as $kind) {
            $has = $tx->exists('SELECT 1 FROM contact_identifiers WHERE contact_id = ? AND tenant_id = ? AND kind = ? AND is_primary = 1 AND released_at IS NULL', [$contactId, $tenantId, $kind]);
            if (!$has) {
                $first = $tx->val('SELECT id FROM contact_identifiers WHERE contact_id = ? AND tenant_id = ? AND kind = ? AND released_at IS NULL ORDER BY created_at, id LIMIT 1', [$contactId, $tenantId, $kind]);
                if ($first) $tx->run('UPDATE contact_identifiers SET is_primary = 1 WHERE id = ?', [$first]);
            }
            $value = $tx->val('SELECT value FROM contact_identifiers WHERE contact_id = ? AND tenant_id = ? AND kind = ? AND is_primary = 1 AND released_at IS NULL', [$contactId, $tenantId, $kind]);
            $tx->run('UPDATE contacts SET ' . $kind . ' = ?, version = version + 1 WHERE id = ? AND tenant_id = ?', [$value, $contactId, $tenantId]);
        }
    }

    /**
     * Owner must be an active member. Managers may assign anyone (or nobody); agents (own-scope) only ever own what they create or take.
     * $requested === false means "not specified" (use the default).
     */
    private static function resolveOwner(Tx $tx, Actor $actor, string|null|false $requested, ?string $defaultOwner): ?string
    {
        $manager = $actor->kind === 'system' || Matrix::scopeOf($actor->role, 'contacts:update') === 'all';
        if ($requested === false) $owner = $defaultOwner;
        else {
            if (!$manager && $requested !== $actor->userId) throw Errors::forbidden('Only managers can assign a contact to someone else');
            $owner = $requested;
        }
        if (!$owner) return null;
        if (!$tx->exists("SELECT 1 FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active' AND is_active = 1", [$actor->tenantId, $owner])) {
            throw Errors::validation([['path' => 'body.ownerId', 'message' => 'Owner must be an active member of this workspace']]);
        }
        return $owner;
    }
}
