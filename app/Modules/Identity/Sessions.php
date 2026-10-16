<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Ids;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Time;
use App\Core\Tx;

/**
 * Server-side sessions (identity flow: base tables, see PROJECT_LOG 1.1).
 *  - Token: 256-bit random, only its SHA-256 is stored (a DB leak yields nothing replayable).
 *  - Two clocks: absolute expiry (created + 14 d) and sliding idle expiry (last_seen + 12 h).
 *  - Active workspace is stored on the session (server side), not guessed from membership ordering.
 *  - `last_seen_at` writes are throttled to <= 1/min per session.
 */
final class Sessions
{
    public static function generateToken(int $bytes = 32): string
    {
        return Ids::token($bytes);
    }

    public static function hashToken(string $t): string
    {
        return Crypto::hashToken($t);
    }

    /** Friendly device label from a User-Agent (display only, no fingerprinting). */
    public static function deviceLabel(?string $ua): string
    {
        if (!$ua) return 'Unknown device';
        $browser = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'Chrome/') ? 'Chrome' : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : 'Browser')));
        $os = str_contains($ua, 'Windows') ? 'Windows' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'macOS' : (str_contains($ua, 'Android') ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (str_contains($ua, 'Linux') ? 'Linux' : 'Unknown OS'))));
        return "$browser on $os";
    }

    public static function idleSeconds(): int
    {
        return (int) (((float) Config::get('session_idle_hours', 12)) * 3600);
    }

    /** @return array{token:string,expiresAt:\DateTimeImmutable,sessionId:string} */
    public static function create(string $userId, ?string $ip, ?string $userAgent, ?string $activeTenantId = null, int $authLevel = 1): array
    {
        $token = self::generateToken(32);
        $expires = Time::now()->modify('+' . (int) Config::get('session_absolute_days', 14) . ' days');
        $id = Db::identity()->tx(static fn(Tx $tx) => $tx->insert('sessions', [
            'user_id' => $userId,
            'token_hash' => self::hashToken($token),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 400) : null,
            'device_label' => self::deviceLabel($userAgent),
            'expires_at' => $expires,
            'active_tenant_id' => $activeTenantId,
            'auth_level' => $authLevel,
            'mfa_verified_at' => $authLevel === 2 ? new \App\Core\Raw('NOW(3)') : null,
        ]));
        return ['token' => $token, 'expiresAt' => $expires, 'sessionId' => $id];
    }

    public static function validate(?string $token): ?Session
    {
        if (!$token || strlen($token) < 20 || strlen($token) > 200) return null;
        return Db::identity()->tx(static function (Tx $tx) use ($token) {
            $r = $tx->one(
                "SELECT s.id AS session_id, s.auth_level, s.active_tenant_id, s.created_at AS session_created, s.last_seen_at,
                        u.id AS user_id, u.email, u.name, u.phone, u.avatar_url, u.email_verified, u.timezone, u.locale,
                        EXISTS (SELECT 1 FROM mfa_factors f WHERE f.user_id = u.id AND f.confirmed_at IS NOT NULL) AS mfa_enabled
                   FROM sessions s JOIN users u ON u.id = s.user_id
                  WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW(3)
                    AND s.last_seen_at > DATE_SUB(NOW(3), INTERVAL ? SECOND) AND u.is_active = 1",
                [self::hashToken($token), self::idleSeconds()]
            );
            if (!$r) return null;
            $tenants = $tx->all(
                "SELECT t.id, t.name, t.slug, tm.role, t.timezone, t.default_currency AS currency, t.kind, t.industry, tm.work_channels
                   FROM tenant_memberships tm JOIN tenants t ON t.id = tm.tenant_id
                  WHERE tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND t.is_active = 1 ORDER BY tm.created_at, t.id",
                [$r['user_id']]
            );
            // Sliding idle window: refresh at most once a minute.
            if ((Time::nowMs() - (int) Time::ms($r['last_seen_at'])) > 60000) {
                $tx->run("UPDATE sessions SET last_seen_at = NOW(3) WHERE id = ? AND last_seen_at < DATE_SUB(NOW(3), INTERVAL 60 SECOND)", [$r['session_id']]);
            }
            return new Session(
                $r['session_id'],
                (int) $r['auth_level'],
                $r['active_tenant_id'],
                $r['session_created'],
                [
                    'id' => $r['user_id'], 'email' => $r['email'], 'name' => $r['name'], 'phone' => $r['phone'], 'avatarUrl' => $r['avatar_url'],
                    'emailVerified' => (bool) $r['email_verified'], 'timezone' => $r['timezone'], 'locale' => $r['locale'], 'mfaEnabled' => (bool) $r['mfa_enabled'],
                ],
                array_map(static function (array $t): array {
                    $wc = $t['work_channels'] !== null ? Json::dec($t['work_channels'], null) : null;
                    return [
                        'id' => $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'role' => $t['role'], 'timezone' => $t['timezone'], 'currency' => $t['currency'],
                        'kind' => $t['kind'], 'industry' => $t['industry'], 'workChannels' => is_array($wc) ? array_values($wc) : null,
                    ];
                }, $tenants)
            );
        }, ['retries' => 0]);
    }

    public static function revokeByToken(string $token): void
    {
        Db::identity()->run('UPDATE sessions SET revoked_at = NOW(3) WHERE token_hash = ? AND revoked_at IS NULL', [self::hashToken($token)]);
    }

    /** Revoke one of the user's own sessions (the id must belong to the user). */
    public static function revokeOwn(string $userId, string $sessionId): bool
    {
        return Db::identity()->run('UPDATE sessions SET revoked_at = NOW(3) WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$sessionId, $userId]) > 0;
    }

    public static function revokeAll(string $userId, ?string $exceptSessionId = null): int
    {
        return Db::identity()->run('UPDATE sessions SET revoked_at = NOW(3) WHERE user_id = ? AND revoked_at IS NULL AND (? IS NULL OR id <> ?)', [$userId, $exceptSessionId, $exceptSessionId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function list(string $userId, string $currentSessionId): array
    {
        $rows = Db::identity()->query(
            'SELECT id, device_label, ip_address, created_at, last_seen_at FROM sessions
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW(3) AND last_seen_at > DATE_SUB(NOW(3), INTERVAL ? SECOND)
              ORDER BY last_seen_at DESC LIMIT 50',
            [$userId, self::idleSeconds()]
        );
        return array_map(static fn($r) => ['id' => $r['id'], 'deviceLabel' => $r['device_label'], 'ip' => $r['ip_address'], 'createdAt' => $r['created_at'], 'lastSeenAt' => $r['last_seen_at'], 'current' => $r['id'] === $currentSessionId], $rows);
    }

    public static function setActiveTenant(string $sessionId, string $tenantId): void
    {
        Db::identity()->run('UPDATE sessions SET active_tenant_id = ? WHERE id = ?', [$tenantId, $sessionId]);
    }

    /** Point sessions away from a workspace (someone was removed, or the workspace was archived). Optionally for one user only. */
    public static function detach(string $tenantId, ?string $userId = null): int
    {
        return Db::identity()->run('UPDATE sessions SET active_tenant_id = NULL WHERE active_tenant_id = ? AND (? IS NULL OR user_id = ?)', [$tenantId, $userId, $userId]);
    }

    // ── cookies ───────────────────────────────────────────────────────────────────────────────────────────────────

    public static function setCookie(Request $req, Response $res, string $token, \DateTimeInterface $expiresAt): void
    {
        $res->cookie(Csrf::sessionCookieName($req), $token, ['httponly' => true, 'secure' => Csrf::secure($req), 'samesite' => 'Lax', 'path' => '/', 'expires' => (int) $expiresAt->format('U')]);
    }

    public static function clearCookie(Request $req, Response $res): void
    {
        $res->clearCookie(Csrf::sessionCookieName($req), ['httponly' => true, 'secure' => Csrf::secure($req), 'samesite' => 'Lax']);
    }
}
