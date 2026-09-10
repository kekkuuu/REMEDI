<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per product, same filtered set and the same status badge order
 * (Out of Stock -> Expired Stock -> Low Stock -> OK) as the on-screen and
 * print copies of the Inventory Report -- see reports/inventory.blade.php,
 * which this logic is copied from so the exported status can never say
 * something the report itself does not.
 */
class InventoryReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    use EscapesFormulas;

    public function __construct(private array $data) {}

    public function collection()
    {
        return $this->data['products'];
    }

    public function headings(): array
    {
        return ['Product', 'SKU', 'Category', 'Unit', 'Total Stock', 'Unit Price (PHP)', 'Stock Value (PHP)', 'Status'];
    }

    public function map($p): array
    {
        $status = $p->total_stock <= 0
            ? 'Out of Stock'
            : ($p->sellable_stock <= 0
                ? 'Expired Stock'
                : ($p->total_stock <= $p->reorder_level ? 'Low Stock' : 'OK'));

        return [
            $this->escapeCell($p->name),
            $this->escapeCell($p->sku),
            $this->escapeCell($p->category->name ?? '—'),
            $p->unit,
            $p->total_stock,
            (float) $p->selling_price,
            round($p->total_stock * $p->selling_price, 2),
            $status,
        ];
    }

    public function title(): string
    {
        return 'Inventory Report';
    }
}
