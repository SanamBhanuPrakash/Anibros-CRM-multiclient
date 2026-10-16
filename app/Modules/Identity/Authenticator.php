<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Actor;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Matrix;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tx;

/**
 * Authentication -> request actor.
 *
 *  Browser:  HttpOnly session cookie (+ CSRF double-submit enforced separately for unsafe methods)
 *  Machine:  `Authorization: Bearer crm_live_<prefix>_<secret>` (API key; hashed at rest; scoped; may only narrow its role)
 *
 * Workspace selection is explicit and verified: X-Tenant-Id header (must be an active membership) -> session.active_tenant_id (if still a
 * member) -> first membership. A header can never grant access to a workspace the user is not a member of.
 * Failures are deliberately uniform (401 "Authentication is required"): no hint whether a token existed.
 */
final class Authenticator
{
    public const API_KEY_RE = '/^crm_live_([a-z0-9]{10})_([A-Za-z0-9_-]{43})$/';

    private static function charge(string $name, string $key, float $capacity, float $refill, Response $res): void
    {
        $d = RateLimiter::take($name, $key, $capacity, $refill);
        $res->header('RateLimit-Limit', (string) $d['limit']);
        $res->header('RateLimit-Remaining', (string) $d['remaining']);
        if (!$d['allowed']) throw Errors::rateLimited($d['retryAfter']);
    }

    /** Sets $req->actor (and $req->session for browser sessions). Throws 401/403/429. */
    public static function authenticate(Request $req, Response $res): void
    {
        $auth = $req->header('authorization');
        $bearer = ($auth !== null && str_starts_with($auth, 'Bearer ')) ? trim(substr($auth, 7)) : null;
        $cookie = $req->cookie(Csrf::sessionCookieName($req));

        // ── API key ──────────────────────────────────────────────────────────────────────────────────────────────
        if ($bearer) {
            $ip = $req->ip ?: 'unknown';
            // A wrong key counts against the address it came from; past a handful the answer becomes "slow down" instead of "no".
            $reject = static function () use ($ip): never {
                $d = RateLimiter::take('bad_key', $ip, 20, 0.2);
                if (!$d['allowed']) throw Errors::rateLimited($d['retryAfter']);
                throw Errors::unauthenticated();
            };
            if (!preg_match(self::API_KEY_RE, $bearer, $m)) $reject();
            $k = Db::identity()->one(
                'SELECT k.id, k.tenant_id, k.secret_hash, k.role, k.scopes, k.expires_at, k.revoked_at, t.is_active AS tenant_active
                   FROM api_keys k JOIN tenants t ON t.id = k.tenant_id WHERE k.prefix = ?',
                [$m[1]]
            );
            $presented = Crypto::hashToken($m[2]);
            $stored = $k['secret_hash'] ?? Crypto::hashToken('no-such-key'); // compare even when unknown: timing reveals nothing
            $matches = Crypto::safeEqual($presented, (string) $stored) && $k !== null;
            if (!$matches || $k['revoked_at'] || ($k['expires_at'] && strtotime((string) $k['expires_at']) < time()) || !$k['tenant_active'] || !Matrix::isRole($k['role'])) $reject();
            $allowed = [];
            foreach ((array) Json::dec($k['scopes'], []) as $s) {
                if (in_array($s, Matrix::permissions(), true) && Matrix::scopeOf($k['role'], $s) !== null) $allowed[$s] = true;
            }
            self::charge('api_key', $k['id'], 300, 20, $res);
            $req->actor = new Actor($k['tenant_id'], null, $k['role'], 'api_key', $k['id'], $allowed, $req->id, $req->ip);
            // touch (at most once a minute)
            try {
                Db::identity()->run('UPDATE api_keys SET last_used_at = NOW(3), last_used_ip = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(3), INTERVAL 1 MINUTE))', [$req->ip, $k['id']]);
            } catch (\Throwable) {
            }
            return;
        }

        // ── Session cookie ───────────────────────────────────────────────────────────────────────────────────────
        $session = Sessions::validate($cookie);
        if (!$session) throw Errors::unauthenticated();
        if (!$session->user['emailVerified']) throw Errors::emailNotVerified();
        if (!$session->tenants) throw Errors::unauthenticated();

        $requested = trim((string) ($req->header('x-tenant-id') ?? ''));
        $tenant = null;
        if ($requested !== '') {
            foreach ($session->tenants as $t) if ($t['id'] === strtolower($requested)) $tenant = $t;
            if (!$tenant) throw Errors::forbidden('You are not a member of the requested workspace');
        }
        if (!$tenant) {
            foreach ($session->tenants as $t) if ($t['id'] === $session->activeTenantId) $tenant = $t;
        }
        $tenant ??= $session->tenants[0];

        self::charge('api_user', $session->user['id'], 600, 40, $res);
        $req->actor = new Actor($tenant['id'], $session->user['id'], $tenant['role'], 'user', null, null, $req->id, $req->ip);
        $req->session = $session;
    }

    /** The signed-in user of this request, if any (for public endpoints that must recognise one). */
    public static function currentSession(Request $req): ?\App\Core\Session
    {
        return Sessions::validate($req->cookie(Csrf::sessionCookieName($req)));
    }

    /** @return array{plaintext:string,prefix:string,secretHash:string} */
    public static function generateApiKey(): array
    {
        $prefix = substr(str_pad(preg_replace('/[^a-z0-9]/', '', bin2hex(random_bytes(8))), 10, '0'), 0, 10);
        $secret = \App\Core\Ids::token(32); // 43 chars
        return ['plaintext' => "crm_live_{$prefix}_{$secret}", 'prefix' => $prefix, 'secretHash' => Crypto::hashToken($secret)];
    }
}
