<?php
declare(strict_types=1);

namespace App\Modules\Contacts;

/**
 * Identity keys for deduplication. Two records are "the same person" when they share a normalised key, so the normalisation must be strict
 * enough to avoid false merges and tolerant enough to catch real duplicates:
 *   +91 98765-43210, 098765 43210 and 9876543210 (default country IN) are all  +919876543210.
 *
 * Phone numbers are normalised to E.164 without an external library: international numbers ("+..." or "00...") are taken as typed, national
 * numbers get the calling code of the workspace's default country after the national trunk prefix ("0") is removed. The checks are length based
 * (like libphonenumber's isPossible), not a full numbering-plan validation.
 */
final class Normalize
{
    /** ISO country => [calling code, national length min, national length max, trunk prefix to strip] */
    private const COUNTRIES = [
        'IN' => ['91', 10, 10, '0'], 'US' => ['1', 10, 10, '1'], 'CA' => ['1', 10, 10, '1'], 'GB' => ['44', 9, 10, '0'], 'AE' => ['971', 8, 9, '0'],
        'AU' => ['61', 9, 9, '0'], 'NZ' => ['64', 8, 10, '0'], 'SG' => ['65', 8, 8, ''], 'MY' => ['60', 8, 10, '0'], 'LK' => ['94', 9, 9, '0'],
        'NP' => ['977', 8, 10, '0'], 'BD' => ['880', 10, 10, '0'], 'PK' => ['92', 10, 10, '0'], 'SA' => ['966', 8, 9, '0'], 'QA' => ['974', 8, 8, ''],
        'KW' => ['965', 8, 8, ''], 'OM' => ['968', 8, 8, ''], 'BH' => ['973', 8, 8, ''], 'DE' => ['49', 7, 11, '0'], 'FR' => ['33', 9, 9, '0'],
        'ES' => ['34', 9, 9, ''], 'IT' => ['39', 6, 11, ''], 'NL' => ['31', 9, 9, '0'], 'IE' => ['353', 8, 9, '0'], 'ZA' => ['27', 9, 9, '0'],
        'NG' => ['234', 8, 10, '0'], 'KE' => ['254', 9, 9, '0'], 'EG' => ['20', 9, 10, '0'], 'TR' => ['90', 10, 10, '0'], 'BR' => ['55', 10, 11, '0'],
        'MX' => ['52', 10, 10, ''], 'AR' => ['54', 10, 11, '0'], 'CN' => ['86', 11, 11, '0'], 'JP' => ['81', 9, 10, '0'], 'KR' => ['82', 8, 10, '0'],
        'HK' => ['852', 8, 8, ''], 'TH' => ['66', 8, 9, '0'], 'ID' => ['62', 8, 12, '0'], 'PH' => ['63', 10, 10, '0'], 'VN' => ['84', 9, 10, '0'],
        'RU' => ['7', 10, 10, '8'], 'CH' => ['41', 9, 9, '0'], 'SE' => ['46', 7, 9, '0'], 'NO' => ['47', 8, 8, ''], 'DK' => ['45', 8, 8, ''],
        'PL' => ['48', 9, 9, ''], 'PT' => ['351', 9, 9, ''], 'IL' => ['972', 8, 9, '0'],
    ];

    public static function email(?string $raw): ?string
    {
        $v = mb_strtolower(trim((string) $raw));
        if ($v === '' || strlen($v) > 254 || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v)) return null;
        return $v;
    }

    public static function phone(?string $raw, string $defaultCountry = 'IN'): ?string
    {
        $v = trim((string) $raw);
        if ($v === '') return null;
        $hasPlus = str_starts_with($v, '+');
        $digits = preg_replace('/\D+/', '', $v) ?? '';
        if ($digits === '') return null;
        if ($hasPlus || str_starts_with($digits, '00') && !$hasPlus && strlen($digits) > 10) {
            if (!$hasPlus) $digits = substr($digits, 2);
            return self::validInternational($digits);
        }
        $c = self::COUNTRIES[strtoupper($defaultCountry)] ?? self::COUNTRIES['IN'];
        [$cc, $min, $max, $trunk] = $c;
        // typed with the country code but without "+": 919876543210
        if (str_starts_with($digits, $cc) && strlen($digits) - strlen($cc) >= $min && strlen($digits) - strlen($cc) <= $max && strlen($digits) > $max) {
            return self::validInternational($digits);
        }
        if ($trunk !== '' && str_starts_with($digits, $trunk) && strlen($digits) - strlen($trunk) >= $min) $digits = substr($digits, strlen($trunk));
        if (strlen($digits) < $min || strlen($digits) > $max) return null;
        return '+' . $cc . $digits;
    }

    private static function validInternational(string $digits): ?string
    {
        if ($digits === '' || $digits[0] === '0' || strlen($digits) < 8 || strlen($digits) > 15) return null;
        return '+' . $digits;
    }

    /** Tags: trimmed, lower-case, de-duplicated, bounded. Order of first appearance is kept. @return string[] */
    public static function tags(?array $tags): array
    {
        $out = [];
        foreach ($tags ?? [] as $t) {
            $v = mb_substr((string) preg_replace('/\s+/', '-', mb_strtolower(trim((string) $t))), 0, 40);
            if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    /** Escape `%`, `_` and `\` so user text is matched literally by LIKE. */
    public static function escapeLike(string $s): string
    {
        return (string) preg_replace('/[\\\\%_]/', '\\\\$0', $s);
    }

    public static function blank(?string $v): ?string
    {
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }
}
