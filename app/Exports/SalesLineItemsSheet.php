<?php

namespace App\Exports;

use App\Support\SalesLineItems;
use App\Support\XlsxWriter;

/**
 * One row per sale line, in the transaction file's columns (Order#, Date /
 * Time, Product ID, ... Gross Profit) -- see App\Support\SalesLineItems for
 * what a line is in each record. Ends with a TOTAL row, and, when the period
 * holds more lines than the sheet carries, a note saying how many are shown.
 *
 * Rendered by App\Support\XlsxWriter (see SalesReportExport), which writes
 * every string as text: the SKU stays a full 13-digit barcode instead of
 * 4.81E+12, and a name starting with "=" can never run as a formula.
 */
class SalesLineItemsSheet
{
    public function __construct(private array $result) {}

    public function title(): string
    {
        return 'Transaction Items';
    }

    public function headings(): array
    {
        return SalesLineItems::HEADINGS;
    }

    public function rows(): iterable
    {
        yield from $this->result['lines'];

        $sum = fn (int $i) => round(array_sum(array_map(fn ($l) => (float) ($l[$i] ?? 0), $this->result['lines'])), 2);
        yield ['TOTAL', '', '', '', '', '', '', $sum(7), '', '', $sum(10), $sum(11), $sum(12)];

        if ($this->result['shown'] < $this->result['total']) {
            yield [];
            yield ['Showing the latest '.number_format($this->result['shown']).' of '
                .number_format($this->result['total']).' lines (the TOTAL row covers these only). '
                .'Choose a shorter date range in the Sales Report to get every line.'];
        }
    }

    /** Order#, Date / Time, Product ID, Product Name, SKU, Category, Unit are text. */
    public function kinds(): array
    {
        return [7 => XlsxWriter::INT, 8 => XlsxWriter::MONEY, 9 => XlsxWriter::MONEY,
            10 => XlsxWriter::MONEY, 11 => XlsxWriter::MONEY, 12 => XlsxWriter::MONEY];
    }

    public function widths(): array
    {
        return [20, 23, 11, 42, 16, 26, 9, 9, 11, 11, 12, 12, 13];
    }
}
