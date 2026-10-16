<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Errors;
use App\Core\Json;
use App\Core\Tx;

/**
 * Whole local days in the WORKSPACE time zone, shared by the Dashboard, Attribution and Reports so every number and the list behind it
 * use the same boundaries (a daylight-saving day is 23 or 25 hours long, never assumed to be 24).
 */
final class Range
{
    private const MAX_DAYS = 366;

    private static function validDate(?string $v): bool
    {
        return $v !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Whole local days in the workspace time zone: [from 00:00, to + 1 day 00:00). Defaults to the last 30 days including today.
     *
     * @param array{from?:?string,to?:?string} $q
     * @return array{from:string,to:string,timezone:string,days:int,start:\DateTimeImmutable,end:\DateTimeImmutable,currency:string}
     */
    public static function resolve(Tx $tx, string $tenantId, array $q): array
    {
        $t = $tx->one('SELECT timezone, settings FROM tenants WHERE id = ?', [$tenantId]);
        $timezone = ($t['timezone'] ?? '') ?: 'UTC';
        try { $zone = new \DateTimeZone($timezone); } catch (\Throwable) { $zone = new \DateTimeZone('UTC'); $timezone = 'UTC'; }
        $settings = is_string($t['settings'] ?? null) ? Json::dec($t['settings'], []) : [];
        $currency = isset($settings['currency']) && is_string($settings['currency']) ? $settings['currency'] : 'INR';
        foreach (['from', 'to'] as $k) {
            if (isset($q[$k]) && !self::validDate($q[$k])) throw Errors::validation([['path' => "query.$k", 'message' => 'Use a date like 2026-10-14']]);
        }
        $to = $q['to'] ?? (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
        $from = $q['from'] ?? (new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC')))->modify('-29 days')->format('Y-m-d');
        $utc = new \DateTimeZone('UTC');
        $days = (int) round((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400) + 1;
        if ($days < 1) throw Errors::validation([['path' => 'query.to', 'message' => 'The end date must not be before the start date']]);
        if ($days > self::MAX_DAYS) throw Errors::validation([['path' => 'query.to', 'message' => 'Choose a range of at most ' . self::MAX_DAYS . ' days']]);
        return [
            'from' => $from, 'to' => $to, 'timezone' => $timezone, 'days' => $days, 'currency' => $currency,
            'start' => (new \DateTimeImmutable($from . ' 00:00:00', $zone))->setTimezone($utc),
            'end' => (new \DateTimeImmutable($to . ' 00:00:00', $zone))->modify('+1 day')->setTimezone($utc),
        ];
    }

    /** The part of a range that is shown to the caller. */
    public static function pub(array $r): array
    {
        return ['from' => $r['from'], 'to' => $r['to'], 'timezone' => $r['timezone'], 'days' => $r['days']];
    }
}
