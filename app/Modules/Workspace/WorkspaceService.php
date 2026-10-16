<?php
declare(strict_types=1);

namespace App\Modules\Workspace;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Identity\Sessions;
use App\Modules\Leads\Routing;

/**
 * Workspace settings: ONE typed, validated, merge-style writer of tenants.settings and the tenants.timezone column.
 * Only known keys are accepted; an update touches ONLY the keys it names; the time zone lives in the column; an optional If-Match makes
 * concurrent edits fail with 412 instead of overwriting each other; every change is audited (never secrets: there are none here).
 */
final class WorkspaceService
{
    public const ASSIGNMENT_MODES = ['creator', 'balanced', 'unassigned'];

    public static function isValidTimeZone(string $tz): bool
    {
        return ($tz === 'UTC' || str_contains($tz, '/')) && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    /** @param array<string,mixed> $r row of tenants */
    private static function toDto(array $r): array
    {
        $s = is_string($r['settings']) ? (Json::dec($r['settings'], []) ?: []) : (array) $r['settings'];
        $mode = $s['leadAssignment'] ?? null;
        $mins = $s['sla']['firstResponseMinutes'] ?? null;
        return [
            'name' => $r['name'],
            'slug' => $r['slug'],
            'timezone' => $r['timezone'],
            'currency' => is_string($s['currency'] ?? null) ? $s['currency'] : 'INR',
            'defaultCountry' => is_string($s['defaultCountry'] ?? null) ? $s['defaultCountry'] : null,
            'leadAssignment' => ($mode === 'balanced' || $mode === 'unassigned') ? $mode : 'creator',
            'firstResponseMinutes' => (is_numeric($mins) && $mins >= 0 && $mins <= 10080) ? (int) $mins : 30,
            'routing' => Routing::parse($s['routing'] ?? null),
            'primaryColor' => is_string($s['primary_color'] ?? null) ? $s['primary_color'] : null,
            'accentColor' => is_string($s['accent_color'] ?? null) ? $s['accent_color'] : null,
            'version' => (int) Time::ms($r['updated_at']),
        ];
    }

    public static function get(Actor $actor): array
    {
        $actor->assertCan('tenant:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $r = $tx->one('SELECT name, slug, timezone, settings, updated_at FROM tenants WHERE id = ?', [$actor->tenantId]);
            if (!$r) throw Errors::notFound('Workspace');
            return self::toDto($r);
        });
    }

    /** @param array<string,mixed> $patch validated body; only the keys present are changed */
    public static function update(Actor $actor, array $patch, ?int $ifMatch): array
    {
        $actor->assertCan('tenant:update');
        if (isset($patch['timezone']) && !self::isValidTimeZone($patch['timezone'])) throw Errors::validation([['path' => 'body.timezone', 'message' => 'Use an IANA time zone such as Asia/Kolkata']]);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $patch, $ifMatch) {
            $cur = $tx->one('SELECT name, slug, timezone, settings, updated_at FROM tenants WHERE id = ? FOR UPDATE', [$actor->tenantId]);
            if (!$cur) throw Errors::notFound('Workspace');
            if ($ifMatch !== null && $ifMatch !== Time::ms($cur['updated_at'])) throw Errors::preconditionFailed('Someone else changed the workspace settings. Reload and try again.');
            $before = self::toDto($cur);
            $settings = Json::dec($cur['settings'], []) ?: [];
            $changed = [];
            $setKey = static function (string $key, mixed $value, string $label) use (&$settings, &$changed): void {
                if ($value === null) unset($settings[$key]);
                else $settings[$key] = $value;
                $changed[] = $label;
            };
            if (array_key_exists('currency', $patch)) $setKey('currency', strtoupper($patch['currency']), 'currency');
            if (array_key_exists('defaultCountry', $patch)) $setKey('defaultCountry', $patch['defaultCountry'] ? strtoupper($patch['defaultCountry']) : null, 'defaultCountry');
            if (array_key_exists('leadAssignment', $patch)) $setKey('leadAssignment', $patch['leadAssignment'], 'leadAssignment');
            if (array_key_exists('primaryColor', $patch)) $setKey('primary_color', $patch['primaryColor'], 'primaryColor');
            if (array_key_exists('accentColor', $patch)) $setKey('accent_color', $patch['accentColor'], 'accentColor');
            if (array_key_exists('firstResponseMinutes', $patch)) {
                $sla = (isset($settings['sla']) && is_array($settings['sla'])) ? $settings['sla'] : [];
                $sla['firstResponseMinutes'] = $patch['firstResponseMinutes'];
                $settings['sla'] = $sla;
                $changed[] = 'firstResponseMinutes';
            }
            if (array_key_exists('routing', $patch)) {
                $rules = array_values(array_filter($patch['routing'], static fn($r) => in_array($r['source'], Routing::SOURCES, true)));
                if (count(array_unique(array_column($rules, 'source'))) !== count($rules)) throw Errors::validation([['path' => 'body.routing', 'message' => 'Each source can have only one rule']]);
                $ids = array_values(array_unique(array_merge(...array_map(static fn($r) => $r['userIds'], $rules ?: [[ 'userIds' => [] ]]))));
                if ($ids) {
                    $good = array_flip($tx->col(
                        "SELECT tm.user_id FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id AND u.is_active = 1
                          WHERE tm.tenant_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND tm.user_id IN (?) AND tm.role IN ('sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner')",
                        [$actor->tenantId, $ids]
                    ));
                    foreach ($ids as $i) if (!isset($good[$i])) throw Errors::validation([['path' => 'body.routing', 'message' => 'Every person in a rule must work leads in this client']]);
                }
                if ($rules) $settings['routing'] = $rules; else unset($settings['routing']);
                $changed[] = 'routing';
            }
            $newName = isset($patch['name']) ? trim($patch['name']) : null;
            $newZone = $patch['timezone'] ?? null;
            if ($newZone !== null) {
                unset($settings['timezone']); // the column is the single source of truth: no stale copy
                $changed[] = 'timezone';
            }
            if ($newName !== null) $changed[] = 'name';
            if (!$changed) return $before;
            $tx->run('UPDATE tenants SET settings = ?, name = COALESCE(?, name), timezone = COALESCE(?, timezone) WHERE id = ?', [Json::encObj($settings), $newName, $newZone, $actor->tenantId]);
            $after = self::toDto($tx->one('SELECT name, slug, timezone, settings, updated_at FROM tenants WHERE id = ?', [$actor->tenantId]));
            $pick = static fn(array $o): array => array_intersect_key($o, array_flip($changed));
            Audit::log($tx, 'workspace.update', 'workspace', $actor->tenantId, $pick($before), $pick($after));
            return $after;
        });
    }

    /**
     * Agency clients: the profile columns of the tenants row. Only whitelisted columns are touched; currency and time zone are kept
     * consistent with the settings blob. Returns the labels of the fields that changed. The caller authorises and audits.
     *
     * @param array<string,mixed> $patch keys: name, industry, website, contactName, contactEmail, contactPhone, notes, timezone, currency (absent = unchanged)
     * @return string[]
     */
    public static function writeClientProfile(Tx $tx, string $tenantId, array $patch): array
    {
        $set = [];
        $changed = [];
        $map = ['name' => 'name', 'industry' => 'industry', 'website' => 'website', 'contactName' => 'contact_name', 'contactEmail' => 'contact_email', 'contactPhone' => 'contact_phone', 'notes' => 'notes', 'timezone' => 'timezone'];
        foreach ($map as $k => $col) {
            if (!array_key_exists($k, $patch)) continue;
            $set[$col] = $patch[$k];
            $changed[] = $k;
        }
        $needSettings = array_key_exists('timezone', $patch) || array_key_exists('currency', $patch);
        if ($needSettings) {
            $settings = Json::dec((string) $tx->val('SELECT settings FROM tenants WHERE id = ?', [$tenantId]), []) ?: [];
            if (array_key_exists('timezone', $patch)) unset($settings['timezone']); // the column is the single source of truth
            if (array_key_exists('currency', $patch)) {
                $cur = strtoupper($patch['currency']);
                $set['default_currency'] = $cur;
                $settings['currency'] = $cur;
                $changed[] = 'currency';
            }
            $set['settings'] = Json::encObj($settings);
        }
        if (!$set) return [];
        $tx->update('tenants', $set, "id = ? AND kind = 'client'", [$tenantId]);
        return $changed;
    }

    /** Archive or restore a client workspace: archived = inactive, so sessions, API keys and lead capture stop resolving it. Nothing is deleted. */
    public static function writeClientArchived(Tx $tx, string $tenantId, bool $archived): void
    {
        $tx->run("UPDATE tenants SET archived_at = CASE WHEN ? = 1 THEN NOW(3) ELSE NULL END, is_active = IF(? = 1, 0, 1) WHERE id = ? AND kind = 'client'", [$archived ? 1 : 0, $archived ? 1 : 0, $tenantId]);
        if ($archived) Sessions::detach($tenantId);
    }
}
