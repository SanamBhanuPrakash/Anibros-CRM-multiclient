<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Csrf;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Matrix;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Session;
use App\Core\Tx;
use App\Core\V;

/** /api/v1/auth/* (public) and /api/v1/me/* (signed-in person). */
final class IdentityRoutes
{
    /** @return array<string,mixed> */
    public static function describeSession(Session $s, string $activeTenantId, string $role): array
    {
        return [
            'user' => [
                'id' => $s->user['id'], 'email' => $s->user['email'], 'name' => $s->user['name'], 'phone' => $s->user['phone'],
                'avatarUrl' => $s->user['avatarUrl'], 'timezone' => $s->user['timezone'], 'locale' => $s->user['locale'], 'mfaEnabled' => $s->user['mfaEnabled'],
            ],
            'tenants' => $s->tenants,
            'activeTenantId' => $activeTenantId,
            'capabilities' => Json::obj(Matrix::capabilitiesFor($role)),
            'sessionId' => $s->sessionId,
        ];
    }

    private static function meta(Ctx $c): array
    {
        return ['ip' => $c->req->ip, 'userAgent' => $c->req->userAgent(), 'requestId' => $c->req->id];
    }

    /** @param array{token:string,expiresAt:\DateTimeImmutable,session:Session} $r */
    private static function authenticated(Ctx $c, array $r): array
    {
        Sessions::setCookie($c->req, $c->res, $r['token'], $r['expiresAt']);
        $csrf = Csrf::issue($c->req, $c->res);
        $s = $r['session'];
        $active = null;
        foreach ($s->tenants as $t) if ($t['id'] === $s->activeTenantId) $active = $t;
        $active ??= $s->tenants[0];
        return ['status' => 'authenticated', 'csrfToken' => $csrf] + self::describeSession($s, $active['id'], $active['role']);
    }

    private static function validTimezone(string $tz): bool
    {
        return $tz === 'UTC' || in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    public static function register(): void
    {
        $email = V::str()->trim()->lower()->email()->max(254);
        $pw = V::str()->min(1)->max(128);
        $token = V::str()->min(20)->max(200);

        Router::mount('/api/v1/auth', [
            ['method' => 'GET', 'path' => '/csrf', 'auth' => 'public', 'handler' => fn(Ctx $c) => ['csrfToken' => Csrf::issue($c->req, $c->res)]],
            ['method' => 'POST', 'path' => '/signup', 'auth' => 'public', 'status' => 202,
                'body' => V::obj(['email' => $email, 'password' => $pw, 'name' => V::str()->trim()->min(2)->max(100), 'organisationName' => V::str()->trim()->min(2)->max(120)->optional()]),
                'handler' => function (Ctx $c) { AuthService::signup($c->body, self::meta($c)); return new Reply(['status' => 'verification_sent'], 202); }],
            ['method' => 'POST', 'path' => '/login', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['email' => $email, 'password' => $pw]),
                'handler' => function (Ctx $c) {
                    $r = AuthService::login($c->body, self::meta($c));
                    if ($r['kind'] === 'mfa_required') return new Reply(['status' => 'mfa_required', 'challenge' => $r['challenge']], 200);
                    return new Reply(self::authenticated($c, $r), 200);
                }],
            ['method' => 'POST', 'path' => '/login/mfa', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['challenge' => V::str()->min(20)->max(400), 'code' => V::str()->regex('/^\d{6}$/')->optional(), 'recoveryCode' => V::str()->min(8)->max(32)->optional()])
                    ->refine(static fn($b) => (isset($b['code']) !== isset($b['recoveryCode'])), 'Provide either code or recoveryCode'),
                'handler' => fn(Ctx $c) => new Reply(self::authenticated($c, AuthService::completeMfa($c->body, self::meta($c))), 200)],
            ['method' => 'POST', 'path' => '/logout', 'auth' => 'public', 'status' => 204,
                'handler' => function (Ctx $c) {
                    AuthService::logout($c->req->cookie(Csrf::sessionCookieName($c->req)));
                    Sessions::clearCookie($c->req, $c->res);
                    return new Reply(null, 204);
                }],
            ['method' => 'POST', 'path' => '/verify-email', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['token' => $token]),
                'handler' => fn(Ctx $c) => new Reply(self::authenticated($c, AuthService::verifyEmail($c->body['token'], self::meta($c))), 200)],
            ['method' => 'POST', 'path' => '/resend-verification', 'auth' => 'public', 'status' => 202,
                'body' => V::obj(['email' => $email]),
                'handler' => function (Ctx $c) { AuthService::resendVerification($c->body['email'], self::meta($c)); return new Reply(['status' => 'accepted'], 202); }],
            ['method' => 'POST', 'path' => '/password-reset/request', 'auth' => 'public', 'status' => 202,
                'body' => V::obj(['email' => $email]),
                'handler' => function (Ctx $c) { AuthService::requestPasswordReset($c->body['email'], self::meta($c)); return new Reply(['status' => 'accepted'], 202); }],
            ['method' => 'POST', 'path' => '/password-reset/complete', 'auth' => 'public', 'status' => 200,
                'body' => V::obj(['token' => $token, 'password' => $pw]),
                'handler' => function (Ctx $c) { AuthService::completePasswordReset($c->body['token'], $c->body['password'], self::meta($c)); return new Reply(['status' => 'password_updated'], 200); }],
        ]);

        Router::mount('/api/v1/me', [
            ['method' => 'GET', 'path' => '/', 'handler' => function (Ctx $c) {
                if (!$c->session) throw Errors::badRequest('This endpoint requires a user session, not an API key');
                return self::describeSession($c->session, $c->actor->tenantId, $c->actor->role);
            }],
            ['method' => 'PATCH', 'path' => '/',
                'body' => V::obj([
                    'name' => V::str()->trim()->min(2)->max(100)->optional(),
                    'phone' => V::str()->trim()->max(30)->nullable()->optional(),
                    'timezone' => V::str()->refine(static fn($tz) => self::validTimezone($tz), 'Unknown IANA time zone')->optional(),
                    'locale' => V::str()->regex('/^[a-z]{2}(-[A-Z]{2})?$/')->optional(),
                ])->refine(static fn($b) => count($b) > 0, 'Nothing to update'),
                'handler' => function (Ctx $c) {
                    if (!$c->actor->userId) throw Errors::forbidden();
                    $b = $c->body;
                    return Db::asUser($c->actor->userId)->tx(function (Tx $tx) use ($b, $c) {
                        $set = [];
                        foreach (['name', 'timezone', 'locale'] as $k) if (isset($b[$k])) $set[$k] = $b[$k];
                        if (array_key_exists('phone', $b)) $set['phone'] = $b['phone'];
                        if ($set) $tx->update('users', $set, 'id = ?', [$c->actor->userId]);
                        return $tx->one('SELECT id, email, name, phone, timezone, locale FROM users WHERE id = ?', [$c->actor->userId]);
                    });
                }],
            ['method' => 'POST', 'path' => '/password', 'status' => 204,
                'body' => V::obj(['currentPassword' => $pw, 'newPassword' => $pw]),
                'handler' => function (Ctx $c) {
                    AuthService::changePassword($c->actor->userId, $c->session->sessionId, $c->body['currentPassword'], $c->body['newPassword']);
                    return new Reply(null, 204);
                }],
            ['method' => 'POST', 'path' => '/switch-tenant', 'status' => 200,
                'body' => V::obj(['tenantId' => V::uuid()]),
                'handler' => function (Ctx $c) {
                    $s = $c->session;
                    $target = null;
                    foreach ($s->tenants as $t) if ($t['id'] === $c->body['tenantId']) $target = $t;
                    if (!$target) throw Errors::notFound('Workspace');
                    Sessions::setActiveTenant($s->sessionId, $target['id']);
                    return new Reply(self::describeSession($s, $target['id'], $target['role']), 200);
                }],
            ['method' => 'GET', 'path' => '/sessions', 'handler' => fn(Ctx $c) => Sessions::list($c->actor->userId, $c->session->sessionId)],
            ['method' => 'DELETE', 'path' => '/sessions/{id}', 'status' => 204, 'params' => V::obj(['id' => V::uuid()]),
                'handler' => function (Ctx $c) {
                    if (!Sessions::revokeOwn($c->actor->userId, $c->params['id'])) throw Errors::notFound('Session');
                    return new Reply(null, 204);
                }],
            ['method' => 'DELETE', 'path' => '/sessions', 'handler' => fn(Ctx $c) => ['revoked' => Sessions::revokeAll($c->actor->userId, $c->session->sessionId)]],
            ['method' => 'POST', 'path' => '/mfa/setup', 'status' => 200, 'handler' => fn(Ctx $c) => new Reply(AuthService::mfaBeginSetup($c->actor->userId, $c->session->user['email']), 200)],
            ['method' => 'POST', 'path' => '/mfa/confirm', 'status' => 200, 'body' => V::obj(['code' => V::str()->regex('/^\d{6}$/')]),
                'handler' => fn(Ctx $c) => new Reply(AuthService::mfaConfirmSetup($c->actor->userId, $c->body['code']), 200)],
            ['method' => 'POST', 'path' => '/mfa/recovery-codes', 'status' => 200, 'body' => V::obj(['code' => V::str()->regex('/^\d{6}$/')]),
                'handler' => fn(Ctx $c) => new Reply(['recoveryCodes' => AuthService::mfaRegenerateRecoveryCodes($c->actor->userId, $c->body['code'])], 200)],
            ['method' => 'POST', 'path' => '/mfa/disable', 'status' => 204, 'body' => V::obj(['password' => $pw, 'code' => V::str()->min(6)->max(32)]),
                'handler' => function (Ctx $c) {
                    AuthService::mfaDisable($c->actor->userId, $c->body['password'], $c->body['code']);
                    return new Reply(null, 204);
                }],
        ]);
    }
}
