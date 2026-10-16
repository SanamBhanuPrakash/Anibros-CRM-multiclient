<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Keyset (cursor) pagination: O(log n) per page and stable while rows are inserted. The cursor is opaque to clients (base64url JSON) and
 * bound to the sort it was issued for, so it cannot be replayed against a different ordering.
 */
final class Pagination
{
    /** Query schema shared by every list endpoint: ?limit=1..200&cursor=... */
    public static function query(int $default = 50): V
    {
        return V::obj([
            'limit' => V::coerceInt()->min(1)->max(200)->default($default),
            'cursor' => V::str()->max(512)->optional(),
        ]);
    }

    /** @param array{k:string|int|float|null,i:string,s:string} $c */
    public static function encode(array $c): string
    {
        return Crypto::b64(Json::enc($c));
    }

    /** @return array{k:string|int|float|null,i:string,s:string}|null */
    public static function decode(?string $raw, string $sortSignature): ?array
    {
        if ($raw === null || $raw === '') return null;
        $c = Json::dec(Crypto::unb64($raw), null);
        if (!is_array($c) || !isset($c['i']) || !is_string($c['i']) || ($c['s'] ?? null) !== $sortSignature) throw Errors::badRequest('Invalid pagination cursor');
        return $c;
    }

    /**
     * Build a page from `limit + 1` fetched rows (ORDER BY key dir, id dir LIMIT limit+1).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param callable(array):(string|int|float|null) $keyOf sort-key value of a row
     * @return array{items:array,page:array{limit:int,next:?string,hasMore:bool}}
     */
    public static function page(array $rows, int $limit, string $sortSignature, callable $keyOf, string $idField = 'id'): array
    {
        $hasMore = count($rows) > $limit;
        $items = $hasMore ? array_slice($rows, 0, $limit) : $rows;
        $last = $items ? $items[count($items) - 1] : null;
        return [
            'items' => $items,
            'page' => [
                'limit' => $limit,
                'next' => $hasMore && $last ? self::encode(['k' => $keyOf($last), 'i' => $last[$idField], 's' => $sortSignature]) : null,
                'hasMore' => $hasMore,
            ],
        ];
    }

    /**
     * SQL fragment "rows after the cursor" for a (key, id) ordering; pushes the parameters. keyExpr / idExpr are trusted SQL fragments.
     * Timestamps travel as ISO strings in cursors and are converted back to the DB format here.
     *
     * @param array<int,mixed> $params
     */
    public static function predicate(?array $cursor, array &$params, string $keyExpr, string $idExpr, string $direction, string $keyType = 'str'): ?string
    {
        if ($cursor === null) return null;
        $k = $cursor['k'];
        if ($keyType === 'ts' && $k !== null) $k = Time::db($k);
        $params[] = $k;
        $params[] = $cursor['i'];
        $op = $direction === 'desc' ? '<' : '>';
        return "($keyExpr, $idExpr) $op (?, ?)";
    }
}
