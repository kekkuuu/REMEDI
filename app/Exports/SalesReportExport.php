<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Same breakdown the Sales Report's "Where the total comes from" table
 * shows on screen -- one row per day or month bucket, split into the
 * imported record and this terminal's own POS sales. $data comes straight
 * from ReportController::buildSalesReportData(), so the export can never
 * disagree with the page it was generated from about the range or totals.
 */
class SalesReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private array $data) {}

    public function collection()
    {
        return $this->data['dailyBreakdown'];
    }

    public function headings(): array
    {
        return ['Period', 'Units Sold', 'Imported Revenue (PHP)', 'This Terminal Revenue (PHP)', 'Total Revenue (PHP)'];
    }

    public function map($row): array
    {
        $total = (float) $row['revenue'];
        $pos = (float) ($this->data['posByBucket'][$row['key']] ?? 0);

        return [
            $row['label'],
            $row['units'],
            round($total - $pos, 2),
            $pos,
            $total,
        ];
    }

    public function title(): string
    {
        return 'Sales Report';
    }
}
