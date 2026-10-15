<?php

namespace App\Support;

use ZipArchive;

/**
 * A minimal streaming .xlsx writer: rows go straight to a temp file, so a sheet
 * of tens of thousands of rows costs a few MB of memory and about a second,
 * where PhpSpreadsheet (Laravel Excel) builds every cell as an object --
 * measured 2026-10-15: 20,000 sale lines took 7.8 s and 272 MB that way, too
 * close to what a serverless request on Vercel is allowed.
 *
 * Writes only what the reports need: several sheets, a bold header row, column
 * widths, and three cell kinds -- text, whole number, money (#,##0.00).
 * Every string is written as TEXT (inline string), never as a formula, so a
 * name starting with "=" cannot run; and a 13-digit SKU stays exact instead of
 * becoming 4.81E+12.
 */
final class XlsxWriter
{
    public const TEXT = 'text';

    public const INT = 'int';

    public const MONEY = 'money';

    /** Style ids in styles.xml below. */
    private const STYLE = ['header' => 1, self::TEXT => 2, self::INT => 3, self::MONEY => 4];

    /** @var array<int, array{title: string, path: string}> */
    private array $sheets = [];

    /**
     * @param  iterable<array<int, mixed>>  $rows  data rows (header excluded)
     * @param  array<int, string>  $kinds  per column: TEXT / INT / MONEY (default TEXT)
     * @param  array<int, float>  $widths  per column, in Excel character widths
     */
    public function addSheet(string $title, array $headings, iterable $rows, array $kinds = [], array $widths = []): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-sheet-');
        $out = fopen($path, 'wb');

        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        if ($widths) {
            fwrite($out, '<cols>');
            foreach ($widths as $i => $w) {
                $n = $i + 1;
                fwrite($out, '<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>');
            }
            fwrite($out, '</cols>');
        }
        fwrite($out, '<sheetData>');

        $r = 1;
        fwrite($out, $this->row($r++, $headings, [], true));
        foreach ($rows as $row) {
            fwrite($out, $this->row($r++, array_values($row), $kinds, false));
        }

        fwrite($out, '</sheetData></worksheet>');
        fclose($out);

        $this->sheets[] = ['title' => self::sheetTitle($title), 'path' => $path];
    }

    /** Writes the workbook to a temp file and returns its path. */
    public function save(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .implode('', array_map(fn ($i) => '<sheet name="'.self::esc($this->sheets[$i - 1]['title']).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>', range(1, $n)))
            .'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', range(1, $n)))
            .'<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        // Built-in number formats only: 49 = @ (text), 3 = #,##0, 4 = #,##0.00.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>');
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFile($sheet['path'], 'xl/worksheets/sheet'.($i + 1).'.xml');
        }
        $zip->close();

        foreach ($this->sheets as $sheet) {
            @unlink($sheet['path']);
        }

        return $path;
    }

    private function row(int $r, array $cells, array $kinds, bool $header): string
    {
        $xml = '<row r="'.$r.'">';
        foreach ($cells as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = self::column($i).$r;
            $kind = $kinds[$i] ?? self::TEXT;
            if (! $header && $kind !== self::TEXT && is_numeric($value)) {
                $xml .= '<c r="'.$ref.'" s="'.self::STYLE[$kind].'"><v>'.(0 + $value).'</v></c>';
            } else {
                $style = $header ? self::STYLE['header'] : self::STYLE[self::TEXT];
                $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $value).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** 0 -> A, 25 -> Z, 26 -> AA. */
    private static function column(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    /** XML-safe, with the control characters XML 1.0 forbids removed. */
    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Excel's rules: at most 31 characters, none of : \ / ? * [ ]. */
    private static function sheetTitle(string $title): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $title), 0, 31) ?: 'Sheet';
    }
}
