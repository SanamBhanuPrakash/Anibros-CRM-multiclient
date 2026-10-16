<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Input validation (the PHP counterpart of the zod schemas of the Node API).
 *
 *   $schema = V::obj(['email' => V::str()->trim()->lower()->email()->max(254), 'role' => V::enum(['a','b'])->default('a')]);
 *   $clean  = $schema->parseOrFail($input, 'body');          // throws Errors::validation with paths like "body.email"
 *
 * Semantics follow zod: unknown object keys are stripped (strict() rejects them), a missing key is an error unless the field is
 * optional() (key omitted) or has a default(), null is only accepted by nullable(), trim()/lower() run before the checks.
 */
final class V
{
    private const NONE = "\0none";

    private string $kind;
    private bool $optional = false;
    private bool $nullable = false;
    private mixed $default = self::NONE;
    private bool $coerce = false;
    private bool $trim = false;
    private ?string $case = null;
    private ?int $min = null;
    private ?int $max = null;
    private ?float $fmin = null;
    private ?float $fmax = null;
    private bool $isInt = false;
    private ?string $pattern = null;
    private ?string $patternMsg = null;
    private ?string $format = null;
    /** @var array<int,string|int|float|bool> */
    private array $values = [];
    private ?V $item = null;
    /** @var array<string,V> */
    private array $shape = [];
    private bool $strict = false;
    private bool $passthrough = false;
    /** @var array<int,V> */
    private array $options = [];
    /** @var array<int,array{0:callable,1:string,2:?string}> */
    private array $refines = [];
    private mixed $transformFn = null;
    private ?string $describe = null;

    private function __construct(string $kind)
    {
        $this->kind = $kind;
    }

    // ── constructors ──────────────────────────────────────────────────────────────────────────────────────────────

    public static function str(): self { return new self('str'); }
    public static function int(): self { $v = new self('num'); $v->isInt = true; return $v; }
    public static function num(): self { return new self('num'); }
    public static function bool(): self { return new self('bool'); }
    /** Query-string numbers / booleans arrive as strings. */
    public static function coerceInt(): self { $v = self::int(); $v->coerce = true; return $v; }
    public static function coerceNum(): self { $v = self::num(); $v->coerce = true; return $v; }
    public static function coerceBool(): self { $v = self::bool(); $v->coerce = true; return $v; }
    public static function uuid(): self { $v = new self('str'); $v->format = 'uuid'; return $v; }
    public static function email(): self { $v = new self('str'); $v->format = 'email'; return $v; }
    public static function url(): self { $v = new self('str'); $v->format = 'url'; return $v; }
    /** 'YYYY-MM-DD' kept as a string. */
    public static function dateStr(): self { $v = new self('str'); $v->format = 'date'; return $v; }
    /** ISO-8601 date-time (with or without zone, or a plain date): returns a DateTimeImmutable in UTC. */
    public static function datetime(): self { return new self('datetime'); }
    /** @param array<int,string|int|float|bool> $values */
    public static function enum(array $values): self { $v = new self('enum'); $v->values = array_values($values); return $v; }
    public static function literal(string|int|bool $value): self { $v = new self('enum'); $v->values = [$value]; return $v; }
    public static function arr(V $item): self { $v = new self('arr'); $v->item = $item; return $v; }
    /** @param array<string,V> $shape */
    public static function obj(array $shape): self { $v = new self('obj'); $v->shape = $shape; return $v; }
    /** Free-form object with value schema (z.record). */
    public static function record(V $value): self { $v = new self('record'); $v->item = $value; return $v; }
    public static function any(): self { return new self('any'); }
    /** @param array<int,V> $options */
    public static function union(array $options): self { $v = new self('union'); $v->options = $options; return $v; }

    // ── modifiers ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function optional(): self { $this->optional = true; return $this; }
    public function nullable(): self { $this->nullable = true; return $this; }
    public function nullish(): self { $this->optional = true; $this->nullable = true; return $this; }
    public function default(mixed $v): self { $this->default = $v; return $this; }
    /** Arrays: accept a lone scalar (query string) as a one-element list. */
    public function coerced(): self { $this->coerce = true; return $this; }
    public function trim(): self { $this->trim = true; return $this; }
    public function lower(): self { $this->case = 'lower'; return $this; }
    public function upper(): self { $this->case = 'upper'; return $this; }
    public function min(int|float $n): self { if ($this->kind === 'num') $this->fmin = (float) $n; else $this->min = (int) $n; return $this; }
    public function max(int|float $n): self { if ($this->kind === 'num') $this->fmax = (float) $n; else $this->max = (int) $n; return $this; }
    public function regex(string $pattern, ?string $msg = null): self { $this->pattern = $pattern; $this->patternMsg = $msg; return $this; }
    public function strict(): self { $this->strict = true; return $this; }
    public function passthrough(): self { $this->passthrough = true; return $this; }
    public function describe(string $d): self { $this->describe = $d; return $this; }
    /** @param callable(mixed):bool $fn */
    public function refine(callable $fn, string $message, ?string $path = null): self { $this->refines[] = [$fn, $message, $path]; return $this; }
    /** @param callable(mixed):mixed $fn applied to the parsed value */
    public function transform(callable $fn): self { $this->transformFn = $fn; return $this; }

    /** All keys of an object schema optional (z.partial). */
    public function partial(): self
    {
        $c = clone $this;
        foreach ($c->shape as $k => $s) { $s = clone $s; $s->optional = true; $c->shape[$k] = $s; }
        return $c;
    }

    /** @return array<string,V> */
    public function shape(): array { return $this->shape; }

    // ── parsing ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @throws AppError */
    public function parseOrFail(mixed $value, string $where): mixed
    {
        $issues = [];
        $out = $this->run($value, $where, $issues, true);
        if ($issues) throw Errors::validation($issues, "Invalid $where");
        return $out;
    }

    /**
     * @param array<int,array{path:string,message:string,code?:string}> $issues
     * @return mixed the parsed value, or null when invalid (issues filled); use $present to detect "missing optional"
     */
    public function run(mixed $v, string $path, array &$issues, bool $present, bool &$omit = false): mixed
    {
        $omit = false;
        if ($v === self::NONE || !$present) {
            if ($this->default !== self::NONE) return $this->default;
            if ($this->optional) { $omit = true; return null; }
            $issues[] = ['path' => $path, 'message' => 'Invalid input: expected ' . $this->label() . ', received undefined', 'code' => 'invalid_type'];
            return null;
        }
        if ($v === null) {
            if ($this->nullable) return null;
            $issues[] = ['path' => $path, 'message' => 'Invalid input: expected ' . $this->label() . ', received null', 'code' => 'invalid_type'];
            return null;
        }
        $before = count($issues);
        $out = match ($this->kind) {
            'str' => $this->parseStr($v, $path, $issues),
            'num' => $this->parseNum($v, $path, $issues),
            'bool' => $this->parseBool($v, $path, $issues),
            'enum' => $this->parseEnum($v, $path, $issues),
            'arr' => $this->parseArr($v, $path, $issues),
            'obj' => $this->parseObj($v, $path, $issues),
            'record' => $this->parseRecord($v, $path, $issues),
            'datetime' => $this->parseDatetime($v, $path, $issues),
            'union' => $this->parseUnion($v, $path, $issues),
            default => $v,
        };
        if (count($issues) === $before) {
            foreach ($this->refines as [$fn, $msg, $rp]) {
                if (!$fn($out)) $issues[] = ['path' => $rp !== null ? ($path === '' ? $rp : "$path.$rp") : $path, 'message' => $msg, 'code' => 'custom'];
            }
            if ($this->transformFn !== null && count($issues) === $before) $out = ($this->transformFn)($out);
        }
        return $out;
    }

    private function label(): string
    {
        return match ($this->kind) { 'str' => 'string', 'num' => $this->isInt ? 'int' : 'number', 'bool' => 'boolean', 'arr' => 'array', 'obj', 'record' => 'object', default => $this->kind };
    }

    private static function typeName(mixed $v): string
    {
        return match (true) { is_string($v) => 'string', is_int($v), is_float($v) => 'number', is_bool($v) => 'boolean', is_array($v) => array_is_list($v) ? 'array' : 'object', default => 'unknown' };
    }

    private function bad(string $path, mixed $v, array &$issues): null
    {
        $issues[] = ['path' => $path, 'message' => 'Invalid input: expected ' . $this->label() . ', received ' . self::typeName($v), 'code' => 'invalid_type'];
        return null;
    }

    private function parseStr(mixed $v, string $path, array &$issues): ?string
    {
        if (!is_string($v)) return $this->bad($path, $v, $issues);
        if ($this->trim) $v = trim($v);
        if ($this->case === 'lower') $v = mb_strtolower($v);
        if ($this->case === 'upper') $v = mb_strtoupper($v);
        $len = mb_strlen($v);
        $before = count($issues);
        if ($this->min !== null && $len < $this->min) $issues[] = ['path' => $path, 'message' => "Too small: expected string to have >={$this->min} characters", 'code' => 'too_small'];
        if ($this->max !== null && $len > $this->max) $issues[] = ['path' => $path, 'message' => "Too big: expected string to have <={$this->max} characters", 'code' => 'too_big'];
        if ($this->pattern !== null && preg_match($this->pattern, $v) !== 1) $issues[] = ['path' => $path, 'message' => $this->patternMsg ?? 'Invalid string: must match pattern', 'code' => 'invalid_format'];
        if ($this->format !== null && $len > 0) {
            $ok = match ($this->format) {
                'uuid' => Ids::isUuid($v),
                'email' => (bool) filter_var($v, FILTER_VALIDATE_EMAIL) && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v) === 1,
                'url' => (bool) filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) === 1,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)),
                default => true,
            };
            if (!$ok) $issues[] = ['path' => $path, 'message' => 'Invalid ' . $this->format, 'code' => 'invalid_format'];
        } elseif ($this->format !== null) {
            $issues[] = ['path' => $path, 'message' => 'Invalid ' . $this->format, 'code' => 'invalid_format'];
        }
        if ($this->format === 'uuid') $v = strtolower($v);
        return count($issues) === $before ? $v : null;
    }

    private function parseNum(mixed $v, string $path, array &$issues): int|float|null
    {
        if ($this->coerce && is_string($v)) {
            $s = trim($v);
            if ($s === '' || !is_numeric($s)) { $issues[] = ['path' => $path, 'message' => 'Invalid input: expected ' . $this->label() . ', received NaN', 'code' => 'invalid_type']; return null; }
            $v = $s + 0;
        } elseif ($this->coerce && is_bool($v)) {
            $v = $v ? 1 : 0;
        }
        if (!is_int($v) && !is_float($v)) return $this->bad($path, $v, $issues);
        if (is_float($v) && (is_nan($v) || is_infinite($v))) return $this->bad($path, $v, $issues);
        if ($this->isInt && is_float($v)) {
            if ($v != floor($v)) { $issues[] = ['path' => $path, 'message' => 'Invalid input: expected int, received float', 'code' => 'invalid_type']; return null; }
            $v = (int) $v;
        }
        $before = count($issues);
        if ($this->fmin !== null && $v < $this->fmin) $issues[] = ['path' => $path, 'message' => 'Too small: expected number to be >=' . $this->numLabel($this->fmin), 'code' => 'too_small'];
        if ($this->fmax !== null && $v > $this->fmax) $issues[] = ['path' => $path, 'message' => 'Too big: expected number to be <=' . $this->numLabel($this->fmax), 'code' => 'too_big'];
        return count($issues) === $before ? $v : null;
    }

    private function numLabel(float $n): string { return $n == floor($n) ? (string) (int) $n : (string) $n; }

    private function parseBool(mixed $v, string $path, array &$issues): ?bool
    {
        if ($this->coerce) {
            if (is_string($v)) {
                $l = strtolower(trim($v));
                if (in_array($l, ['true', '1', 'yes', 'on'], true)) return true;
                if (in_array($l, ['false', '0', 'no', 'off', ''], true)) return false;
            } elseif (is_int($v) && ($v === 0 || $v === 1)) return $v === 1;
        }
        if (!is_bool($v)) return $this->bad($path, $v, $issues);
        return $v;
    }

    private function parseEnum(mixed $v, string $path, array &$issues): string|int|float|bool|null
    {
        if (is_string($v) && $this->trim) $v = trim($v);
        if (!in_array($v, $this->values, true)) {
            $opts = implode(', ', array_map(static fn($x) => json_encode($x), $this->values));
            $issues[] = ['path' => $path, 'message' => 'Invalid option: expected one of ' . $opts, 'code' => 'invalid_value'];
            return null;
        }
        return $v;
    }

    private function parseArr(mixed $v, string $path, array &$issues): ?array
    {
        // A query string gives a single string for ?tag=a: with coerced() a scalar becomes a one-element list
        if ($this->coerce && is_string($v)) $v = [$v];
        if (!is_array($v) || ($v !== [] && !array_is_list($v))) return $this->bad($path, $v, $issues);
        $before = count($issues);
        if ($this->min !== null && count($v) < $this->min) $issues[] = ['path' => $path, 'message' => "Too small: expected array to have >={$this->min} items", 'code' => 'too_small'];
        if ($this->max !== null && count($v) > $this->max) $issues[] = ['path' => $path, 'message' => "Too big: expected array to have <={$this->max} items", 'code' => 'too_big'];
        $out = [];
        foreach ($v as $i => $x) {
            $r = $this->item->run($x, $path === '' ? (string) $i : "$path.$i", $issues, true, $omit);
            $out[] = $r;
        }
        return count($issues) === $before ? $out : null;
    }

    private function parseObj(mixed $v, string $path, array &$issues): ?array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) return $this->bad($path, $v, $issues);
        $before = count($issues);
        $out = [];
        foreach ($this->shape as $k => $schema) {
            $has = array_key_exists($k, $v);
            $omit = false;
            $r = $schema->run($has ? $v[$k] : self::NONE, $path === '' ? $k : "$path.$k", $issues, $has, $omit);
            if (!$omit) $out[$k] = $r;
        }
        if ($this->strict) {
            foreach (array_keys($v) as $k) {
                if (!isset($this->shape[$k])) $issues[] = ['path' => $path, 'message' => 'Unrecognized key: "' . $k . '"', 'code' => 'unrecognized_keys'];
            }
        } elseif ($this->passthrough) {
            foreach ($v as $k => $x) if (!isset($this->shape[$k])) $out[$k] = $x;
        }
        return count($issues) === $before ? $out : null;
    }

    private function parseRecord(mixed $v, string $path, array &$issues): ?array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) return $this->bad($path, $v, $issues);
        $before = count($issues);
        $out = [];
        foreach ($v as $k => $x) $out[$k] = $this->item->run($x, $path === '' ? (string) $k : "$path.$k", $issues, true, $omit);
        return count($issues) === $before ? $out : null;
    }

    private function parseDatetime(mixed $v, string $path, array &$issues): ?\DateTimeImmutable
    {
        if (!is_string($v) || trim($v) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', trim($v))) {
            $issues[] = ['path' => $path, 'message' => 'Invalid ISO datetime', 'code' => 'invalid_format'];
            return null;
        }
        $d = Time::parse(trim($v));
        if (!$d) { $issues[] = ['path' => $path, 'message' => 'Invalid ISO datetime', 'code' => 'invalid_format']; return null; }
        return $d;
    }

    private function parseUnion(mixed $v, string $path, array &$issues): mixed
    {
        foreach ($this->options as $o) {
            $local = [];
            $r = $o->run($v, $path, $local, true, $omit);
            if (!$local) return $r;
        }
        $issues[] = ['path' => $path, 'message' => 'Invalid input', 'code' => 'invalid_union'];
        return null;
    }
}
