<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Export;

use App\Services\Export\CsvWriter;
use App\Services\Export\XlsxWriter;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

class ExportWritersTest extends TestCase
{
    private XlsxWriter $xlsxWriter;

    private CsvWriter $csvWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xlsxWriter = new XlsxWriter;
        $this->csvWriter = new CsvWriter;
    }

    public function test_tenths_to_decimal_string_and_three_decimal_quantities_use_exact_integer_arithmetic(): void
    {
        $this->assertSame('42.5', XlsxWriter::tenthsToDecimalString(425));
        $this->assertSame('100', XlsxWriter::tenthsToDecimalString(1000));
        $this->assertSame('0.5', XlsxWriter::tenthsToDecimalString(5));
        $this->assertSame('0', XlsxWriter::tenthsToDecimalString(0));
        $this->assertSame('-25', XlsxWriter::tenthsToDecimalString(-250));
        $this->assertSame('-12.7', XlsxWriter::tenthsToDecimalString(-127));

        // 3-decimal quantity strings written as numeric cells
        $qtyCell = XlsxWriter::numericCell('0.025');
        $this->assertSame('n', $qtyCell['type']);
        $this->assertSame('0.025', $qtyCell['value']);
    }

    public function test_formula_injection_neutralizes_dangerous_string_cells_and_leaves_negative_numeric_cells_untouched(): void
    {
        $dangerousStrings = [
            '=SUM(A1:A10)',
            '+cmd|\' /C calc\'!A0',
            '-10+20',
            '@SUM(1+1)',
            "\t=1+1",
            "\r=1+1",
            '   =HYPERLINK("http://evil.local")',
        ];

        foreach ($dangerousStrings as $input) {
            $sanitized = XlsxWriter::sanitizeStringCell($input);
            $this->assertTrue(
                str_starts_with($sanitized, "'"),
                "Expected leading apostrophe on '{$input}', got '{$sanitized}'."
            );
        }

        // Normal strings are not prefixed
        $this->assertSame('Kopi Susu Gula Aren', XlsxWriter::sanitizeStringCell('Kopi Susu Gula Aren'));

        // Excel 32,767-char cell limit is enforced cleanly
        $longText = str_repeat('Catatan Penutupan Shift ', 2000); // 48,000 chars
        $truncated = XlsxWriter::sanitizeStringCell($longText);
        $this->assertSame(XlsxWriter::MAX_EXCEL_CELL_CHARS, mb_strlen($truncated, 'UTF-8'));

        // Negative numeric cells are never prefixed with an apostrophe
        $negNum = XlsxWriter::numericCell(-15000);
        $this->assertSame('n', $negNum['type']);
        $this->assertSame('-15000', $negNum['value']);
    }

    public function test_xlsx_writer_produces_valid_zip_with_required_parts_typed_cells_frozen_header_special_chars_and_valid_unique_sheet_names(): void
    {
        $bytes = $this->xlsxWriter->write([
            [
                'name' => 'Ringkasan: Penjualan / HPP * [2026] ? Lebih Dari 31 Karakter',
                'headers' => ['Metrik & Label', 'Nilai <Rp>', 'Margin (%)'],
                'rows' => [
                    [
                        XlsxWriter::stringCell('Kopi "Spesial" & Teh <Dingin> ☕'),
                        XlsxWriter::numericCell(3420000),
                        XlsxWriter::numericCell(XlsxWriter::tenthsToDecimalString(425)),
                    ],
                    [
                        XlsxWriter::stringCell('=CMD()'),
                        XlsxWriter::numericCell(-5000),
                        XlsxWriter::numericCell('0.025'),
                    ],
                ],
            ],
            [
                // Duplicate noisy name to test deduplication within 31 chars
                'name' => 'Ringkasan: Penjualan / HPP * [2026] ? Lebih Dari 31 Karakter',
                'headers' => ['Kolom A'],
                'rows' => [
                    [XlsxWriter::stringCell('Baris 1')],
                ],
            ],
        ]);

        $tempZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_verify_'.bin2hex(random_bytes(5)).'.xlsx';
        file_put_contents($tempZip, $bytes);

        try {
            $zip = new ZipArchive;
            $this->assertSame(true, $zip->open($tempZip));

            // Required OpenXML parts
            $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
            $this->assertNotFalse($zip->locateName('_rels/.rels'));
            $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
            $this->assertNotFalse($zip->locateName('xl/_rels/workbook.xml.rels'));
            $this->assertNotFalse($zip->locateName('xl/styles.xml'));
            $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
            $this->assertNotFalse($zip->locateName('xl/worksheets/sheet2.xml'));

            // Validate workbook.xml sheet names (<= 31 chars, no forbidden characters, unique)
            $workbookXml = (string) $zip->getFromName('xl/workbook.xml');
            $wb = new SimpleXMLElement($workbookXml);
            $sheetNames = [];
            foreach ($wb->sheets->sheet as $sheetNode) {
                $name = (string) $sheetNode['name'];
                $this->assertLessThanOrEqual(31, mb_strlen($name, 'UTF-8'));
                $this->assertDoesNotMatchRegularExpression('/[:\\\\\/?*\[\]]/', $name);
                $sheetNames[] = $name;
            }
            $this->assertCount(2, $sheetNames);
            $this->assertCount(2, array_unique($sheetNames));

            // Validate styles.xml has bold font
            $stylesXml = (string) $zip->getFromName('xl/styles.xml');
            $this->assertStringContainsString('<b/>', $stylesXml);

            // Validate sheet1.xml parses, has frozen pane, header style s="1", string cells and numeric cells
            $sheet1Xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $s1 = new SimpleXMLElement($sheet1Xml);
            $this->assertStringContainsString('state="frozen"', $sheet1Xml);
            $this->assertStringContainsString('ySplit="1"', $sheet1Xml);

            // Row 1 is bold header (s="1")
            $row1Cells = $s1->sheetData->row[0]->c;
            $this->assertSame('1', (string) $row1Cells[0]['s']);
            $this->assertSame('inlineStr', (string) $row1Cells[0]['t']);
            $this->assertSame('Metrik & Label', (string) $row1Cells[0]->is->t);

            // Row 2 has special chars + emoji in string cell, integer in B2, decimal in C2
            $row2Cells = $s1->sheetData->row[1]->c;
            $this->assertSame('inlineStr', (string) $row2Cells[0]['t']);
            $this->assertSame('Kopi "Spesial" & Teh <Dingin> ☕', (string) $row2Cells[0]->is->t);
            $this->assertSame('n', (string) $row2Cells[1]['t']);
            $this->assertSame('3420000', (string) $row2Cells[1]->v);
            $this->assertSame('n', (string) $row2Cells[2]['t']);
            $this->assertSame('42.5', (string) $row2Cells[2]->v);

            // Row 3 has formula-neutralized string cell "'=CMD()" and negative numeric cell "-5000" (untouched!)
            $row3Cells = $s1->sheetData->row[2]->c;
            $this->assertSame("'=CMD()", (string) $row3Cells[0]->is->t);
            $this->assertSame('n', (string) $row3Cells[1]['t']);
            $this->assertSame('-5000', (string) $row3Cells[1]->v);
            $this->assertSame('n', (string) $row3Cells[2]['t']);
            $this->assertSame('0.025', (string) $row3Cells[2]->v);

            $zip->close();
        } finally {
            @unlink($tempZip);
        }
    }

    public function test_csv_writer_includes_utf8_bom_semicolon_delimiter_crlf_quoting_and_formula_neutralization(): void
    {
        $csv = $this->csvWriter->writeSections([
            [
                'name' => 'Bagian Satu',
                'headers' => ['Nama Item', 'Catatan', 'Nominal (Rp)'],
                'rows' => [
                    [
                        XlsxWriter::stringCell('Kopi; Susu "Aren"'),
                        XlsxWriter::stringCell("Baris 1\nBaris 2"),
                        XlsxWriter::numericCell(25000),
                    ],
                    [
                        XlsxWriter::stringCell('=1+1'),
                        XlsxWriter::stringCell('+SUM(A1)'),
                        XlsxWriter::numericCell(-5000),
                    ],
                ],
            ],
            [
                'name' => 'Bagian Dua',
                'headers' => ['Metode', 'Total'],
                'rows' => [
                    [XlsxWriter::stringCell('Tunai'), XlsxWriter::numericCell(20000)],
                ],
            ],
        ]);

        // 1. UTF-8 BOM at start
        $this->assertTrue(str_starts_with($csv, CsvWriter::UTF8_BOM));

        // 2. CRLF line endings
        $withoutBom = substr($csv, strlen(CsvWriter::UTF8_BOM));
        $this->assertStringContainsString("\r\n", $withoutBom);

        // 3. Quoting of semicolon, doubled quotes, newline, formula neutralization, and negative number untouched
        $this->assertStringContainsString('"Kopi; Susu ""Aren""";', $withoutBom);
        $this->assertStringContainsString("\"Baris 1\nBaris 2\";25000\r\n", $withoutBom);
        $this->assertStringContainsString("'=1+1;'+SUM(A1);-5000\r\n", $withoutBom);

        // 4. Blank line and section title row between sections
        $this->assertStringContainsString("\r\n\r\nBagian Dua\r\nMetode;Total\r\nTunai;20000\r\n", $withoutBom);
    }
}
