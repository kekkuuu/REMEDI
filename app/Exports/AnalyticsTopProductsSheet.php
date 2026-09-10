<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AnalyticsTopProductsSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    use EscapesFormulas;

    public function __construct(private Collection $rows) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['SKU', 'Product', 'Units Sold', 'Revenue (PHP)'];
    }

    public function map($row): array
    {
        return [
            $this->escapeCell($row->product_sku),
            $this->escapeCell($row->name),
            (int) $row->total_qty,
            (float) $row->total_revenue,
        ];
    }

    public function title(): string
    {
        return 'Top Selling Products';
    }
}
