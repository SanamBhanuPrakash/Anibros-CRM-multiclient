<?php
declare(strict_types=1);

namespace App\Modules\ApiKeys;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Map;
use App\Core\Matrix;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Time;
use App\Core\Tx;
use App\Core\V;
use App\Modules\Identity\Authenticator;

/**
 * API keys for machine-to-machine access (public REST API, integrations, Zapier / Make, website forms).
 *  - The plaintext (`crm_live_<prefix>_<secret>`) is returned ONCE at creation; only SHA-256(secret) is stored.
 *  - A key has a ceiling role and an explicit scope list. Scopes are a subset of the role's permissions, which are a subset of the
 *    creator's own: nobody can mint a key more powerful than themselves.
 *  - Keys are revoked, never deleted (audit trail). Optional expiry. Last-used time/IP are tracked.
 */
final class ApiKeys
{
    private const MAX_ACTIVE_KEYS = 25;

    /** Permissions that make no sense for machines (interactive / identity-bound). */
    private const NOT_FOR_KEYS = ['api_keys:manage', 'members:invite', 'members:update', 'members:remove', 'privacy:manage', 'notifications:read', 'saved_views:manage'];

    /** Ready-made keys for the two things people actually do. */
    public const TEMPLATES = [
        [
            'id' => 'send-leads-in', 'direction' => 'in', 'title' => 'Send leads into the CRM',
            'description' => 'For a website form, Zapier, Make or your own system. It can add leads and contacts and nothing else: it cannot read or change anything you already have.',
            'role' => 'sales_agent', 'scopes' => ['leads:create', 'contacts:create'], 'expiresInDays' => 365, 'suggestedName' => 'Lead intake',
        ],
        [
            'id' => 'read-data-out', 'direction' => 'out', 'title' => 'Read data out of the CRM',
            'description' => 'For a dashboard, a spreadsheet or a reporting tool. Read-only: leads, contacts, activity and reports. It can never change or delete anything.',
            'role' => 'marketing_analyst', 'scopes' => ['leads:read', 'contacts:read', 'activities:read', 'dashboard:read', 'reports:read', 'attribution:read'], 'expiresInDays' => 365, 'suggestedName' => 'Reporting (read-only)',
        ],
    ];

    /** @return string[] */
    public static function availableScopes(Actor $actor): array
    {
        return array_values(array_filter(Matrix::permissions(), static fn($p) => Matrix::can($actor->role, $p) && !in_array($p, self::NOT_FOR_KEYS, true)));
    }

    /** The templates this person may actually create. */
    public static function templates(Actor $actor): array
    {
        $grantable = array_flip(self::availableScopes($actor));
        $assignable = Matrix::assignableRoles($actor->role);
        return array_values(array_filter(self::TEMPLATES, static function ($t) use ($grantable, $assignable) {
            if (!in_array($t['role'], $assignable, true)) return false;
            foreach ($t['scopes'] as $s) if (!isset($grantable[$s]) || Matrix::scopeOf($t['role'], $s) === null) return false;
            return true;
        }));
    }

    public static function list(Actor $actor): array
    {
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor) {
            $rows = $tx->all(
                'SELECT id, name, prefix, role, scopes, expires_at AS expiresAt, last_used_at AS lastUsedAt, last_used_ip AS lastUsedIp,
                        revoked_at AS revokedAt, created_at AS createdAt, created_by AS createdBy
                   FROM api_keys WHERE tenant_id = ? ORDER BY created_at DESC, id',
                [$actor->tenantId]
            );
            return Map::rows($rows, ['json' => ['scopes' => []]]);
        });
    }

    /** @param array{name:string,role:string,scopes:string[],expiresInDays?:int} $in */
    public static function create(Actor $actor, array $in): array
    {
        if (!in_array($in['role'], Matrix::assignableRoles($actor->role), true)) throw Errors::forbidden("Your role cannot create a key with role \"{$in['role']}\"");
        $grantable = array_flip(self::availableScopes($actor));
        $bad = array_values(array_filter($in['scopes'], static fn($s) => !isset($grantable[$s]) || Matrix::scopeOf($in['role'], $s) === null));
        if ($bad) throw Errors::validation(array_map(static fn($s) => ['path' => 'scopes', 'message' => "\"$s\" cannot be granted to this key"], $bad));
        if (!$in['scopes']) throw Errors::validation([['path' => 'scopes', 'message' => 'Select at least one scope']]);
        $key = Authenticator::generateApiKey();
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in, $key) {
            $active = (int) $tx->val('SELECT COUNT(*) FROM api_keys WHERE tenant_id = ? AND revoked_at IS NULL', [$actor->tenantId]);
            if ($active >= self::MAX_ACTIVE_KEYS) throw Errors::conflict('A workspace can have at most ' . self::MAX_ACTIVE_KEYS . ' active API keys');
            $expires = !empty($in['expiresInDays']) ? Time::plus('+' . (int) $in['expiresInDays'] . ' days') : null;
            $id = $tx->insert('api_keys', [
                'name' => $in['name'], 'prefix' => $key['prefix'], 'secret_hash' => $key['secretHash'], 'role' => $in['role'],
                'scopes' => Json::enc(array_values($in['scopes'])), 'created_by' => $actor->userId, 'expires_at' => $expires,
            ]);
            $row = $tx->one('SELECT id, name, prefix, role, scopes, expires_at AS expiresAt, created_at AS createdAt FROM api_keys WHERE id = ?', [$id]);
            Audit::log($tx, 'api_key.create', 'api_key', $id, null, ['name' => $in['name'], 'role' => $in['role'], 'scopes' => $in['scopes'], 'prefix' => $key['prefix']]);
            return Map::row($row, ['json' => ['scopes' => []]]) + ['key' => $key['plaintext']];
        });
    }

    public static function revoke(Actor $actor, string $id): void
    {
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $r = $tx->one('SELECT name, prefix FROM api_keys WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$r) throw Errors::notFound('API key');
            $tx->run('UPDATE api_keys SET revoked_at = NOW(3) WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'api_key.revoke', 'api_key', $id, $r);
        });
    }

    public static function register(): void
    {
        Router::mount('/api/v1/api-keys', [
            ['method' => 'GET', 'path' => '/scopes', 'perm' => 'api_keys:manage', 'handler' => fn(Ctx $c) => ['scopes' => self::availableScopes($c->actor), 'roles' => Matrix::assignableRoles($c->actor->role)]],
            ['method' => 'GET', 'path' => '/templates', 'perm' => 'api_keys:manage', 'handler' => fn(Ctx $c) => self::templates($c->actor)],
            ['method' => 'GET', 'path' => '/', 'perm' => 'api_keys:manage', 'handler' => fn(Ctx $c) => self::list($c->actor)],
            ['method' => 'POST', 'path' => '/', 'perm' => 'api_keys:manage', 'idempotent' => true,
                'body' => V::obj([
                    'name' => V::str()->trim()->min(1)->max(100),
                    'role' => V::enum(Matrix::ROLES)->default('sales_agent'),
                    'scopes' => V::arr(V::enum(Matrix::permissions()))->min(1)->max(60),
                    'expiresInDays' => V::int()->min(1)->max(730)->optional(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(self::create($c->actor, $c->body), 201)],
            ['method' => 'DELETE', 'path' => '/{id}', 'perm' => 'api_keys:manage', 'params' => V::obj(['id' => V::uuid()]), 'status' => 204,
                'handler' => function (Ctx $c) { self::revoke($c->actor, $c->params['id']); return new Reply(null, 204); }],
        ]);
    }
}
