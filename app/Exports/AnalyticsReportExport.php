<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Two sheets, matching the two ranked lists the Analytics Report page shows
 * side by side: Top Selling Products (by revenue) and Slow Moving Products.
 * $data comes straight from ReportController::buildAnalyticsReportData().
 */
class AnalyticsReportExport implements WithMultipleSheets
{
    public function __construct(private array $data) {}

    public function sheets(): array
    {
        return [
            new AnalyticsTopProductsSheet($this->data['topProducts']),
            new AnalyticsSlowMovingSheet($this->data['slowMoving']),
        ];
    }
}
