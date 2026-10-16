<?php
declare(strict_types=1);

namespace App\Modules\CustomFields;

use App\Core\Text;

/**
 * Custom lead fields: the rules that turn what a person typed into what is stored (pure functions, no database).
 *
 * Each client defines its own fields; the definitions are the schema, the values live on the lead as JSON.
 * Stored shapes: text/select/url/email => string, number => number, boolean => true/false, date => "YYYY-MM-DD", multi_select => string[] of option VALUES.
 * An empty value is "not set": the key is simply absent.
 */
final class FieldRules
{
    public const TYPES = ['text', 'number', 'date', 'boolean', 'select', 'multi_select', 'url', 'email'];
    public const MAX_FIELDS_PER_CLIENT = 50;
    public const MAX_OPTIONS = 50;
    public const KEY_RE = '/^[a-z][a-z0-9_]{0,39}$/';

    private static function slug(string $s, int $max): string
    {
        $s = Text::asciiFold(mb_strtolower($s));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
        return rtrim(substr($s, 0, $max), '_');
    }

    /** "Treatment interested in" => treatment_interested_in; unique among $taken; always a valid key. @param array<string,mixed> $taken keys as array keys */
    public static function keyFromLabel(string $label, array $taken): string
    {
        $base = self::slug($label, 36);
        if ($base === '') $base = 'field';
        if (!preg_match('/^[a-z]/', $base)) $base = substr('f_' . $base, 0, 36);
        $key = $base;
        for ($n = 2; isset($taken[$key]); $n++) $key = substr($base, 0, 36 - strlen((string) $n) - 1) . '_' . $n;
        return $key;
    }

    /**
     * Options may be given as plain labels or as {value,label}. Values are stable identifiers; labels can be renamed later.
     *
     * @param array<int,string|array{value?:string,label:string}> $input
     * @return array{options:array<int,array{value:string,label:string}>,problems:array<int,array{path:string,message:string}>}
     */
    public static function normalizeOptions(array $input): array
    {
        $problems = [];
        $options = [];
        $seen = [];
        foreach (array_values($input) as $i => $raw) {
            $label = trim(is_string($raw) ? $raw : (string) ($raw['label'] ?? ''));
            if ($label === '') { $problems[] = ['path' => "body.options.$i", 'message' => 'An option needs a name']; continue; }
            if (mb_strlen($label) > 80) $problems[] = ['path' => "body.options.$i", 'message' => 'Option names are at most 80 characters'];
            $given = is_string($raw) ? null : (isset($raw['value']) ? trim((string) $raw['value']) : null);
            $given = $given === '' ? null : $given;
            $value = $given ?? (self::slug($label, 40) ?: 'option_' . ($i + 1));
            if ($given && !preg_match('/^[a-z0-9][a-z0-9_]{0,39}$/', $given)) $problems[] = ['path' => "body.options.$i.value", 'message' => 'Use lowercase letters, digits and underscores'];
            for ($n = 2; isset($seen[$value]) && !$given; $n++) $value = self::slug($label, 36) . '_' . $n;
            if (isset($seen[$value])) $problems[] = ['path' => "body.options.$i", 'message' => "Two options share the value “{$value}”"];
            foreach ($options as $o) if (mb_strtolower($o['label']) === mb_strtolower($label)) { $problems[] = ['path' => "body.options.$i", 'message' => "“{$label}” is listed twice"]; break; }
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => $label];
        }
        if (count($options) > self::MAX_OPTIONS) $problems[] = ['path' => 'body.options', 'message' => 'At most ' . self::MAX_OPTIONS . ' options'];
        return ['options' => $options, 'problems' => $problems];
    }

    private static function isBlank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '') || (is_array($v) && $v === []);
    }

    private static function validDate(string $s): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return $y >= 1900 && $y <= 2200 && checkdate($mo, $d, $y);
    }

    /**
     * Normalise one value for one field. A null value in the result means "blank (clear it)".
     *
     * @param array{type:string,options:array,label:string} $def
     * @return array{ok:true,value:string|int|float|bool|array|null}|array{ok:false,message:string}
     */
    public static function normalizeValue(array $def, mixed $raw): array
    {
        if (self::isBlank($raw)) return ['ok' => true, 'value' => null];
        $fail = static fn(string $m): array => ['ok' => false, 'message' => $m];
        // Only a multi-select takes a list. Anything list- or object-shaped sent to another type would otherwise be silently turned into text.
        if (is_array($raw) && $def['type'] !== 'multi_select') return $fail('Enter a single value');
        $findOption = static function (string $s) use ($def) {
            foreach ($def['options'] as $o) if ($o['value'] === $s) return $o;
            foreach ($def['options'] as $o) if (mb_strtolower($o['label']) === mb_strtolower($s)) return $o;
            return null;
        };
        $labels = static fn() => implode(', ', array_map(static fn($x) => $x['label'], $def['options']));
        switch ($def['type']) {
            case 'text':
                if (!is_string($raw) && !is_int($raw) && !is_float($raw)) return $fail('Enter text');
                $s = trim((string) $raw);
                return mb_strlen($s) > 500 ? $fail('At most 500 characters') : ['ok' => true, 'value' => $s];
            case 'number':
                if (is_int($raw) || is_float($raw)) $n = (float) $raw;
                elseif (is_string($raw) && trim($raw) !== '' && is_numeric(trim(str_replace(',', '', $raw)))) $n = (float) trim(str_replace(',', '', $raw));
                else return $fail('Enter a number');
                if (abs($n) > 1e13) return $fail('That number is too large');
                return ['ok' => true, 'value' => $n == floor($n) ? (int) $n : $n];
            case 'date':
                $s = is_string($raw) ? substr(trim($raw), 0, 10) : '';
                return self::validDate($s) ? ['ok' => true, 'value' => $s] : $fail('Use a date like 2026-10-31');
            case 'boolean':
                if (is_bool($raw)) return ['ok' => true, 'value' => $raw];
                $s = strtolower(trim((string) $raw));
                if (in_array($s, ['true', 'yes', 'y', '1'], true)) return ['ok' => true, 'value' => true];
                if (in_array($s, ['false', 'no', 'n', '0'], true)) return ['ok' => true, 'value' => false];
                return $fail('Choose yes or no');
            case 'select':
                $o = $findOption(trim((string) $raw));
                return $o ? ['ok' => true, 'value' => $o['value']] : $fail('Choose one of: ' . $labels());
            case 'multi_select':
                $list = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/[,;]/', $raw) : null);
                if ($list === null) return $fail('Choose one or more options');
                $out = [];
                foreach ($list as $item) {
                    $s = trim((string) $item);
                    if ($s === '') continue;
                    $o = $findOption($s);
                    if (!$o) return $fail("“{$s}” is not one of: " . $labels());
                    if (!in_array($o['value'], $out, true)) $out[] = $o['value'];
                }
                return ['ok' => true, 'value' => $out ?: null];
            case 'url':
                $s = trim((string) $raw);
                $p = parse_url($s);
                if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true) || strlen($s) > 500) return $fail('Enter a web address starting with http:// or https://');
                return ['ok' => true, 'value' => $s];
            case 'email':
                $s = strtolower(trim((string) $raw));
                return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $s) && strlen($s) <= 254 ? ['ok' => true, 'value' => $s] : $fail('Enter an e-mail address');
        }
        return $fail('Unsupported field type');
    }

    /**
     * Check $input against the client's definitions.
     *  create: unknown or hidden keys are errors; defaults fill in fields nobody answered; required fields must be present.
     *  patch:  only the keys sent are touched; blanking a required field is an error.
     *
     * @param array<int,array<string,mixed>> $defs
     * @param array{mode:string,enforceRequired:bool} $opts
     * @return array{set:array<string,mixed>,clear:string[],problems:array<int,array{path:string,message:string}>}
     */
    public static function validate(array $defs, ?array $input, array $opts): array
    {
        $byKey = [];
        foreach ($defs as $d) $byKey[$d['key']] = $d;
        $set = [];
        $clear = [];
        $problems = [];
        foreach (($input ?? []) as $key => $raw) {
            $path = "body.customFields.$key";
            $def = $byKey[$key] ?? null;
            if (!$def) { $problems[] = ['path' => $path, 'message' => "“{$key}” is not a field of this client. Add it under Custom fields first"]; continue; }
            if (!$def['visible']) { $problems[] = ['path' => $path, 'message' => "“{$def['label']}” is hidden and can no longer be changed"]; continue; }
            $r = self::normalizeValue($def, $raw);
            if (!$r['ok']) { $problems[] = ['path' => $path, 'message' => $def['label'] . ': ' . $r['message']]; continue; }
            if ($r['value'] === null) {
                if ($opts['mode'] === 'patch') {
                    if ($def['required'] && $opts['enforceRequired']) $problems[] = ['path' => $path, 'message' => $def['label'] . ' is required'];
                    else $clear[] = $key;
                }
            } else $set[$key] = $r['value'];
        }
        if ($opts['mode'] === 'create') {
            foreach ($defs as $def) {
                if (!$def['visible'] || array_key_exists($def['key'], $set)) continue;
                if ($def['defaultValue'] !== null) {
                    $d = self::normalizeValue($def, $def['defaultValue']);
                    if ($d['ok'] && $d['value'] !== null) { $set[$def['key']] = $d['value']; continue; }
                }
                if ($def['required'] && $opts['enforceRequired']) $problems[] = ['path' => 'body.customFields.' . $def['key'], 'message' => $def['label'] . ' is required'];
            }
        }
        return ['set' => $set, 'clear' => $clear, 'problems' => $problems];
    }

    /** Human-readable text for a stored value (export, screens): option labels instead of codes, Yes/No instead of true/false. */
    public static function display(array $def, mixed $stored): string|int|float|null
    {
        if ($stored === null) return null;
        $label = static function ($v) use ($def) {
            foreach ($def['options'] as $o) if ($o['value'] === $v) return $o['label'];
            return (string) $v;
        };
        if ($def['type'] === 'boolean') return $stored === true ? 'Yes' : ($stored === false ? 'No' : (string) $stored);
        if ($def['type'] === 'select') return $label($stored);
        if ($def['type'] === 'multi_select') return is_array($stored) ? implode('; ', array_map($label, $stored)) : (string) $stored;
        if ($def['type'] === 'number') return is_numeric($stored) ? $stored + 0 : null;
        return (string) $stored;
    }

    /** Starter fields a new client gets for its industry (ordinary fields: renamed, hidden or extended later). @return array<int,array{label:string,type:string,options?:string[],helpText?:string}> */
    public static function starters(string $industry): array
    {
        $time = ['Morning', 'Afternoon', 'Evening'];
        $s = static fn(string $label, string $type, ?array $options = null, ?string $help = null): array => array_filter(['label' => $label, 'type' => $type, 'options' => $options, 'helpText' => $help], static fn($v) => $v !== null);
        $all = [
            'healthcare' => [$s('Service needed', 'select', ['General consultation', 'Dental', 'Skin / hair', 'Bones / joints', 'Women’s health', 'Children', 'Eye', 'Other']), $s('Preferred date', 'date'), $s('Preferred time', 'select', $time), $s('Existing patient', 'boolean')],
            'restaurant' => [$s('Party size', 'number', null, 'Number of guests'), $s('Occasion', 'select', ['Birthday', 'Anniversary', 'Family', 'Corporate', 'Casual', 'Other']), $s('Preferred date', 'date'), $s('Vegetarian only', 'boolean')],
            'finance' => [$s('Product interest', 'select', ['Home loan', 'Personal loan', 'Car loan', 'Business loan', 'Credit card', 'Savings account', 'Insurance', 'Other']), $s('Monthly income', 'number'), $s('Employment type', 'select', ['Salaried', 'Self-employed', 'Business owner', 'Other']), $s('City', 'text')],
            'retail' => [$s('Product interest', 'text'), $s('Budget', 'number'), $s('Preferred store', 'text'), $s('Visit date', 'date')],
            'education' => [$s('Course interest', 'text'), $s('Highest qualification', 'select', ['10th', '12th', 'Graduate', 'Postgraduate', 'Other']), $s('Preferred batch', 'select', ['Morning', 'Evening', 'Weekend', 'Online']), $s('Parent / guardian name', 'text')],
            'beauty_wellness' => [$s('Service interested in', 'select', ['Hair', 'Skin', 'Spa / massage', 'Bridal', 'Nails', 'Other']), $s('Preferred date', 'date'), $s('Preferred time', 'select', $time), $s('First visit', 'boolean')],
            'automotive' => [$s('Model interest', 'text'), $s('Fuel type', 'select', ['Petrol', 'Diesel', 'Electric', 'CNG', 'Hybrid']), $s('Budget', 'number'), $s('Test drive wanted', 'boolean'), $s('Exchange old vehicle', 'boolean')],
            'travel' => [$s('Destination', 'text'), $s('Travel date', 'date'), $s('Travellers', 'number'), $s('Budget', 'number'), $s('Trip type', 'select', ['Leisure', 'Honeymoon', 'Family', 'Corporate', 'Pilgrimage', 'Other'])],
            'professional_services' => [$s('Service needed', 'text'), $s('Company name', 'text'), $s('Company size', 'select', ['1–10', '11–50', '51–200', '200+']), $s('Timeline', 'select', ['Immediately', 'Within a month', '1–3 months', 'Just exploring'])],
        ];
        return $all[$industry] ?? [];
    }
}
