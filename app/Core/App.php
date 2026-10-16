<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Inbound\Receiver;

/**
 * The front controller: security headers, CORS, CSRF, routing (API, public lead intake, health, docs) and the single-page UI.
 * Everything arrives through public/index.php (Apache rewrites everything that is not a real file to it).
 */
final class App
{
    public static function run(): void
    {
        $req = Request::fromGlobals();
        $res = new Response();
        try {
            \App\Routes::registerAll();
            self::dispatch($req, $res);
        } catch (\Throwable $e) {
            Router::fail($e, $req, $res);
        }
        $res->send();
        self::afterResponse($req);
    }

    private static function dispatch(Request $req, Response $res): void
    {
        $path = $req->path;

        if ($path === '/healthz' || $path === '/api/health') {
            $res->json(200, ['status' => 'ok', 'time' => Time::iso(Time::now())]);
            $res->header('Cache-Control', 'no-store');
            return;
        }
        if ($path === '/readyz') {
            try {
                $v = Db::system()->tx(static fn(Tx $tx) => $tx->val('SELECT MAX(version) FROM schema_migrations'));
                $res->json(200, ['status' => 'ready', 'database' => 'connected', 'schemaVersion' => $v]);
            } catch (\Throwable $e) {
                Logger::error('Readiness probe failed', ['error' => $e->getMessage()]);
                $res->json(503, ['status' => 'unavailable', 'database' => 'disconnected']);
            }
            $res->header('Cache-Control', 'no-store');
            return;
        }

        // Public lead intake (Meta / Google / website / generic): signature or key authenticated, its own CORS, never cookies or CSRF.
        if (str_starts_with($path, '/hooks/') || $path === '/hooks') {
            $res->header('Cache-Control', 'no-store');
            self::apiHeaders($res, $req);
            Receiver::handle($req, $res);
            return;
        }

        if (str_starts_with($path, '/api/') || $path === '/api') {
            self::apiHeaders($res, $req);
            $res->header('Cache-Control', 'no-store');
            if ($req->method === 'OPTIONS') {
                self::preflight($req, $res);
                return;
            }
            self::cors($req, $res);
            if ($path === '/api/v1/openapi.json') {
                self::file(APP_ROOT . '/docs/openapi.json', 'application/json; charset=utf-8', $res, 'public, max-age=300');
                return;
            }
            if ($path === '/api/docs') {
                self::apiDocs($req, $res);
                return;
            }
            Csrf::protect($req, $res);
            Router::dispatch($req, $res);
            return;
        }

        // A real file in public/ (only reached when the web server did not serve it itself, e.g. php -S)
        $file = realpath(APP_ROOT . '/public' . $path);
        if ($file !== false && is_file($file) && str_starts_with($file, realpath(APP_ROOT . '/public') . DIRECTORY_SEPARATOR) && !in_array(basename($file), ['index.php', 'app.html'], true) && !str_starts_with(basename($file), '.')) {
            self::file($file, self::mime($file), $res, 'public, max-age=86400');
            return;
        }
        self::spa($req, $res);
    }

    // ── headers & CORS ────────────────────────────────────────────────────────────────────────────────────────────

    private static function apiHeaders(Response $res, Request $req): void
    {
        $res->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $res->header('X-Content-Type-Options', 'nosniff');
        $res->header('Referrer-Policy', 'no-referrer');
        $res->header('Cross-Origin-Resource-Policy', 'same-site');
        if ($req->scheme === 'https' && Config::isProduction()) $res->header('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    private static function cors(Request $req, Response $res): void
    {
        $o = $req->header('origin');
        if ($o && Csrf::originAllowed($req, $o)) {
            $res->header('Access-Control-Allow-Origin', $o);
            $res->header('Access-Control-Allow-Credentials', 'true');
            $res->header('Access-Control-Expose-Headers', 'X-Request-Id, RateLimit-Limit, RateLimit-Remaining, Retry-After, Idempotent-Replay, ETag');
            $res->header('Vary', 'Origin');
        }
    }

    private static function preflight(Request $req, Response $res): void
    {
        $o = $req->header('origin');
        $res->status = 204;
        if ($o && Csrf::originAllowed($req, $o)) {
            $res->header('Access-Control-Allow-Origin', $o);
            $res->header('Access-Control-Allow-Credentials', 'true');
            $res->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $res->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key, If-Match, X-Request-Id, X-Tenant-Id, X-CSRF-Token');
            $res->header('Access-Control-Max-Age', '600');
            $res->header('Vary', 'Origin');
        }
    }

    // ── static things ─────────────────────────────────────────────────────────────────────────────────────────────

    private static function mime(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'js', 'mjs' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff2' => 'font/woff2', 'woff' => 'font/woff', 'txt' => 'text/plain; charset=utf-8', 'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    private static function file(string $file, string $type, Response $res, string $cache): void
    {
        if (!is_file($file)) {
            $res->json(404, ['status' => 404, 'detail' => 'Not found'], 'application/problem+json');
            return;
        }
        $res->status = 200;
        $res->header('Content-Type', $type);
        $res->header('Cache-Control', $cache);
        $res->body = (string) file_get_contents($file);
    }

    private static function apiDocs(Request $req, Response $res): void
    {
        $base = $req->basePath;
        $res->status = 200;
        $res->header('Content-Type', 'text/html; charset=utf-8');
        $res->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
        $res->body = '<!doctype html><meta charset="utf-8"><title>Anibros CRM API</title><style>body{font:16px system-ui;max-width:720px;margin:48px auto;padding:0 16px;color:#1c1c1a}code{background:#f3f2ec;padding:2px 6px;border-radius:4px}</style>'
            . '<h1>Anibros CRM API</h1><p>The machine-readable description of every endpoint is at <a href="' . htmlspecialchars($base) . '/api/v1/openapi.json"><code>/api/v1/openapi.json</code></a> (OpenAPI 3).</p>'
            . '<p>Authenticate with <code>Authorization: Bearer crm_live_…</code> (create a key in Settings → API keys). Every response is <code>{ "data": … }</code>; errors are RFC 9457 problem documents.</p>';
    }

    /** The single-page UI: the built index with the base path and a CSP nonce injected. */
    private static function spa(Request $req, Response $res): void
    {
        $file = APP_ROOT . '/public/app.html';
        $res->header('Cache-Control', 'no-cache');
        $res->header('X-Content-Type-Options', 'nosniff');
        $res->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->header('X-Frame-Options', 'DENY');
        if ($req->scheme === 'https' && Config::isProduction()) $res->header('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        if (!is_file($file)) {
            $res->status = 503;
            $res->header('Content-Type', 'text/html; charset=utf-8');
            $res->body = '<!doctype html><meta charset="utf-8"><title>Anibros CRM</title><body style="font:16px system-ui;padding:48px"><h1>The interface has not been built yet</h1><p>Copy the built UI into <code>public/</code> (see docs/DEPLOY_CPANEL.md).</p>';
            return;
        }
        $nonce = base64_encode(random_bytes(16));
        $html = (string) file_get_contents($file);
        $base = $req->basePath;
        $inject = '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '/"><script nonce="' . $nonce . '">window.__ANIBROS__=' . Json::enc(['base' => $base]) . ';</script>';
        $html = preg_replace('/<head>/i', '<head>' . $inject, $html, 1);
        $html = preg_replace('/<script>/i', '<script nonce="' . $nonce . '">', $html);
        $res->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $res->status = 200;
        $res->header('Content-Type', 'text/html; charset=utf-8');
        $res->body = $html;
    }

    // ── after the response ────────────────────────────────────────────────────────────────────────────────────────

    /** Hand the response to the browser first, then (at most every 20 s, at most ~8 s of work) run due background jobs. */
    private static function afterResponse(Request $req): void
    {
        if (!Config::get('pump_after_response') || str_starts_with($req->path, '/healthz')) return;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @ob_flush();
            @flush();
        }
        ignore_user_abort(true);
        try {
            Worker::pump(8.0);
        } catch (\Throwable $e) {
            Logger::warn('Opportunistic job pump failed', ['error' => $e->getMessage()]);
        }
    }
}
