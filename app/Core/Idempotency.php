<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Idempotency-Key support for non-idempotent POSTs (public API).
 *
 *  - First request with a key: reserves it (INSERT IGNORE), runs the handler, stores the response.
 *  - Retry with the same key + same payload: replays the stored response (`Idempotent-Replay: true`), handler NOT re-run.
 *  - Same key, different payload / actor / route: 422. Retry while the first is still running: 409 + Retry-After.
 *  - 5xx responses are not stored (the client may safely retry for real).
 * Keys are scoped per workspace, expire after 24 h (worker retention) and are bound to actor + method + path + body hash.
 */
final class Idempotency
{
    private const LOCK_SECONDS = 60;

    /** @return string|null 'replayed' when a stored response was written to $res, otherwise null (continue with the handler) */
    public static function begin(Request $req, Response $res, Actor $actor): ?string
    {
        $key = $req->header('idempotency-key');
        if ($key === null || $key === '') return null;
        if (strlen($key) < 8 || strlen($key) > 128 || !preg_match('/^[\x21-\x7E]+$/', $key)) throw Errors::badRequest('Idempotency-Key must be 8–128 printable ASCII characters');
        $hash = hash('sha256', implode("\n", [$req->method, $req->path, $actor->userId ?? $actor->apiKeyId ?? '', Json::enc($req->json() ?? new \stdClass())]));
        $ctx = ['tenantId' => $actor->tenantId, 'userId' => $actor->userId, 'requestId' => $actor->requestId, 'ip' => $actor->ip];

        $r = Db::tenant($ctx)->tx(static function (Tx $tx) use ($key, $hash) {
            $tx->run('INSERT IGNORE INTO idempotency_keys (tenant_id, `key`, request_hash, locked_until) VALUES (?, ?, ?, DATE_ADD(NOW(3), INTERVAL ? SECOND))', [$tx->tenantId, $key, $hash, self::LOCK_SECONDS]);
            if ($tx->affected > 0) return ['fresh' => true];
            $row = $tx->one('SELECT request_hash, status_code, response_body, (locked_until > NOW(3)) AS locked FROM idempotency_keys WHERE `key` = ? FOR UPDATE', [$key]);
            // A crashed first attempt leaves a lock that expires: allow takeover then.
            if ($row && $row['status_code'] === null && !$row['locked']) {
                $tx->run('UPDATE idempotency_keys SET locked_until = DATE_ADD(NOW(3), INTERVAL ? SECOND), request_hash = ? WHERE `key` = ?', [self::LOCK_SECONDS, $hash, $key]);
                return ['fresh' => true];
            }
            return ['fresh' => false, 'row' => $row];
        });

        if (!$r['fresh']) {
            $row = $r['row'];
            if ($row['request_hash'] !== $hash) throw Errors::idempotencyConflict();
            if ($row['status_code'] === null) throw Errors::conflict('A request with this Idempotency-Key is still being processed', [], ['Retry-After' => '2']);
            $res->header('Idempotent-Replay', 'true');
            $res->status = (int) $row['status_code'];
            $res->headers['Content-Type'] = 'application/json; charset=utf-8';
            $res->body = (string) $row['response_body'];
            return 'replayed';
        }
        $req->idem = ['key' => $key] + $ctx;
        return null;
    }

    /** Persist (or release) the outcome of a request that reserved a key. Called for every response, errors included. */
    public static function complete(Request $req, Response $res): void
    {
        $i = $req->idem;
        if (!$i) return;
        $req->idem = null;
        try {
            Db::tenant(['tenantId' => $i['tenantId'], 'userId' => $i['userId'], 'requestId' => $i['requestId'], 'ip' => $i['ip']])->tx(function (Tx $tx) use ($i, $res) {
                if ($res->status >= 500) $tx->run('DELETE FROM idempotency_keys WHERE `key` = ?', [$i['key']]);
                else $tx->run('UPDATE idempotency_keys SET status_code = ?, response_body = ?, locked_until = NULL WHERE `key` = ?', [$res->status, $res->body === '' ? 'null' : $res->body, $i['key']]);
            }, ['retries' => 0]);
        } catch (\Throwable $e) {
            Logger::warn('Failed to persist idempotent response', ['error' => $e->getMessage()]);
        }
    }
}
