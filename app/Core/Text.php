<?php
declare(strict_types=1);

namespace App\Core;

/** Small text helpers shared by modules. */
final class Text
{
    /** Strip accents ("é" => "e"); characters with no ASCII form are dropped. Works with or without the intl extension. */
    public static function asciiFold(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
            if ($n !== false) return (string) preg_replace('/\p{Mn}+/u', '', $n);
        }
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t === false ? (string) preg_replace('/[^\x20-\x7E]/', '', $s) : $t;
    }

    /** "Smile Dental Clinic" => "smile-dental-clinic" (at most $max characters, never empty). */
    public static function slugify(string $s, int $max = 40, string $fallback = 'item', string $sep = '-'): string
    {
        $s = self::asciiFold(mb_strtolower($s));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', $sep, $s), $sep);
        $s = rtrim(substr($s, 0, $max), $sep);
        return $s !== '' ? $s : $fallback;
    }

    /** Case-insensitive "contains" without regex pitfalls. */
    public static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || mb_stripos($haystack, $needle) !== false;
    }
}
