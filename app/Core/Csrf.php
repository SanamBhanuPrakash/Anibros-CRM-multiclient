<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF defence for cookie-authenticated requests (defence in depth on top of SameSite=Lax):
 *  1. Origin / Referer, when present on an unsafe method, must be this site or an allowed origin.
 *  2. Double-submit token: the `x-csrf-token` header must equal the (JS-readable) CSRF cookie.
 * Bearer / API-key requests carry no ambient credentials, so they are exempt (no session cookie => nothing to forge).
 *
 * Cookie names get the `__Host-` prefix on HTTPS (forces Secure, Path=/, no Domain: defeats cookie injection from sub-domains).
 */
final class Csrf
{
    public static function secure(Request $req): bool
    {
        return $req->scheme === 'https';
    }

    public static function cookieName(Request $req): string
    {
        return (self::secure($req) ? '__Host-' : '') . 'anibrosdesign_csrf';
    }

    public static function sessionCookieName(Request $req): string
    {
        return (self::secure($req) ? '__Host-' : '') . (string) Config::get('session_cookie', 'anibrosdesign_session');
    }

    /** Issue a fresh token cookie and return the token. */
    public static function issue(Request $req, Response $res): string
    {
        $token = Ids::token(24);
        $res->cookie(self::cookieName($req), $token, ['httponly' => false, 'secure' => self::secure($req), 'samesite' => 'Lax', 'path' => '/']);
        return $token;
    }

    /** @return string[] origins (scheme://host[:port]) allowed besides this site itself */
    private static function allowedOrigins(): array
    {
        $list = (array) Config::get('allowed_origins', []);
        $app = (string) Config::get('app_url', '');
        if ($app !== '') {
            $p = parse_url($app);
            if ($p && isset($p['scheme'], $p['host'])) $list[] = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return array_map(static fn($o) => rtrim(strtolower((string) $o), '/'), $list);
    }

    public static function originAllowed(Request $req, string $origin): bool
    {
        $o = rtrim(strtolower($origin), '/');
        if (in_array($o, self::allowedOrigins(), true)) return true;
        $h = parse_url($o);
        $host = strtolower($req->host);
        return $h && isset($h['host']) && (strtolower($h['host']) . (isset($h['port']) ? ':' . $h['port'] : '')) === $host;
    }

    /** @throws AppError */
    public static function protect(Request $req, Response $res): void
    {
        if (in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (!$req->cookie(self::cookieName($req))) self::issue($req, $res);
            return;
        }
        $origin = $req->origin();
        if ($origin !== null && !self::originAllowed($req, $origin)) throw Errors::forbidden('Cross-origin request rejected');
        if ($req->cookie(self::sessionCookieName($req))) {
            $cookie = $req->cookie(self::cookieName($req));
            $header = $req->header('x-csrf-token');
            if (!$cookie || !$header || !hash_equals($cookie, $header)) throw Errors::forbidden('Invalid or missing CSRF token');
        }
    }
}
