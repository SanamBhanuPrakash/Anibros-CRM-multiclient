<?php
declare(strict_types=1);

namespace App\Core;

final class CsvError extends \RuntimeException
{
}

/**
 * Minimal RFC 4180 CSV reader / writer (no dependency, bounded).
 *
 * Reader: BOM, CRLF/LF, quoted fields with embedded newlines and "" escapes, delimiter sniffed from the header (comma, semicolon or tab: Excel in many
 * locales saves with semicolons). Unterminated quotes are an error rather than silently swallowing the rest of the file.
 * Writer: every cell a spreadsheet could interpret as a formula (= + - @ TAB CR) is prefixed with an apostrophe, which prevents CSV/formula injection when
 * an exported file is opened in Excel or Sheets.
 */
final class Csv
{
    /** @return array{header:string[],rows:array<int,string[]>} */
    public static function parse(string $input, int $maxRows = 50000, int $maxColumns = 60): array
    {
        $text = str_starts_with($input, "\xEF\xBB\xBF") ? substr($input, 3) : $input;
        $nl = strcspn($text, "\r\n");
        $firstLine = substr($text, 0, $nl > 0 ? $nl : strlen($text));
        $best = ',';
        $bestN = 0;
        foreach ([',', ';', "\t"] as $d) {
            $n = substr_count($firstLine, $d) + 1;
            if ($n > $bestN) { $bestN = $n; $best = $d; }
        }
        $delimiter = $best;
        $records = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);
        $endField = static function () use (&$row, &$field, $maxColumns): void {
            $row[] = $field;
            $field = '';
            if (count($row) > $maxColumns) throw new CsvError("Too many columns (limit $maxColumns)");
        };
        $endRow = static function () use (&$records, &$row, $endField, $maxRows): void {
            $endField();
            if (!(count($row) === 1 && $row[0] === '')) $records[] = $row;
            $row = [];
            if (count($records) > $maxRows + 1) throw new CsvError("Too many rows (limit $maxRows)");
        };
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($inQuotes) {
                if ($c === '"') {
                    if (($text[$i + 1] ?? '') === '"') { $field .= '"'; $i++; continue; }
                    $inQuotes = false;
                } else $field .= $c;
            } elseif ($c === '"' && $field === '') $inQuotes = true;
            elseif ($c === $delimiter) $endField();
            elseif ($c === "\r") { if (($text[$i + 1] ?? '') === "\n") $i++; $endRow(); }
            elseif ($c === "\n") $endRow();
            else $field .= $c;
        }
        if ($inQuotes) throw new CsvError('The file ends inside a quoted value — check for a missing closing quote');
        if ($field !== '' || $row) $endRow();
        $header = array_map('trim', array_shift($records) ?? []);
        if (!$header || !array_filter($header, static fn($h) => $h !== '')) throw new CsvError('The file has no header row');
        return ['header' => $header, 'rows' => $records];
    }

    public static function cell(mixed $v): string
    {
        if ($v === null) return '';
        if ($v instanceof \DateTimeInterface) $s = Time::iso($v);
        elseif (is_array($v)) $s = implode('; ', array_map('strval', $v));
        elseif (is_bool($v)) $s = $v ? 'true' : 'false';
        else $s = (string) $v;
        if (!is_int($v) && !is_float($v) && preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s; // genuine numbers (e.g. -5) cannot be formulas
        return (preg_match('/[",\r\n;]/', $s) || str_starts_with($s, ' ') || str_ends_with($s, ' ')) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }

    /** @param string[] $header @param array<int,array<int,mixed>> $rows */
    public static function write(array $header, array $rows): string
    {
        $lines = [implode(',', array_map([self::class, 'cell'], $header))];
        foreach ($rows as $r) $lines[] = implode(',', array_map([self::class, 'cell'], $r));
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }
}
