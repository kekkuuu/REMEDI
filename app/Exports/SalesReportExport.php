<?php

namespace App\Exports;

use App\Support\XlsxWriter;

/**
 * The Sales Report's Excel download, two sheets:
 * - the day/month breakdown the page shows (SalesBreakdownSheet), and
 * - every sale line in the transaction file's own layout (SalesLineItemsSheet,
 *   2026-10-15, at the user's request), built by App\Support\SalesLineItems
 *   with the same range and filters the report used.
 *
 * Written with App\Support\XlsxWriter rather than Laravel Excel: the line sheet
 * runs to tens of thousands of rows, which PhpSpreadsheet holds in memory cell
 * by cell. The sheet classes stay the one definition of what each sheet says.
 */
class SalesReportExport
{
    public function __construct(private array $data, private array $lines) {}

    /** Builds the workbook in a temp file and returns its path. */
    public function save(): string
    {
        $xlsx = new XlsxWriter;

        $breakdown = new SalesBreakdownSheet($this->data);
        $xlsx->addSheet(
            $breakdown->title(),
            $breakdown->headings(),
            $breakdown->collection()->map(fn ($row) => $breakdown->map($row)),
            [1 => XlsxWriter::INT, 2 => XlsxWriter::MONEY, 3 => XlsxWriter::MONEY, 4 => XlsxWriter::MONEY],
            [18, 12, 22, 26, 20],
        );

        $lines = new SalesLineItemsSheet($this->lines);
        $xlsx->addSheet($lines->title(), $lines->headings(), $lines->rows(), $lines->kinds(), $lines->widths());

        return $xlsx->save();
    }
}
