<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AnalyticsSlowMovingSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['SKU', 'Product', 'Units Sold', 'Stock on Hand'];
    }

    public function map($row): array
    {
        return [$row->sku, $row->name, (float) $row->units_sold, (int) $row->total_stock];
    }

    public function title(): string
    {
        return 'Slow Moving Products';
    }
}
