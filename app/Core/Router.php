<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Identity\Authenticator;

/**
 * Declarative routes. A route is DATA: method, path, auth requirement, permission, input schemas, handler. The router turns that into
 * authenticate -> authorize -> validate -> (idempotency) -> handler -> envelope, exactly like the Node `mountRoutes`.
 *
 * Route spec keys:
 *   method   GET|POST|PUT|PATCH|DELETE
 *   path     '/api/v1/contacts/{id}' (relative to the mount prefix)
 *   auth     'session' (default; cookie session or API key) | 'public'
 *   perm     permission string or list (ALL are required)
 *   params / query / body   V schemas
 *   status   default success status (POST defaults to 201, others 200)
 *   idempotent  honour the Idempotency-Key header
 *   raw      body is not JSON (binary upload): handler gets the bytes in $ctx->body, capped by `rawLimit` bytes
 *   handler  callable(Ctx): Reply|RawReply|mixed
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,names:string[],spec:array}> */
    private static array $routes = [];

    /** @param array<int,array<string,mixed>> $specs */
    public static function mount(string $prefix, array $specs): void
    {
        foreach ($specs as $spec) {
            $path = $prefix . (($spec['path'] ?? '/') === '/' ? '' : $spec['path']);
            if ($path === '') $path = '/';
            $names = [];
            $regex = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static function ($m) use (&$names) {
                $names[] = $m[1];
                return '([^/]+)';
            }, $path);
            $spec['fullPath'] = $path;
            self::$routes[] = ['method' => strtoupper($spec['method']), 'regex' => '#^' . $regex . '/?$#', 'names' => $names, 'spec' => $spec];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function routes(): array
    {
        return array_map(static fn($r) => $r['spec'], self::$routes);
    }

    public static function dispatch(Request $req, Response $res): void
    {
        $start = microtime(true);
        Logger::$correlationId = $req->id;
        $res->header('X-Request-Id', $req->id);
        try {
            $route = null;
            $params = [];
            foreach (self::$routes as $r) {
                if ($r['method'] !== $req->method && !($req->method === 'HEAD' && $r['method'] === 'GET')) continue;
                if (preg_match($r['regex'], $req->path, $m)) {
                    foreach ($r['names'] as $i => $n) $params[$n] = rawurldecode($m[$i + 1]);
                    $route = $r;
                    break;
                }
            }
            if (!$route) {
                $problem = self::problem(Errors::notFound('Route'), $req);
                $problem['detail'] = 'No route for ' . $req->method . ' ' . $req->path;
                $res->json(404, $problem, 'application/problem+json');
                return;
            }
            self::run($route['spec'], $params, $req, $res);
        } catch (\Throwable $e) {
            self::fail($e, $req, $res);
        } finally {
            Idempotency::complete($req, $res);
            self::observe($req, $res, (microtime(true) - $start) * 1000);
        }
    }

    private static function run(array $spec, array $rawParams, Request $req, Response $res): void
    {
        $session = ($spec['auth'] ?? 'session') === 'session';
        $actor = null;
        if ($session) {
            Authenticator::authenticate($req, $res);
            $actor = $req->actor;
            if (!$actor) throw Errors::unauthenticated();
            foreach ((array) ($spec['perm'] ?? []) as $p) $actor->assertCan($p);
        }
        $params = isset($spec['params']) ? $spec['params']->parseOrFail($rawParams, 'path') : $rawParams;
        $query = isset($spec['query']) ? $spec['query']->parseOrFail($req->query, 'query') : $req->query;
        $body = null;
        $method = $req->method;
        if (!empty($spec['raw'])) {
            $limit = (int) ($spec['rawLimit'] ?? 10 * 1024 * 1024);
            $len = (int) ($req->header('content-length') ?? 0);
            if ($len > $limit) throw Errors::payloadTooLarge();
            $body = $req->body();
            if (strlen($body) > $limit) throw Errors::payloadTooLarge();
        } elseif (!in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            $limit = (int) ($spec['bodyLimit'] ?? 1024 * 1024);
            if ((int) ($req->header('content-length') ?? 0) > $limit) throw Errors::payloadTooLarge();
            $json = $req->json();
            self::assertNoNul($json, 'body');
            $body = isset($spec['body']) ? $spec['body']->parseOrFail($json ?? [], 'body') : ($json ?? []);
        }
        self::assertNoNul($req->query, 'query');
        $ctx = new Ctx($params, $query, $body, $actor, $req, $res, $req->session);

        if (!empty($spec['idempotent']) && $actor && Idempotency::begin($req, $res, $actor) === 'replayed') return;

        $out = ($spec['handler'])($ctx);

        if ($out instanceof RawReply) {
            foreach ($out->headers as $k => $v) $res->header($k, $v);
            $res->status = $out->status;
            $res->header('Content-Type', $out->contentType);
            $res->body = $out->body;
            return;
        }
        $reply = $out instanceof Reply ? $out : new Reply($out);
        $status = $reply->status ?? ($spec['status'] ?? ($method === 'POST' ? 201 : 200));
        foreach ($reply->headers as $k => $v) $res->header($k, $v);
        if ($status === 204) {
            $res->status = 204;
            $res->body = '';
        } else {
            $res->json($status, $reply->page ? ['data' => $reply->data, 'page' => $reply->page] : ['data' => $reply->data]);
        }
    }

    /** NUL characters cannot be stored in text columns: refuse them at the edge as a 400 instead of failing deep inside a query. */
    private static function assertNoNul(mixed $v, string $path, int $depth = 0): void
    {
        if (is_string($v)) {
            if (str_contains($v, "\0")) throw Errors::validation([['path' => $path, 'message' => 'must not contain NUL characters', 'code' => 'invalid_string']], "Invalid $path");
            return;
        }
        if (!is_array($v) || $depth > 12) return;
        foreach ($v as $k => $x) {
            if (is_string($k) && str_contains($k, "\0")) throw Errors::validation([['path' => $path . '.' . str_replace("\0", '', $k), 'message' => 'must not contain NUL characters', 'code' => 'invalid_string']], "Invalid $path");
            self::assertNoNul($x, $path . '.' . $k, $depth + 1);
        }
    }

    /** @return array<string,mixed> */
    public static function problem(AppError $e, ?Request $req = null): array
    {
        $base = array_filter([
            'type' => 'https://docs.anibros.app/errors/' . strtolower(str_replace('_', '-', $e->errorCode)),
            'title' => $e->title,
            'status' => $e->status,
            'detail' => $e->detail,
            'code' => $e->errorCode,
            'requestId' => $req?->id,
        ], static fn($v) => $v !== null);
        // extension members win over the base members (an invitation answer replaces `code` with LOGIN_REQUIRED), `error` is always last
        return array_merge($base, $e->errors ? ['errors' => $e->errors] : [], $e->extensions, ['error' => $e->detail ?? $e->title]);
    }

    public static function fail(\Throwable $e, Request $req, Response $res): void
    {
        $mapped = Errors::fromThrowable($e);
        $app = $mapped ?? Errors::internal();
        if (!$mapped) {
            Logger::error('Unhandled exception', ['error' => $e->getMessage(), 'name' => get_class($e), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => substr($e->getTraceAsString(), 0, 3000), 'route' => $req->method . ' ' . $req->path]);
        } elseif ($app->status === 401 || $app->status === 403) {
            Logger::warn('Access denied', ['code' => $app->errorCode, 'route' => $req->method . ' ' . $req->path, 'tenantId' => $req->actor?->tenantId, 'userId' => $req->actor?->userId]);
        } elseif ($app->status >= 500) {
            Logger::error('Request failed', ['code' => $app->errorCode, 'route' => $req->method . ' ' . $req->path, 'cause' => $e->getMessage()]);
        }
        foreach ($app->headers as $k => $v) $res->header($k, $v);
        $res->json($app->status, self::problem($app, $req), 'application/problem+json');
    }

    /** Access log + the curated, workspace-visible event record (5xx, 403/429, slow requests). Only requests attributable to a workspace. */
    private static function observe(Request $req, Response $res, float $ms): void
    {
        $route = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', ':id', $req->path);
        $level = $res->status >= 500 ? 'error' : ($res->status >= 400 ? 'warn' : 'debug');
        if ($level !== 'debug' || Config::get('log_level') === 'debug') {
            Logger::log($level, $req->method . ' ' . $route . ' → ' . $res->status . ' (' . (int) $ms . 'ms)', ['status' => $res->status, 'durationMs' => (int) $ms, 'ip' => $req->ip, 'tenantId' => $req->actor?->tenantId, 'userId' => $req->actor?->userId]);
        }
        $tenantId = $req->actor?->tenantId;
        if (!$tenantId) return;
        $ctx = ['method' => $req->method, 'route' => $route, 'status' => $res->status, 'durationMs' => (int) $ms, 'ip' => $req->ip];
        $base = ['tenantId' => $tenantId, 'userId' => $req->actor?->userId, 'correlationId' => $req->id];
        if ($res->status >= 500) EventLog::record($base + ['component' => 'api', 'severity' => 'error', 'throttleKey' => '5xx:' . $route, 'message' => "Server error on {$req->method} $route ({$res->status})", 'context' => $ctx]);
        elseif ($res->status === 403 || $res->status === 429) EventLog::record($base + ['component' => 'security', 'severity' => 'warn', 'throttleKey' => $res->status . ':' . $req->actor?->userId . ':' . $route, 'message' => ($res->status === 403 ? 'Permission denied' : 'Rate limited') . ": {$req->method} $route", 'context' => $ctx]);
        elseif ($ms >= 1500) EventLog::record($base + ['component' => 'api', 'severity' => 'warn', 'throttleKey' => 'slow:' . $route, 'message' => "Slow request: {$req->method} $route took " . (int) $ms . ' ms', 'context' => $ctx]);
    }
}
