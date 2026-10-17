<?php
declare(strict_types=1);

namespace App\Modules\Agency;

final class XlsxWriter
{
    private const MAX_CELL = 32000;
    private const MAX_ROWS = 1048575;

    /** @param array<int,array{name:string,header:array<int,string>,rows:array<int,array<int,mixed>>,widths?:array<int,int>,moneyColumns?:array<int,int>,wrapColumns?:array<int,int>}> $sheets */
    public static function build(array $sheets, string $timezone): string
    {
        if (!$sheets) throw new \InvalidArgumentException('An Excel workbook must contain a sheet');
        if (count($sheets) > 255) throw new \InvalidArgumentException('Too many worksheets');
        $tz = new \DateTimeZone($timezone);
        $used = [];
        foreach ($sheets as &$sheet) {
            if (count($sheet['rows']) > self::MAX_ROWS) throw new \LengthException('Too many rows for one worksheet');
            $base = trim(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $sheet['name']) ?? 'Sheet', 0, 31));
            $base = $base !== '' ? $base : 'Sheet';
            $name = $base;
            for ($n = 2; isset($used[mb_strtolower($name)]); $n++) $name = mb_substr($base, 0, 31 - strlen((string) $n) - 1) . ' ' . $n;
            $used[mb_strtolower($name)] = true;
            $sheet['name'] = $name;
        }
        unset($sheet);

        $entries = [];
        $add = static function (string $name, string $xml) use (&$entries): void { $entries[] = [$name, $xml]; };
        $overrides = '';
        $workbookSheets = '';
        $relationships = '';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . self::xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relationships .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $overrides . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $add('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>');
        $add('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $add('xl/styles.xml', self::styles());
        foreach ($sheets as $i => $sheet) $add('xl/worksheets/sheet' . ($i + 1) . '.xml', self::sheetXml($sheet, $tz));
        return self::zip($entries);
    }

    private static function sheetXml(array $sheet, \DateTimeZone $tz): string
    {
        $widths = [];
        foreach ($sheet['header'] as $i => $header) {
            $width = (int) ($sheet['widths'][$i] ?? min(60, mb_strlen($header) + 2));
            foreach (array_slice($sheet['rows'], 0, 50) as $row) {
                $value = $row[$i] ?? null;
                $length = $value instanceof \DateTimeInterface ? 16 : ($value === null ? 0 : mb_strlen((string) $value));
                $width = max($width, min(50, $length + 2));
            }
            $widths[] = max(8, min(60, $width));
        }
        $last = self::columnLetter(max(0, count($sheet['header']) - 1));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>';
        foreach ($widths as $i => $width) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        $xml .= '</cols><sheetData><row r="1" ht="30" customHeight="1">';
        foreach ($sheet['header'] as $i => $header) $xml .= self::textCell(self::columnLetter($i) . '1', (string) $header, 1);
        $xml .= '</row>';
        foreach ($sheet['rows'] as $ri => $row) {
            if (count($row) > count($sheet['header'])) throw new \InvalidArgumentException('Worksheet row has more values than its header');
            $rowNumber = $ri + 2;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach ($row as $ci => $value) $xml .= self::cell(self::columnLetter($ci) . $rowNumber, $value, $ci, $sheet, $tz);
            $xml .= '</row>';
        }
        $xml .= '</sheetData><autoFilter ref="A1:' . $last . (count($sheet['rows']) + 1) . '"/></worksheet>';
        return $xml;
    }

    private static function cell(string $ref, mixed $value, int $column, array $sheet, \DateTimeZone $tz): string
    {
        if ($value === null || $value === '') return '';
        if ($value instanceof \DateTimeInterface) {
            $local = \DateTimeImmutable::createFromInterface($value)->setTimezone($tz);
            $wall = new \DateTimeImmutable($local->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC'));
            $epoch = new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC'));
            $serial = ($wall->getTimestamp() - $epoch->getTimestamp()) / 86400 + ((int) $wall->format('u') / 1000000 / 86400);
            return '<c r="' . $ref . '" s="2"><v>' . sprintf('%.10F', $serial) . '</v></c>';
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) return '';
            return '<c r="' . $ref . '"' . (in_array($column, $sheet['moneyColumns'] ?? [], true) ? ' s="3"' : '') . '><v>' . (string) $value . '</v></c>';
        }
        if (is_bool($value)) return '<c r="' . $ref . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
        return self::textCell($ref, (string) $value, in_array($column, $sheet['wrapColumns'] ?? [], true) ? 4 : 0);
    }

    private static function textCell(string $ref, string $value, int $style): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        if (mb_strlen($value) > self::MAX_CELL) $value = mb_substr($value, 0, self::MAX_CELL) . '…';
        return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . ' t="inlineStr"><is><t xml:space="preserve">' . self::xml($value) . '</t></is></c>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function columnLetter(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) $name = chr(65 + (($number - 1) % 26)) . $name;
        return $name;
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd\ hh:mm"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E5F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function zip(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = self::dosTime();
        foreach ($entries as [$name, $data]) {
            $nameBytes = $name;
            $compressed = gzdeflate($data, 6);
            if ($compressed === false) throw new \RuntimeException('Could not compress an Excel workbook entry');
            $crc = (int) sprintf('%u', crc32($data));
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($compressed), strlen($data), strlen($nameBytes), 0);
            $local .= $localHeader . $nameBytes . $compressed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($compressed), strlen($data), strlen($nameBytes), 0, 0, 0, 0, 0, $offset) . $nameBytes;
            $offset += strlen($localHeader) + strlen($nameBytes) + strlen($compressed);
        }
        return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
    }

    private static function dosTime(): array
    {
        $parts = getdate();
        return [
            (($parts['hours'] & 0x1f) << 11) | (($parts['minutes'] & 0x3f) << 5) | (($parts['seconds'] >> 1) & 0x1f),
            ((max(1980, $parts['year']) - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
        ];
    }
}
