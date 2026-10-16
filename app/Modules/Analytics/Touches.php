<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Tx;

/**
 * Touches: where a lead came from, as it was at the time. The first touch of a lead is written when the lead is created and is never edited (the
 * unique helper index keeps the first; nothing in the application updates attribution rows); a later enquiry from someone who already has an open lead
 * is appended as a later touch. "Last touch" is simply the newest row. Editing a lead's source afterwards changes the lead, not its history.
 */
final class Touches
{
    private static function clip(mixed $v, int $n): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $n) : null;
    }

    /**
     * Reads what a touch can carry out of the provenance that intake channels already record (utm tags, ad group, click ids).
     *
     * @param array{source:string,campaign?:?string,sourceDetails?:?array} $in
     * @return array{source:string,campaign:?string,medium:?string,adGroup:?string,keyword:?string,clickId:?string,utmContent:?string}
     */
    public static function from(array $in): array
    {
        $d = is_array($in['sourceDetails'] ?? null) ? $in['sourceDetails'] : [];
        $tracking = is_array($d['tracking'] ?? null) ? $d['tracking'] : [];
        return [
            'source' => $in['source'],
            'campaign' => self::clip($in['campaign'] ?? null, 200),
            'medium' => self::clip($tracking['utm_medium'] ?? null, 100),
            'adGroup' => self::clip($d['adgroupId'] ?? $d['adsetId'] ?? null, 200),
            'keyword' => self::clip($tracking['utm_term'] ?? null, 200),
            'clickId' => self::clip($d['gclid'] ?? $tracking['gclid'] ?? $tracking['fbclid'] ?? null, 300),
            'utmContent' => self::clip($tracking['utm_content'] ?? null, 200),
        ];
    }

    private static function insert(Tx $tx, string $tenantId, string $leadId, string $type, array $t): void
    {
        $tx->insertIgnore('marketing_attribution', [
            'tenant_id' => $tenantId, 'lead_id' => $leadId, 'touch_type' => $type, 'source' => $t['source'], 'campaign' => $t['campaign'] ?? null, 'medium' => $t['medium'] ?? null,
            'ad_group' => $t['adGroup'] ?? null, 'keyword' => $t['keyword'] ?? null, 'click_id' => $t['clickId'] ?? null, 'utm_content' => $t['utmContent'] ?? null, 'is_direct' => $t['source'] === 'direct' ? 1 : 0,
        ]);
    }

    /** Called by lead creation, inside its transaction. Writing twice is harmless (the unique key keeps the first). */
    public static function recordFirst(Tx $tx, string $tenantId, string $leadId, array $t): void
    {
        self::insert($tx, $tenantId, $leadId, 'first_touch', $t);
    }

    /** Called when a person who already has an open lead enquires again. */
    public static function recordLater(Tx $tx, string $tenantId, string $leadId, array $t): void
    {
        self::insert($tx, $tenantId, $leadId, 'later_touch', $t);
    }
}
