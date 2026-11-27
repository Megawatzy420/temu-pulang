<?php

declare(strict_types=1);

namespace App\Services\Export;

class CsvWriter
{
    public const UTF8_BOM = "\xEF\xBB\xBF";

    public const DELIMITER = ';';

    public const LINE_ENDING = "\r\n";

    /**
     * Write one or more sections into a single UTF-8 BOM CSV string with semicolon delimiter and CRLF line endings.
     * Sections are separated by a blank line and a section title row.
     *
     * @param  list<array{
     *     name: string,
     *     headers: list<string>,
     *     rows: list<list<array{type: 's'|'n', value: string}|string|int>>
     * }>  $sections
     */
    public function writeSections(array $sections): string
    {
        $lines = [];

        foreach ($sections as $sectionIndex => $section) {
            if ($sectionIndex > 0) {
                $lines[] = '';
            }

            $sectionTitle = trim((string) ($section['name'] ?? ''));
            if ($sectionTitle !== '') {
                $lines[] = $this->encodeRow([XlsxWriter::stringCell($sectionTitle)]);
            }

            $headers = $section['headers'] ?? [];
            if ($headers !== []) {
                $typedHeaders = array_map(
                    static fn (string $h): array => XlsxWriter::stringCell($h),
                    array_values($headers),
                );
                $lines[] = $this->encodeRow($typedHeaders);
            }

            foreach ($section['rows'] ?? [] as $row) {
                $lines[] = $this->encodeRow(array_values($row));
            }
        }

        return self::UTF8_BOM.implode(self::LINE_ENDING, $lines).self::LINE_ENDING;
    }

    /**
     * Encode a single CSV row of typed or scalar cells using semicolon delimiter and RFC-4180 quoting.
     *
     * @param  list<array{type: 's'|'n', value: string}|string|int>  $cells
     */
    public function encodeRow(array $cells): string
    {
        $encodedCells = [];

        foreach ($cells as $cell) {
            if (is_int($cell)) {
                $rawValue = (string) $cell;
            } elseif (is_array($cell)) {
                $type = ($cell['type'] ?? 's') === 'n' ? 'n' : 's';
                $val = (string) ($cell['value'] ?? '');
                $rawValue = $type === 'n'
                    ? trim($val)
                    : XlsxWriter::sanitizeStringCell($val);
            } else {
                $rawValue = XlsxWriter::sanitizeStringCell((string) $cell);
            }

            $encodedCells[] = $this->quoteFieldIfNeeded($rawValue);
        }

        return implode(self::DELIMITER, $encodedCells);
    }

    /**
     * Quote a field when it contains the semicolon delimiter, double quotes, or CR/LF line breaks,
     * doubling internal double quotes.
     */
    private function quoteFieldIfNeeded(string $value): string
    {
        $needsQuotes = str_contains($value, self::DELIMITER)
            || str_contains($value, '"')
            || str_contains($value, "\n")
            || str_contains($value, "\r");

        if (! $needsQuotes) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
