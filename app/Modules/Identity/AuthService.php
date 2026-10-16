<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\AppError;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Ids;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Password;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Time;
use App\Core\Totp;
use App\Core\Tx;
use App\Modules\Leads\Seeds;

/**
 * Sign-up, sign-in, e-mail verification, password reset, MFA. Everything here runs in the identity flow (base tables) because it has to
 * look people up by e-mail or token before any workspace is chosen.
 *
 * Throttling (see docs/SECURITY_MODEL.md of the Node project): per (ip,email) stops guessing one account from one machine; per ip stops
 * credential stuffing across accounts; per email (generous) bounds distributed guessing without letting an attacker LOCK a victim out.
 */
final class AuthService
{
    private const VERIFY_TTL = '+24 hours';
    private const RESET_TTL = '+1 hour';
    private const MFA_CHALLENGE_TTL_MS = 300000;

    private static function take(string $name, string $key, float $perCount, float $windowSec, string $message = 'Too many requests. Please try again later.'): void
    {
        RateLimiter::guard($name, $key, $perCount, $perCount / $windowSec, $message);
    }

    private static function invalidCredentials(): AppError
    {
        return new AppError(401, 'UNAUTHENTICATED', 'Invalid credentials', 'Invalid email or password');
    }

    // ── sign-up: uniform response whether or not the e-mail is registered (no account enumeration) ──────────────────

    /** @param array{email:string,password:string,name:string,organisationName?:?string} $in @param array{ip?:?string,userAgent?:?string,requestId?:?string} $meta */
    public static function signup(array $in, array $meta): void
    {
        self::take('signup_ip', 'ip:' . ($meta['ip'] ?? ''), 10, 3600);
        $email = strtolower(trim($in['email']));
        $problems = Password::problems($in['password'], $email, $in['name']);
        if ($problems) throw Errors::validation(array_map(static fn($m) => ['path' => 'password', 'message' => $m], $problems));

        $hash = Password::hash($in['password']);
        $name = trim($in['name']);
        $orgName = mb_substr(trim((string) ($in['organisationName'] ?? '')) ?: "$name's Agency", 0, 120);
        $slugBase = mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($orgName)), '-'), 0, 40) ?: 'workspace';

        $created = Db::identity()->tx(static function (Tx $tx) use ($email, $hash, $name, $orgName, $slugBase, $meta) {
            $userId = $tx->insertIgnore('users', ['email' => $email, 'password_hash' => $hash, 'name' => $name]);
            if (!$userId) return false;
            $orgId = $tx->insert('organisations', ['name' => $orgName, 'slug' => $slugBase . '-' . Ids::hex(3), 'owner_id' => $userId]);
            // The agency's own workspace (HQ): its staff directory and own sales leads. Client workspaces are added from the agency layer.
            $tenantId = $tx->insert('tenants', ['organisation_id' => $orgId, 'name' => $orgName, 'slug' => 'main', 'kind' => 'agency_hq', 'settings' => '{}']);
            $tx->insert('tenant_memberships', ['tenant_id' => $tenantId, 'user_id' => $userId, 'role' => 'tenant_owner', 'status' => 'active', 'is_active' => 1]);
            self::issueVerification($tx, $userId, $email, $name);
            // From here the transaction acts AS the new owner in the new workspace (seed, audit, views).
            $tx->actAs($tenantId, $userId, $meta['requestId'] ?? null, $meta['ip'] ?? null);
            Seeds::beginnerPipeline($tx, $tenantId);
            Seeds::scoringConfig($tx, $tenantId);
            Audit::log($tx, 'tenant.create', 'tenant', $tenantId, null, ['owner' => $userId, 'organisation' => $orgName]);
            return true;
        });

        if (!$created) {
            // Existing account: tell the OWNER of the mailbox (not the requester) what to do. The response to the caller is identical.
            $u = Db::identity()->one('SELECT id, name, email_verified, is_active FROM users WHERE email = ?', [$email]);
            if ($u && $u['is_active']) {
                Db::identity()->tx(static function (Tx $tx) use ($u, $email) {
                    if ($u['email_verified']) Mailer::enqueue($tx, 'account_exists', $email, ['name' => $u['name']], null, 'exists:' . $u['id'] . ':' . gmdate('Y-m-d'));
                    else self::issueVerification($tx, $u['id'], $email, $u['name']);
                });
            }
        }
    }

    private static function issueVerification(Tx $tx, string $userId, string $email, string $name): void
    {
        $token = Sessions::generateToken(32);
        $tx->insert('email_verification_tokens', ['user_id' => $userId, 'token_hash' => Sessions::hashToken($token), 'expires_at' => Time::plus(self::VERIFY_TTL)]);
        Mailer::enqueue($tx, 'verify_email', $email, ['name' => $name], $token);
    }

    public static function resendVerification(string $emailRaw, array $meta): void
    {
        self::take('reset_ip', 'ip:' . ($meta['ip'] ?? ''), 10, 3600);
        $email = strtolower(trim($emailRaw));
        $d = RateLimiter::take('reset_email', 'resend:' . $email, 3, 3 / 3600);
        if (!$d['allowed']) return; // silently ignore: the response must not differ
        $u = Db::identity()->one('SELECT id, name, email_verified, is_active FROM users WHERE email = ?', [$email]);
        if ($u && $u['is_active'] && !$u['email_verified']) Db::identity()->tx(static fn(Tx $tx) => self::issueVerification($tx, $u['id'], $email, $u['name']));
    }

    /** Atomic, single-use consumption: concurrent replays of the same link yield exactly one success. */
    public static function verifyEmail(string $token, array $meta): array
    {
        $userId = Db::identity()->tx(static function (Tx $tx) use ($token) {
            $hash = Sessions::hashToken($token);
            $n = $tx->run('UPDATE email_verification_tokens SET used_at = NOW(3) WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW(3)', [$hash]);
            if (!$n) return null;
            $uid = $tx->val('SELECT user_id FROM email_verification_tokens WHERE token_hash = ?', [$hash]);
            $tx->run('UPDATE users SET email_verified = 1 WHERE id = ?', [$uid]);
            $tx->run('UPDATE email_verification_tokens SET used_at = NOW(3) WHERE user_id = ? AND used_at IS NULL', [$uid]);
            return $uid;
        });
        if (!$userId) throw Errors::badRequest('This verification link is invalid or has expired');
        return self::startSession($userId, $meta, 1);
    }

    // ── login ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{kind:string,token?:string,expiresAt?:\DateTimeImmutable,session?:Session,challenge?:string} */
    public static function login(array $in, array $meta): array
    {
        $email = strtolower(trim($in['email']));
        $ip = $meta['ip'] ?? '';
        self::take('login_ip', 'ip:' . $ip, (float) Config::get('login_ip_limit', 600), 900, 'Too many sign-in attempts. Try again later.');
        self::take('login_ip_email', $ip . ':' . $email, 5, 900, 'Too many sign-in attempts for this account. Try again later.');
        self::take('login_email', 'email:' . $email, 50, 3600, 'Too many sign-in attempts for this account. Try again later.');

        $user = Db::identity()->one('SELECT id, password_hash, email_verified, is_active FROM users WHERE email = ?', [$email]);
        // Always do one hash verification so response time is independent of account existence.
        $ok = $user ? Password::verify($in['password'], $user['password_hash']) : Password::verifyAgainstDummy($in['password']);
        if (!$user || !$ok || !$user['is_active']) {
            self::recordAttempt($email, $ip, false);
            throw self::invalidCredentials();
        }
        self::recordAttempt($email, $ip, true);
        if (!$user['email_verified']) throw Errors::emailNotVerified();
        if (Password::needsRehash($user['password_hash'])) {
            try {
                Db::identity()->run('UPDATE users SET password_hash = ? WHERE id = ?', [Password::hash($in['password']), $user['id']]);
            } catch (\Throwable) {
            }
        }
        $mfa = Db::identity()->one('SELECT 1 AS x FROM mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL', [$user['id']]);
        if ($mfa) return ['kind' => 'mfa_required', 'challenge' => self::signChallenge($user['id'])];
        return self::startSession($user['id'], $meta, 1);
    }

    public static function completeMfa(array $in, array $meta): array
    {
        $userId = self::verifyChallenge($in['challenge']);
        if (!$userId) throw Errors::unauthenticated('The sign-in session expired; start again');
        self::take('mfa', 'user:' . $userId, 6, 300, 'Too many verification attempts. Try again in a few minutes.');
        $ok = false;
        if (!empty($in['code'])) $ok = self::checkTotp($userId, $in['code']);
        elseif (!empty($in['recoveryCode'])) $ok = self::useRecoveryCode($userId, $in['recoveryCode']);
        if (!$ok) throw new AppError(401, 'UNAUTHENTICATED', 'Invalid code', 'That code is not valid');
        return self::startSession($userId, $meta, 2);
    }

    /** @return array{kind:string,token:string,expiresAt:\DateTimeImmutable,session:Session} */
    private static function startSession(string $userId, array $meta, int $authLevel): array
    {
        $m = Db::identity()->one(
            "SELECT tm.tenant_id FROM tenant_memberships tm JOIN tenants t ON t.id = tm.tenant_id
              WHERE tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND t.is_active = 1 ORDER BY tm.created_at, tm.id LIMIT 1",
            [$userId]
        );
        if (!$m) throw Errors::forbidden('This account has no active workspace. Ask an administrator for access.');
        $created = Sessions::create($userId, $meta['ip'] ?? null, $meta['userAgent'] ?? null, $m['tenant_id'], $authLevel);
        $session = Sessions::validate($created['token']);
        if (!$session) throw Errors::internal();
        return ['kind' => 'session', 'token' => $created['token'], 'expiresAt' => $created['expiresAt'], 'session' => $session];
    }

    public static function logout(?string $token): void
    {
        if ($token) Sessions::revokeByToken($token);
    }

    private static function recordAttempt(string $email, ?string $ip, bool $success): void
    {
        try {
            Db::identity()->tx(static fn(Tx $tx) => $tx->insert('login_attempts', ['email' => $email, 'ip_address' => $ip, 'success' => $success ? 1 : 0]), ['retries' => 0]);
        } catch (\Throwable) {
        }
    }

    // ── password reset / change ───────────────────────────────────────────────────────────────────────────────────

    public static function requestPasswordReset(string $emailRaw, array $meta): void
    {
        self::take('reset_ip', 'ip:' . ($meta['ip'] ?? ''), 10, 3600);
        $email = strtolower(trim($emailRaw));
        $d = RateLimiter::take('reset_email', 'reset:' . $email, 3, 3 / 3600);
        if (!$d['allowed']) return; // uniform response
        $u = Db::identity()->one('SELECT id, name, is_active FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['is_active']) return;
        $token = Sessions::generateToken(32);
        Db::identity()->tx(static function (Tx $tx) use ($u, $token, $email) {
            $tx->insert('password_reset_tokens', ['user_id' => $u['id'], 'token_hash' => Sessions::hashToken($token), 'expires_at' => Time::plus(self::RESET_TTL)]);
            Mailer::enqueue($tx, 'password_reset', $email, ['name' => $u['name']], $token);
        });
    }

    public static function completePasswordReset(string $token, string $newPassword, array $meta): void
    {
        $hash = Sessions::hashToken($token);
        $peek = Db::identity()->one('SELECT u.email, u.name FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW(3)', [$hash]);
        if (!$peek) throw Errors::badRequest('This reset link is invalid or has expired');
        $problems = Password::problems($newPassword, $peek['email'], $peek['name']);
        if ($problems) throw Errors::validation(array_map(static fn($m) => ['path' => 'password', 'message' => $m], $problems));
        $newHash = Password::hash($newPassword);
        $ok = Db::identity()->tx(static function (Tx $tx) use ($hash, $newHash) {
            // Atomic consume: of N concurrent submissions of the same link, exactly one passes this statement.
            $n = $tx->run('UPDATE password_reset_tokens SET used_at = NOW(3) WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW(3)', [$hash]);
            if (!$n) return false;
            $userId = $tx->val('SELECT user_id FROM password_reset_tokens WHERE token_hash = ?', [$hash]);
            $tx->run('UPDATE users SET password_hash = ?, password_changed_at = NOW(3) WHERE id = ?', [$newHash, $userId]);
            $u = $tx->one('SELECT email, name FROM users WHERE id = ?', [$userId]);
            $tx->run('UPDATE sessions SET revoked_at = NOW(3) WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
            $tx->run('UPDATE password_reset_tokens SET used_at = NOW(3) WHERE user_id = ? AND used_at IS NULL', [$userId]);
            Mailer::enqueue($tx, 'password_changed', $u['email'], ['name' => $u['name']]);
            return true;
        });
        if (!$ok) throw Errors::badRequest('This reset link is invalid or has expired');
    }

    public static function changePassword(string $userId, string $currentSessionId, string $current, string $next): void
    {
        $u = Db::identity()->one('SELECT password_hash, email, name FROM users WHERE id = ?', [$userId]);
        if (!$u || !Password::verify($current, $u['password_hash'])) throw Errors::forbidden('Your current password is incorrect');
        $problems = Password::problems($next, $u['email'], $u['name']);
        if ($problems) throw Errors::validation(array_map(static fn($m) => ['path' => 'next', 'message' => $m], $problems));
        $hash = Password::hash($next);
        Db::identity()->tx(static function (Tx $tx) use ($userId, $currentSessionId, $hash, $u) {
            $tx->run('UPDATE users SET password_hash = ?, password_changed_at = NOW(3) WHERE id = ?', [$hash, $userId]);
            $tx->run('UPDATE sessions SET revoked_at = NOW(3) WHERE user_id = ? AND revoked_at IS NULL AND id <> ?', [$userId, $currentSessionId]);
            Mailer::enqueue($tx, 'password_changed', $u['email'], ['name' => $u['name']]);
        });
    }

    // ── MFA (TOTP) ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{secret:string,otpauthUri:string} */
    public static function mfaBeginSetup(string $userId, string $email): array
    {
        if (Db::identity()->one('SELECT 1 AS x FROM mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId])) throw Errors::conflict('Two-factor authentication is already enabled');
        $secret = Totp::generateSecret();
        Db::identity()->tx(static function (Tx $tx) use ($userId, $secret) {
            $tx->run('DELETE FROM mfa_factors WHERE user_id = ? AND confirmed_at IS NULL', [$userId]);
            $tx->insert('mfa_factors', ['user_id' => $userId, 'secret_enc' => Crypto::encrypt($secret, 'mfa:' . $userId)]);
        });
        return ['secret' => $secret, 'otpauthUri' => Totp::otpauthUri($email, $secret)];
    }

    /** @return array{recoveryCodes:string[]} */
    public static function mfaConfirmSetup(string $userId, string $code): array
    {
        return Db::identity()->tx(static function (Tx $tx) use ($userId, $code) {
            $f = $tx->one('SELECT id, secret_enc FROM mfa_factors WHERE user_id = ? AND confirmed_at IS NULL FOR UPDATE', [$userId]);
            if (!$f) throw Errors::badRequest('Start two-factor setup first');
            $step = Totp::verify(Crypto::decrypt($f['secret_enc'], 'mfa:' . $userId), $code);
            if ($step === null) throw Errors::badRequest('That code is not valid. Check your authenticator app and try again.');
            $tx->run('UPDATE mfa_factors SET confirmed_at = NOW(3), last_used_step = ? WHERE id = ?', [$step, $f['id']]);
            return ['recoveryCodes' => self::replaceRecoveryCodes($tx, $userId)];
        });
    }

    public static function mfaDisable(string $userId, string $password, string $code): void
    {
        $u = Db::identity()->one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$u || !Password::verify($password, $u['password_hash'])) throw Errors::forbidden('Your password is incorrect');
        if (!self::checkTotp($userId, $code) && !self::useRecoveryCode($userId, $code)) throw Errors::forbidden('That code is not valid');
        Db::identity()->tx(static function (Tx $tx) use ($userId) {
            $tx->run('DELETE FROM mfa_factors WHERE user_id = ?', [$userId]);
            $tx->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        });
    }

    /** @return string[] */
    public static function mfaRegenerateRecoveryCodes(string $userId, string $code): array
    {
        if (!self::checkTotp($userId, $code)) throw Errors::forbidden('That code is not valid');
        return Db::identity()->tx(static fn(Tx $tx) => self::replaceRecoveryCodes($tx, $userId));
    }

    /** @return string[] */
    private static function replaceRecoveryCodes(Tx $tx, string $userId): array
    {
        $codes = Totp::recoveryCodes(10);
        $tx->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
        foreach ($codes as $c) $tx->insert('mfa_recovery_codes', ['user_id' => $userId, 'code_hash' => Sessions::hashToken(Totp::normalizeRecoveryCode($c))]);
        return $codes;
    }

    /** Verifies a TOTP code with replay protection (a code can be used once per 30 s step). */
    private static function checkTotp(string $userId, string $code): bool
    {
        return Db::identity()->tx(static function (Tx $tx) use ($userId, $code) {
            $f = $tx->one('SELECT id, secret_enc, last_used_step FROM mfa_factors WHERE user_id = ? AND confirmed_at IS NOT NULL FOR UPDATE', [$userId]);
            if (!$f) return false;
            $step = Totp::verify(Crypto::decrypt($f['secret_enc'], 'mfa:' . $userId), $code, $f['last_used_step'] === null ? null : (int) $f['last_used_step']);
            if ($step === null) return false;
            $tx->run('UPDATE mfa_factors SET last_used_step = ? WHERE id = ?', [$step, $f['id']]);
            return true;
        });
    }

    private static function useRecoveryCode(string $userId, string $code): bool
    {
        return Db::identity()->run('UPDATE mfa_recovery_codes SET used_at = NOW(3) WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, Sessions::hashToken(Totp::normalizeRecoveryCode($code))]) > 0;
    }

    // ── stateless, short-lived MFA challenge (binds "the password was correct" to the second step) ───────────────

    private static function signChallenge(string $userId): string
    {
        $body = Crypto::b64(Json::enc(['u' => $userId, 'e' => Time::nowMs() + self::MFA_CHALLENGE_TTL_MS, 'n' => Ids::hex(8)]));
        return $body . '.' . Crypto::hmac('mfa-challenge', $body);
    }

    private static function verifyChallenge(string $challenge): ?string
    {
        $parts = explode('.', $challenge);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || !Crypto::safeEqual($parts[1], Crypto::hmac('mfa-challenge', $parts[0]))) return null;
        $p = Json::dec(Crypto::unb64($parts[0]), null);
        return is_array($p) && isset($p['u'], $p['e']) && (int) $p['e'] > Time::nowMs() ? (string) $p['u'] : null;
    }
}
