<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Entity tags. The ETag is the record's version (what `If-Match` compares). Some records also show DERIVED data that does not bump the
 * version (a lead's score, last activity and follow-up date follow its tasks and timeline): those values are folded into the tag as a
 * short fingerprint, so a client revalidating with `If-None-Match` is never told "304 Not Modified" about a changed representation.
 *   "7"  ->  "7.3fa91c0e"
 */
final class Etag
{
    /** @return array{ETag:string} */
    public static function make(int $version, mixed ...$derived): array
    {
        if (!$derived) return ['ETag' => '"' . $version . '"'];
        return ['ETag' => '"' . $version . '.' . substr(hash('sha256', Json::enc($derived)), 0, 8) . '"'];
    }

    /** `If-Match` -> the record version it names (the fingerprint only exists for cache validation). */
    public static function ifMatchVersion(?string $header): ?int
    {
        if ($header === null) return null;
        if (!preg_match('/^(?:W\/)?"?(\d{1,15})(?:\.[0-9a-f]{8})?"?$/', trim($header), $m)) throw Errors::badRequest('If-Match must be the ETag of the record, e.g. "3"');
        return (int) $m[1];
    }
}
