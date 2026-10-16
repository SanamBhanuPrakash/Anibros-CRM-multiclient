<?php
declare(strict_types=1);

namespace App\Modules\Leads;

/** Lead source vocabulary and the "leads from this source go to these people" rules stored in the workspace settings. */
final class Routing
{
    public const LEAD_SOURCES = ['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other'];
    /** A rule names one source, or `all`: "every lead goes to these people", whatever the source (a specific source rule still wins). */
    public const SOURCES = ['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other', 'all'];

    /**
     * Rules are stored in the settings; anything that does not look right is ignored rather than trusted.
     *
     * @return array<int,array{source:string,userIds:string[]}>
     */
    public static function parse(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) return [];
        $out = [];
        foreach (array_slice($raw, 0, 16) as $r) {
            if (!is_array($r)) continue;
            $source = $r['source'] ?? null;
            $ids = $r['userIds'] ?? null;
            if (!in_array($source, self::SOURCES, true) || !is_array($ids)) continue;
            foreach ($out as $x) if ($x['source'] === $source) continue 2;
            $userIds = array_slice(array_values(array_filter($ids, static fn($x) => is_string($x) && preg_match('/^[0-9a-f-]{36}$/i', $x))), 0, 20);
            if ($userIds) $out[] = ['source' => $source, 'userIds' => $userIds];
        }
        return $out;
    }
}
