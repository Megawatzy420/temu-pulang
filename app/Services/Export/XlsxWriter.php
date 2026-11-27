<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

class XlsxWriter
{
    public const MAX_EXCEL_CELL_CHARS = 32767;

    public const MAX_SHEET_NAME_CHARS = 31;

    /**
     * Characters that trigger spreadsheet formula evaluation when placed at the start of a text cell.
     *
     * @var list<string>
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Build a typed string cell definition.
     *
     * @return array{type: 's', value: string}
     */
    public static function stringCell(string $value): array
    {
        return [
            'type' => 's',
            'value' => $value,
        ];
    }

    /**
     * Build a typed numeric cell definition from an integer or pre-validated integer-derived decimal string.
     *
     * @return array{type: 'n', value: string}
     */
    public static function numericCell(int|string $value): array
    {
        $str = trim((string) $value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $str) !== 1) {
            throw new RuntimeException("Invalid numeric cell value: '{$str}'.");
        }

        return [
            'type' => 'n',
            'value' => $str,
        ];
    }

    /**
     * Convert integer tenths (e.g. 425 -> "42.5", 1000 -> "100", 5 -> "0.5", -250 -> "-25")
     * into an exact decimal string using pure integer arithmetic.
     */
    public static function tenthsToDecimalString(int $tenths): string
    {
        if ($tenths === 0) {
            return '0';
        }

        $sign = $tenths < 0 ? '-' : '';
        $abs = abs($tenths);
        $whole = intdiv($abs, 10);
        $frac = $abs % 10;

        if ($frac === 0) {
            return "{$sign}{$whole}";
        }

        return "{$sign}{$whole}.{$frac}";
    }

    /**
     * Sanitize a string cell value against spreadsheet formula injection and enforce Excel's 32,767-char limit.
     * Never called on numeric cells.
     */
    public static function sanitizeStringCell(string $value): string
    {
        // Strip disallowed XML 1.0 control characters (keep tab \x09, LF \x0A, CR \x0D)
        $cleaned = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        if ($cleaned !== '') {
            $firstRaw = $cleaned[0];
            $trimmedLeading = ltrim($cleaned, " \n");
            $firstAfterSpace = $trimmedLeading !== '' ? $trimmedLeading[0] : '';

            if (
                in_array($firstRaw, self::FORMULA_TRIGGERS, true)
                || in_array($firstAfterSpace, self::FORMULA_TRIGGERS, true)
            ) {
                $cleaned = "'".$cleaned;
            }
        }

        if (mb_strlen($cleaned, 'UTF-8') > self::MAX_EXCEL_CELL_CHARS) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_EXCEL_CELL_CHARS, 'UTF-8');
        }

        return $cleaned;
    }

    /**
     * Sanitize and deduplicate a worksheet tab name according to OpenXML rules
     * (max 31 chars, no : \ / ? * [ ], no leading/trailing apostrophe).
     *
     * @param  array<string, true>  $usedLowerNames
     */
    public static function sanitizeSheetName(string $rawName, array &$usedLowerNames = [], int $fallbackIndex = 1): string
    {
        $cleaned = (string) preg_replace('/[:\\\\\/?*\[\]\x00-\x1F]/u', ' ', $rawName);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B'");
        $cleaned = (string) preg_replace('/\s+/u', ' ', $cleaned);

        if ($cleaned === '') {
            $cleaned = "Sheet{$fallbackIndex}";
        }

        $base = mb_substr($cleaned, 0, self::MAX_SHEET_NAME_CHARS, 'UTF-8');
        $base = rtrim($base, " '");
        if ($base === '') {
            $base = "Sheet{$fallbackIndex}";
        }

        $candidate = $base;
        $counter = 2;

        while (isset($usedLowerNames[mb_strtolower($candidate, 'UTF-8')])) {
            $suffix = " ({$counter})";
            $maxBaseLen = self::MAX_SHEET_NAME_CHARS - mb_strlen($suffix, 'UTF-8');
            $trimmedBase = rtrim(mb_substr($base, 0, max(1, $maxBaseLen), 'UTF-8'), " '");
            $candidate = $trimmedBase.$suffix;
            $counter++;
        }

        $usedLowerNames[mb_strtolower($candidate, 'UTF-8')] = true;

        return $candidate;
    }

    /**
     * Build a multi-sheet .xlsx binary payload in memory (via a short-lived temp file deleted in finally).
     *
     * @param  list<array{
     *     name: string,
     *     headers: list<string>,
     *     rows: list<list<array{type: 's'|'n', value: string}|string|int>>,
     *     widths?: list<int>
     * }>  $sheets
     */
    public function write(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('XlsxWriter requires at least one worksheet.');
        }

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tp_xlsx_'.bin2hex(random_bytes(8)).'.xlsx';

        try {
            $zip = new ZipArchive;
            $opened = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException('Could not initialize temporary ZipArchive for XLSX export.');
            }

            $usedSheetNames = [];
            $normalizedSheets = [];

            foreach ($sheets as $idx => $sheet) {
                $sheetIndex = $idx + 1;
                $safeName = self::sanitizeSheetName((string) ($sheet['name'] ?? ''), $usedSheetNames, $sheetIndex);
                $headers = array_values(array_map('strval', $sheet['headers'] ?? []));
                $rows = $sheet['rows'] ?? [];
                $widths = isset($sheet['widths']) && is_array($sheet['widths'])
                    ? array_values(array_map('intval', $sheet['widths']))
                    : $this->computeColumnWidths($headers, $rows);

                $normalizedSheets[] = [
                    'index' => $sheetIndex,
                    'name' => $safeName,
                    'headers' => $headers,
                    'rows' => $rows,
                    'widths' => $widths,
                ];
            }

            $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml(count($normalizedSheets)));
            $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());
            $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml($normalizedSheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml(count($normalizedSheets)));
            $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

            foreach ($normalizedSheets as $sheet) {
                $zip->addFromString(
                    "xl/worksheets/sheet{$sheet['index']}.xml",
                    $this->buildWorksheetXml($sheet['headers'], $sheet['rows'], $sheet['widths']),
                );
            }

            if (! $zip->close()) {
                throw new RuntimeException('Failed to finalize XLSX archive.');
            }

            $bytes = file_get_contents($tempPath);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Failed to read generated XLSX bytes.');
            }

            return $bytes;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Compute integer column widths from header and cell lengths.
     *
     * @param  list<string>  $headers
     * @param  list<list<array{type: 's'|'n', value: string}|string|int>>  $rows
     * @return list<int>
     */
    private function computeColumnWidths(array $headers, array $rows): array
    {
        $colCount = count($headers);
        foreach ($rows as $row) {
            if (count($row) > $colCount) {
                $colCount = count($row);
            }
        }

        $maxLengths = array_fill(0, max(1, $colCount), 12);

        foreach ($headers as $c => $headerText) {
            $len = mb_strlen($headerText, 'UTF-8') + 4;
            if ($len > $maxLengths[$c]) {
                $maxLengths[$c] = min(48, $len);
            }
        }

        foreach ($rows as $row) {
            foreach (array_values($row) as $c => $cell) {
                $val = is_array($cell) ? (string) ($cell['value'] ?? '') : (string) $cell;
                $len = min(48, mb_strlen($val, 'UTF-8') + 3);
                if (! isset($maxLengths[$c]) || $len > $maxLengths[$c]) {
                    $maxLengths[$c] = max(12, $len);
                }
            }
        }

        return $maxLengths;
    }

    /**
     * Build [Content_Types].xml for the workbook and $sheetCount worksheets.
     */
    private function buildContentTypesXml(int $sheetCount): string
    {
        $overrides = [];
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides[] = '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .implode('', $overrides)
            .'</Types>';
    }

    /**
     * Build _rels/.rels pointing to xl/workbook.xml.
     */
    private function buildRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * Build xl/workbook.xml listing all worksheet tabs.
     *
     * @param  list<array{index: int, name: string}>  $sheets
     */
    private function buildWorkbookXml(array $sheets): string
    {
        $sheetTags = [];
        foreach ($sheets as $sheet) {
            $idx = $sheet['index'];
            $escapedName = $this->escapeXml($sheet['name']);
            $sheetTags[] = '<sheet name="'.$escapedName.'" sheetId="'.$idx.'" r:id="rId'.$idx.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheetTags).'</sheets>'
            .'</workbook>';
    }

    /**
     * Build xl/_rels/workbook.xml.rels linking worksheets and styles.xml.
     */
    private function buildWorkbookRelsXml(int $sheetCount): string
    {
        $rels = [];
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels[] = '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $styleRelId = $sheetCount + 1;
        $rels[] = '<Relationship Id="rId'.$styleRelId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $rels)
            .'</Relationships>';
    }

    /**
     * Build xl/styles.xml with cellXfs index 0 (normal) and index 1 (bold header row with light gray fill).
     */
    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F4"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * Build an individual worksheet XML with frozen row 1, column widths, bold header row, and typed cells.
     *
     * @param  list<string>  $headers
     * @param  list<list<array{type: 's'|'n', value: string}|string|int>>  $rows
     * @param  list<int>  $widths
     */
    private function buildWorksheetXml(array $headers, array $rows, array $widths): string
    {
        $colsXml = '';
        if ($widths !== []) {
            $colTags = [];
            foreach ($widths as $idx => $width) {
                $colNum = $idx + 1;
                $safeWidth = max(8, min(60, (int) $width));
                $colTags[] = '<col min="'.$colNum.'" max="'.$colNum.'" width="'.$safeWidth.'" customWidth="1"/>';
            }
            $colsXml = '<cols>'.implode('', $colTags).'</cols>';
        }

        $rowXmlList = [];
        $currentRowNum = 1;

        // Row 1: Header row (style s="1", bold, frozen)
        if ($headers !== []) {
            $headerCells = [];
            foreach ($headers as $colIdx => $headerLabel) {
                $cellRef = self::columnLetter($colIdx + 1).$currentRowNum;
                $sanitized = self::sanitizeStringCell($headerLabel);
                $escaped = $this->escapeXml($sanitized);
                $headerCells[] = '<c r="'.$cellRef.'" s="1" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
            }
            $rowXmlList[] = '<row r="'.$currentRowNum.'">'.implode('', $headerCells).'</row>';
            $currentRowNum++;
        }

        // Data rows (style s="0")
        foreach ($rows as $row) {
            $cellTags = [];
            foreach (array_values($row) as $colIdx => $cell) {
                $cellRef = self::columnLetter($colIdx + 1).$currentRowNum;
                $normalized = $this->normalizeCell($cell);

                if ($normalized['type'] === 'n') {
                    $cellTags[] = '<c r="'.$cellRef.'" t="n"><v>'.$normalized['value'].'</v></c>';
                } else {
                    $sanitized = self::sanitizeStringCell($normalized['value']);
                    $escaped = $this->escapeXml($sanitized);
                    $cellTags[] = '<c r="'.$cellRef.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
                }
            }
            $rowXmlList[] = '<row r="'.$currentRowNum.'">'.implode('', $cellTags).'</row>';
            $currentRowNum++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews>'
            .'<sheetView workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            .'</sheetView>'
            .'</sheetViews>'
            .$colsXml
            .'<sheetData>'.implode('', $rowXmlList).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * Normalize a raw or typed cell into `['type' => 's'|'n', 'value' => string]`.
     *
     * @param  array{type: 's'|'n', value: string}|string|int  $cell
     * @return array{type: 's'|'n', value: string}
     */
    private function normalizeCell(array|string|int $cell): array
    {
        if (is_int($cell)) {
            return self::numericCell($cell);
        }

        if (is_string($cell)) {
            return self::stringCell($cell);
        }

        $type = ($cell['type'] ?? 's') === 'n' ? 'n' : 's';
        $val = (string) ($cell['value'] ?? '');

        return $type === 'n'
            ? self::numericCell($val)
            : self::stringCell($val);
    }

    /**
     * Convert a 1-based column index (1 -> A, 26 -> Z, 27 -> AA) to an Excel column letter.
     */
    public static function columnLetter(int $colIndex): string
    {
        $n = max(1, $colIndex);
        $letters = '';

        while ($n > 0) {
            $remainder = ($n - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $n = intdiv($n - 1, 26);
        }

        return $letters;
    }

    /**
     * Escape text for XML content and attributes.
     */
    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
