<?php
declare(strict_types=1);

namespace App\Modules\Search;

use App\Core\Actor;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Tx;
use App\Modules\Contacts\Normalize;

/**
 * Global search (the ⌘K palette). One endpoint, five kinds of record, at most 5 of each, and ONLY what the caller may see:
 *   leads       leads:read      (sales agents: their own leads)
 *   contacts    contacts:read
 *   properties  properties:read
 *   projects    projects:read
 *   tasks       tasks:read      (sales agents: their own tasks)
 * Kinds a role may not read are absent from the answer (not empty), so the response itself reveals nothing about records the caller
 * cannot see. Matching is a literal case-insensitive "contains" (LIKE metacharacters are escaped) over names, e-mail, phone digits,
 * titles, places and unit numbers; archived records are never returned.
 */
final class SearchService
{
    private const PER_KIND = 5;

    /** Phone number with the usual separators removed, as SQL (portable: no REGEXP_REPLACE). */
    private static function digitsOf(string $col): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    }

    public static function search(Actor $actor, string $raw): array
    {
        $actor->assertCan('search:use');
        $q = trim((string) preg_replace('/\s+/', ' ', $raw));
        if (mb_strlen($q) < 2) throw Errors::validation([['path' => 'query.q', 'message' => 'Type at least 2 characters']]);
        $like = '%' . Normalize::escapeLike($q) . '%';
        $digits = (string) preg_replace('/\D/', '', $q);
        $phoneLike = strlen($digits) >= 3 ? "%$digits%" : null;
        $per = self::PER_KIND;

        $results = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $like, $phoneLike, $per) {
            $results = [];
            $t = $actor->tenantId;
            $phone = $phoneLike !== null ? ' OR ' . self::digitsOf('c.phone') . ' LIKE ?' : '';
            $phoneParam = $phoneLike !== null ? [$phoneLike] : [];

            $leadScope = $actor->scopeIfAllowed('leads:read');
            if ($leadScope) {
                $params = [$t];
                $own = '';
                if ($leadScope === 'own') { $own = ' AND l.owner_id = ?'; $params[] = $actor->userId; }
                $params = array_merge($params, [$like, $like, $like], $phoneParam);
                $rows = $tx->all(
                    "SELECT l.id, c.display_name AS name, s.name AS stage, l.source, l.campaign
                       FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id
                      WHERE l.tenant_id = ? AND l.deleted_at IS NULL$own AND (c.display_name LIKE ? OR c.email LIKE ? OR l.campaign LIKE ?$phone)
                      ORDER BY (l.closed_at IS NULL) DESC, l.created_at DESC, l.id LIMIT $per",
                    $params
                );
                $results['leads'] = array_map(static fn($r) => [
                    'id' => $r['id'], 'title' => $r['name'] ?? 'Lead',
                    'subtitle' => implode(' · ', array_filter([$r['stage'], $r['source'] !== null ? str_replace('_', ' ', $r['source']) : null, $r['campaign']], static fn($x) => $x !== null && $x !== '')),
                    'href' => '/leads/' . $r['id'],
                ], $rows);
            }

            if ($actor->scopeIfAllowed('contacts:read')) {
                $rows = $tx->all(
                    "SELECT c.id, c.display_name AS name, c.email, c.phone, c.company FROM contacts c
                      WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND (c.display_name LIKE ? OR c.email LIKE ? OR c.company LIKE ?$phone)
                      ORDER BY c.display_name, c.id LIMIT $per",
                    array_merge([$t, $like, $like, $like], $phoneParam)
                );
                $results['contacts'] = array_map(static fn($r) => [
                    'id' => $r['id'], 'title' => $r['name'],
                    'subtitle' => implode(' · ', array_filter([$r['company'], $r['email'] ?? $r['phone']], static fn($x) => $x !== null && $x !== '')) ?: null,
                    'href' => '/contacts?c=' . $r['id'],
                ], $rows);
            }

            if ($actor->scopeIfAllowed('properties:read')) {
                $rows = $tx->all(
                    "SELECT p.id, p.title, p.status, COALESCE(p.location, NULLIF(CONCAT_WS(', ', p.locality, p.city), '')) AS place, p.unit_number
                       FROM properties p
                      WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND (p.title LIKE ? OR p.locality LIKE ? OR p.city LIKE ? OR p.location LIKE ? OR p.unit_number LIKE ?)
                      ORDER BY p.created_at DESC, p.id LIMIT $per",
                    [$t, $like, $like, $like, $like, $like]
                );
                $results['properties'] = array_map(static fn($r) => [
                    'id' => $r['id'], 'title' => $r['title'],
                    'subtitle' => implode(' · ', array_filter([$r['unit_number'] ? 'Unit ' . $r['unit_number'] : null, $r['place'], str_replace('_', ' ', (string) $r['status'])], static fn($x) => $x !== null && $x !== '')),
                    'href' => '/properties/' . $r['id'],
                ], $rows);
            }

            if ($actor->scopeIfAllowed('projects:read')) {
                $rows = $tx->all(
                    "SELECT pr.id, pr.name, pr.city, pr.locality FROM projects pr WHERE pr.tenant_id = ? AND pr.deleted_at IS NULL AND (pr.name LIKE ? OR pr.locality LIKE ? OR pr.city LIKE ?)
                      ORDER BY pr.name, pr.id LIMIT $per",
                    [$t, $like, $like, $like]
                );
                $results['projects'] = array_map(static fn($r) => ['id' => $r['id'], 'title' => $r['name'], 'subtitle' => implode(', ', array_filter([$r['locality'], $r['city']], static fn($x) => $x !== null && $x !== '')) ?: null, 'href' => '/projects'], $rows);
            }

            $taskScope = $actor->scopeIfAllowed('tasks:read');
            if ($taskScope) {
                $params = [$t];
                $own = '';
                if ($taskScope === 'own') { $own = ' AND k.assigned_to = ?'; $params[] = $actor->userId; }
                $params[] = $like;
                $rows = $tx->all(
                    "SELECT k.id, k.title, k.status, k.due_date FROM tasks k WHERE k.tenant_id = ? AND k.deleted_at IS NULL$own AND k.title LIKE ?
                      ORDER BY (k.status IN ('pending','in_progress')) DESC, (k.due_date IS NULL), k.due_date, k.id LIMIT $per",
                    $params
                );
                $results['tasks'] = array_map(static fn($r) => [
                    'id' => $r['id'], 'title' => $r['title'],
                    'subtitle' => implode(' · ', array_filter([str_replace('_', ' ', (string) $r['status']), $r['due_date'] ? 'due ' . substr((string) $r['due_date'], 0, 10) : null])),
                    'href' => '/tasks',
                ], $rows);
            }
            return $results;
        });
        return ['query' => $q, 'results' => (object) $results];
    }
}
