<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/** A raw SQL fragment for Tx::insert / Tx::update values (never put user input in one). */
final class Raw
{
    public function __construct(public readonly string $sql)
    {
    }
}

/**
 * SQL preparation: array expansion for IN lists and the table => view rewriting that gives every query its data-isolation context.
 *
 * In a workspace or user context every table named after FROM / JOIN / UPDATE / INSERT INTO / DELETE FROM is replaced by its view
 * (v_<table> to read, w_<table> to write where a narrower write view exists). A table that is not available in that context
 * (identity-only or system tables) or unknown raises an error instead of silently reaching the base table. Comma joins are refused
 * because the second table would escape the rewrite.
 */
final class Sql
{
    private const QUOTED = '/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`)/s';

    /** @return array{0:string,1:array<int|string,mixed>} */
    public static function prepare(string $sql, array $params, string $mode): array
    {
        $parts = preg_split(self::QUOTED, $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];
        $positional = array_is_list($params);
        $flat = [];
        $idx = 0;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) continue; // quoted literal / quoted identifier: untouched
            if ($positional) {
                $part = preg_replace_callback('/\?/', static function () use (&$idx, $params, &$flat) {
                    if (!array_key_exists($idx, $params)) throw new \LogicException('Too few SQL parameters');
                    $v = $params[$idx++];
                    if (is_array($v)) {
                        if ($v === []) { return 'NULL'; }
                        foreach ($v as $x) $flat[] = $x;
                        return implode(',', array_fill(0, count($v), '?'));
                    }
                    $flat[] = $v;
                    return '?';
                }, $part);
            } else {
                $part = preg_replace_callback('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)/', static function ($m) use ($params, &$flat) {
                    $name = $m[1];
                    if (!array_key_exists($name, $params) && !array_key_exists(':' . $name, $params)) return $m[0];
                    $v = array_key_exists($name, $params) ? $params[$name] : $params[':' . $name];
                    if (is_array($v)) {
                        if ($v === []) return 'NULL';
                        $names = [];
                        foreach (array_values($v) as $k => $x) { $n = $name . '_' . $k; $flat[$n] = $x; $names[] = ':' . $n; }
                        return implode(',', $names);
                    }
                    $flat[$name] = $v;
                    return ':' . $name;
                }, $part);
            }
            if ($mode === 'tenant' || $mode === 'user') $part = self::rewrite($part, $mode);
            $parts[$i] = $part;
        }
        if ($positional && $idx !== count($params)) throw new \LogicException('Too many SQL parameters: used ' . $idx . ' of ' . count($params));
        return [implode('', $parts), $flat];
    }

    private static function rewrite(string $s, string $mode): string
    {
        $s = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON__DUP__KEY__UPD', $s);
        $s = preg_replace('/FOR\s+UPDATE/i', 'FOR__UPD', $s);
        if (preg_match('/\b(?:FROM|JOIN|UPDATE)\s+[a-z_][a-z0-9_]*(?:\s+(?:AS\s+)?(?!(?:ON|WHERE|SET|USING|LEFT|RIGHT|INNER|CROSS|JOIN|GROUP|ORDER|LIMIT|HAVING|UNION|FOR|VALUES|SELECT)\b)[a-z_][a-z0-9_]*)?\s*,/i', $s)) {
            throw new \LogicException('Comma joins are not allowed in scoped SQL (use explicit JOIN): ' . substr(trim($s), 0, 120));
        }
        $s = preg_replace_callback(
            '/\b(UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|DELETE\s+FROM|FROM|JOIN)(\s+)([a-z_][a-z0-9_]*)\b(?!\s*\()/i',
            static function (array $m) use ($mode): string {
                $kw = strtoupper(preg_replace('/\s+/', ' ', $m[1]));
                $table = strtolower($m[3]);
                if ($table === 'dual') return $m[0];
                $write = in_array($kw, ['UPDATE', 'INSERT INTO', 'INSERT IGNORE INTO', 'DELETE FROM'], true);
                if (Schema::isTenant($table)) {
                    if ($mode === 'user') throw new \LogicException("Table $table is a workspace table and needs a workspace context (Db::tenant)");
                    // jobs: a workspace may queue platform jobs (mail has no workspace) but only ever reads its own
                    return $m[1] . $m[2] . (($write && $table === 'jobs') ? 'w_' : 'v_') . $table;
                }
                if (in_array($table, Schema::IDENTITY_V, true)) {
                    $view = ($write && in_array($table, Schema::WRITE_VIEWS, true)) ? 'w_' : 'v_';
                    return $m[1] . $m[2] . $view . $table;
                }
                throw new \LogicException("Table $table is not available in a scoped context (identity or system tables need Db::identity() / Db::system())");
            },
            $s
        );
        $s = str_replace(['ON__DUP__KEY__UPD', 'FOR__UPD'], ['ON DUPLICATE KEY UPDATE', 'FOR UPDATE'], $s);
        return $s;
    }
}

/**
 * Data access entry points. Four contexts, see PROJECT_LOG section 1.1:
 *   Db::tenant($actor)   one workspace (views v_* filtered by the workspace)
 *   Db::asUser($userId)  who is asking, no workspace yet (agency layer): identity views only
 *   Db::identity()       sign-in, sign-up, sessions, tokens, invitations (base tables; allow-listed callers only)
 *   Db::system()         worker / installer (base tables; allow-listed callers only)
 */
final class Db
{
    private static ?PDO $pdo = null;
    public static ?Tx $current = null;
    public static int $queries = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = Config::get('db');
        $dsn = !empty($c['socket'])
            ? 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4'
            : 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        try {
            self::$pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED, sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            Logger::error('Database connection failed', ['error' => $e->getMessage()]);
            throw Errors::dependencyUnavailable('The database is not reachable');
        }
        return self::$pdo;
    }

    /** Drop the connection (long-running worker loops reconnect after errors). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$current = null;
    }

    public static function tenant(Actor|array $ctx): Conn
    {
        $a = $ctx instanceof Actor ? ['tenantId' => $ctx->tenantId, 'userId' => $ctx->userId, 'requestId' => $ctx->requestId, 'ip' => $ctx->ip] : $ctx;
        if (!Ids::isUuid($a['tenantId'] ?? null)) throw new \InvalidArgumentException('[db] invalid tenantId');
        if (!empty($a['userId']) && !Ids::isUuid($a['userId'])) throw new \InvalidArgumentException('[db] invalid userId');
        return new Conn('tenant', [
            'tenantId' => strtolower($a['tenantId']),
            'userId' => !empty($a['userId']) ? strtolower($a['userId']) : null,
            'requestId' => self::cleanRequestId($a['requestId'] ?? null),
            'ip' => !empty($a['ip']) && filter_var($a['ip'], FILTER_VALIDATE_IP) ? $a['ip'] : null,
        ]);
    }

    public static function asUser(string $userId): Conn
    {
        if (!Ids::isUuid($userId)) throw new \InvalidArgumentException('[db] invalid userId');
        return new Conn('user', ['tenantId' => null, 'userId' => strtolower($userId), 'requestId' => null, 'ip' => null]);
    }

    public static function identity(): Conn
    {
        return new Conn('identity', ['tenantId' => null, 'userId' => null, 'requestId' => null, 'ip' => null]);
    }

    public static function system(): Conn
    {
        return new Conn('system', ['tenantId' => null, 'userId' => null, 'requestId' => null, 'ip' => null]);
    }

    public static function raw(string $sql): Raw
    {
        return new Raw($sql);
    }

    public static function now(): Raw
    {
        return new Raw('NOW(3)');
    }

    private static function cleanRequestId(?string $id): string
    {
        return ($id !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id)) ? $id : Ids::uuid();
    }
}

/** A handle for one context: runs callbacks (or single statements) inside a transaction carrying that context. */
final class Conn
{
    /** @param array{tenantId:?string,userId:?string,requestId:?string,ip:?string} $ctx */
    public function __construct(private string $mode, private array $ctx)
    {
    }

    /**
     * Run $fn(Tx) in a transaction. Joins the transaction already open on this request (switching its context for the duration),
     * so services may call each other without nesting problems. Retries deadlocks.
     *
     * @template T
     * @param callable(Tx):T $fn
     * @return T
     */
    public function tx(callable $fn, array $opts = []): mixed
    {
        if (Db::$current !== null) return $this->join($fn);
        $retries = $opts['retries'] ?? 2;
        $readonly = (bool) ($opts['readonly'] ?? false);
        for ($attempt = 0;; $attempt++) {
            $pdo = Db::pdo();
            $tx = null;
            try {
                $pdo->exec($readonly ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION');
                $tx = new Tx($this->mode, $this->ctx['tenantId'], $this->ctx['userId'], $this->ctx['requestId'], $this->ctx['ip']);
                Db::$current = $tx;
                $tx->applyContext();
                $result = $fn($tx);
                $pdo->exec('COMMIT');
                $tx->runAfterCommit();
                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    Db::reset();
                }
                $retry = $e instanceof PDOException && (($e->errorInfo[1] ?? 0) === 1213 || ($e->errorInfo[0] ?? '') === '40001');
                if ($retry && $attempt < $retries) {
                    usleep((int) ((20 * 2 ** $attempt + random_int(0, 30)) * 1000));
                    continue;
                }
                throw $e;
            } finally {
                Db::$current = null;
                try {
                    $pdo->exec('SET @app_tenant = NULL, @app_user = NULL, @app_req = NULL, @app_ip = NULL');
                } catch (\Throwable) {
                }
            }
        }
    }

    /** A read-only transaction (no retries). */
    public function read(callable $fn): mixed
    {
        return $this->tx($fn, ['readonly' => true, 'retries' => 0]);
    }

    /** One statement in its own transaction; returns all rows. */
    public function query(string $sql, array $params = []): array
    {
        return $this->tx(static fn(Tx $tx) => $tx->all($sql, $params), ['retries' => 0]);
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->tx(static fn(Tx $tx) => $tx->one($sql, $params), ['retries' => 0]);
    }

    /** One write statement; returns affected rows. */
    public function run(string $sql, array $params = []): int
    {
        return $this->tx(static fn(Tx $tx) => $tx->run($sql, $params), ['retries' => 0]);
    }

    private function join(callable $fn): mixed
    {
        $tx = Db::$current;
        $saved = $tx->snapshot();
        $tx->switchTo($this->mode, $this->ctx['tenantId'], $this->ctx['userId'], $this->ctx['requestId'], $this->ctx['ip']);
        try {
            return $fn($tx);
        } finally {
            $tx->restore($saved);
        }
    }
}

/** A statement executor bound to one open transaction and its isolation context. */
final class Tx
{
    public int $affected = 0;
    /** @var array<int,callable> */
    private array $afterCommit = [];

    public function __construct(
        public string $mode,
        public ?string $tenantId,
        public ?string $userId,
        public ?string $requestId,
        public ?string $ip,
    ) {
    }

    // ── context ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @var array<string,string> userId => comma list of organisations they own / administer (request-level cache) */
    private static array $adminOrgs = [];

    public function applyContext(): void
    {
        $scoped = in_array($this->mode, ['tenant', 'user'], true);
        $uid = $scoped ? $this->userId : null;
        $st = Db::pdo()->prepare('SET @app_tenant = ?, @app_user = ?, @app_req = ?, @app_ip = ?, @app_admin_orgs = ?');
        $st->execute([$this->mode === 'tenant' ? $this->tenantId : null, $uid, $this->requestId, $this->ip, $uid ? self::adminOrgsFor($uid) : null]);
    }

    /**
     * Organisations the user owns or administers (HQ owner/admin). Computed here, with the base tables, by the data layer itself and handed
     * to the write views as a session variable (see Views). Cached for the request; any write to tenant_memberships refreshes it.
     */
    private static function adminOrgsFor(string $userId): string
    {
        if (isset(self::$adminOrgs[$userId])) return self::$adminOrgs[$userId];
        $st = Db::pdo()->prepare("SELECT GROUP_CONCAT(DISTINCT h.organisation_id) FROM tenant_memberships m JOIN tenants h ON h.id = m.tenant_id
                                   WHERE m.user_id = ? AND h.kind = 'agency_hq' AND m.role IN ('tenant_owner', 'tenant_admin') AND m.status = 'active' AND m.is_active = 1");
        $st->execute([$userId]);
        return self::$adminOrgs[$userId] = (string) ($st->fetchColumn() ?: '');
    }

    /** Act as $userId inside workspace $tenantId from here to the end of the transaction (the agency layer entering a client). */
    public function actAs(string $tenantId, ?string $userId = null, ?string $requestId = null, ?string $ip = null): void
    {
        if (!Ids::isUuid($tenantId)) throw new \InvalidArgumentException('[db] invalid tenantId');
        $this->switchTo('tenant', strtolower($tenantId), $userId ?? $this->userId, $requestId ?? $this->requestId, $ip ?? $this->ip);
    }

    /** @return array{0:string,1:?string,2:?string,3:?string,4:?string} */
    public function snapshot(): array
    {
        return [$this->mode, $this->tenantId, $this->userId, $this->requestId, $this->ip];
    }

    public function restore(array $s): void
    {
        $this->switchTo(...$s);
    }

    public function switchTo(string $mode, ?string $tenantId, ?string $userId, ?string $requestId, ?string $ip): void
    {
        $this->mode = $mode;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->requestId = $requestId;
        $this->ip = $ip;
        $this->applyContext();
    }

    /** Run after a successful COMMIT (cache busting, opportunistic job pump). */
    public function afterCommit(callable $fn): void
    {
        $this->afterCommit[] = $fn;
    }

    public function runAfterCommit(): void
    {
        $cbs = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($cbs as $cb) {
            try {
                $cb();
            } catch (\Throwable $e) {
                Logger::warn('after-commit callback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    // ── statements ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->exec($sql, $params);
        return $st->columnCount() > 0 ? $this->fetchAll($st) : [];
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    /** First column of the first row, or null. */
    public function val(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : reset($row);
    }

    /** First column of every row. */
    public function col(string $sql, array $params = []): array
    {
        return array_map(static fn(array $r) => reset($r), $this->all($sql, $params));
    }

    public function exists(string $sql, array $params = []): bool
    {
        return $this->one($sql, $params) !== null;
    }

    /** Executes a write; returns affected rows. */
    public function run(string $sql, array $params = []): int
    {
        $st = $this->exec($sql, $params);
        $this->affected = $st->rowCount();
        return $this->affected;
    }

    public function lastInsertId(): string
    {
        return (string) Db::pdo()->lastInsertId();
    }

    /**
     * INSERT helper. Fills `id` (UUID) and `tenant_id` when the table needs them and the caller did not. Arrays become JSON text
     * (use Json::encObj() yourself for object columns that may be empty). Returns the new id (null for tables without a UUID id).
     *
     * @param array<string,mixed> $row
     * @param array{ignore?:bool,onDuplicate?:array<string,mixed>} $opts
     */
    public function insert(string $table, array $row, array $opts = []): ?string
    {
        if (!in_array($table, Schema::NO_UUID_ID, true) && !array_key_exists('id', $row)) $row = ['id' => Ids::uuid()] + $row;
        if (Schema::isTenant($table) && !array_key_exists('tenant_id', $row) && $this->tenantId !== null && $this->mode === 'tenant') $row['tenant_id'] = $this->tenantId;
        $cols = [];
        $ph = [];
        $params = [];
        foreach ($row as $c => $v) {
            $cols[] = self::ident($c);
            if ($v instanceof Raw) { $ph[] = $v->sql; continue; }
            $ph[] = '?';
            $params[] = self::value($v);
        }
        $sql = ($opts['ignore'] ?? false ? 'INSERT IGNORE INTO ' : 'INSERT INTO ') . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
        if (!empty($opts['onDuplicate'])) {
            $sets = [];
            foreach ($opts['onDuplicate'] as $c => $v) {
                if ($v instanceof Raw) $sets[] = self::ident($c) . ' = ' . $v->sql;
                else { $sets[] = self::ident($c) . ' = ?'; $params[] = self::value($v); }
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        }
        $this->run($sql, $params);
        return $row['id'] ?? null;
    }

    /**
     * "Insert unless a unique key already exists" (PostgreSQL's ON CONFLICT DO NOTHING). Deliberately NOT `INSERT IGNORE`, which would also
     * swallow foreign-key and NOT NULL errors: only a duplicate key is tolerated. Returns the id when a row was inserted, null when it already existed.
     */
    public function insertIgnore(string $table, array $row): ?string
    {
        $first = array_key_first($row);
        if (!array_key_exists('id', $row) && !in_array($table, Schema::NO_UUID_ID, true)) $first = 'id';
        $id = $this->insert($table, $row, ['onDuplicate' => [$first => new Raw(self::ident($first))]]);
        return $this->affected > 0 ? ($id ?? '') : null;
    }

    /**
     * UPDATE helper: SET from an array (Raw allowed), WHERE written with positional ? placeholders.
     *
     * @param array<string,mixed> $set
     */
    public function update(string $table, array $set, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($set as $c => $v) {
            if ($v instanceof Raw) { $sets[] = self::ident($c) . ' = ' . $v->sql; continue; }
            $sets[] = self::ident($c) . ' = ?';
            $params[] = self::value($v);
        }
        if (!$sets) return 0;
        foreach ($whereParams as $p) $params[] = $p;
        return $this->run('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where, $params);
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM ' . $table . ' WHERE ' . $where, $params);
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    private static function ident(string $c): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $c)) throw new \InvalidArgumentException("Bad column name: $c");
        return in_array(strtolower($c), ['key', 'type', 'value', 'status', 'name', 'role', 'source', 'channel', 'kind', 'sort', 'options', 'events', 'result', 'context', 'location', 'floor', 'version', 'template', 'description', 'provider', 'config', 'priority', 'message', 'payload', 'metadata', 'filters', 'mapping', 'errors', 'scopes', 'summary', 'attempts', 'title', 'link', 'url', 'error', 'tokens', 'prefix', 'address', 'city', 'state', 'country', 'email', 'phone', 'company', 'campaign', 'medium', 'keyword', 'note', 'reason', 'outcome', 'direction', 'entity', 'label', 'facing', 'tags', 'tower', 'locality', 'amenities', 'currency', 'amount', 'code', 'action'], true) ? '`' . $c . '`' : $c;
    }

    public static function value(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) return Time::db($v);
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_array($v) || is_object($v)) return Json::enc($v);
        return $v;
    }

    private function exec(string $sql, array $params): PDOStatement
    {
        [$sql, $flat] = Sql::prepare($sql, $params, $this->mode);
        $st = Db::pdo()->prepare($sql);
        $i = 0;
        foreach ($flat as $k => $v) {
            $v = self::value($v);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            if (is_float($v)) $v = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0';
            if (is_int($k)) $st->bindValue(++$i, $v, $type);
            else $st->bindValue(':' . $k, $v, $type);
        }
        Db::$queries++;
        $st->execute();
        // A membership write can change who administers what: refresh the context the write views rely on.
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) && stripos($sql, 'tenant_memberships') !== false) {
            self::$adminOrgs = [];
            $this->applyContext();
        }
        return $st;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchAll(PDOStatement $st): array
    {
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $dt = [];
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) {
            $m = $st->getColumnMeta($i);
            if ($m && in_array($m['native_type'] ?? '', ['DATETIME', 'TIMESTAMP'], true)) $dt[] = $m['name'];
        }
        if ($dt) {
            foreach ($rows as &$r) {
                foreach ($dt as $c) {
                    if (isset($r[$c]) && is_string($r[$c])) $r[$c] = self::iso($r[$c]);
                }
            }
            unset($r);
        }
        return $rows;
    }

    private static function iso(string $v): string
    {
        if (strlen($v) === 19) $v .= '.000';
        return str_replace(' ', 'T', $v) . 'Z';
    }
}
