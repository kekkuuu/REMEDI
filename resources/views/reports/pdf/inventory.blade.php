<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
{{-- Money here reads "PHP 1,234.56", not "₱1,234.56" -- see the same
     comment in reports/pdf/sales.blade.php for why: dompdf's core
     fonts and its one bundled TTF (DejaVu) both lack the U+20B1
     glyph, and the real system fonts that DO have it (Arial, Segoe
     UI) are Microsoft-licensed and can't be bundled into this repo. --}}
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1e293b; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    p.sub { color: #64748b; margin: 0 0 16px; font-size: 11px; }
    table.kpis { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    table.kpis td { width: 25%; padding: 8px; border: 1px solid #e2e8f0; }
    table.kpis .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
    table.kpis .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
    table.rows { width: 100%; border-collapse: collapse; }
    table.rows th, table.rows td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
    table.rows th { background: #f8fafc; font-size: 9px; text-transform: uppercase; color: #475569; }
    table.rows td.num { text-align: right; }
    .st-out { color: #334155; font-weight: bold; }
    .st-expired { color: #f59e0b; font-weight: bold; }
    .st-low { color: #ef4444; font-weight: bold; }
    .st-ok { color: #16a34a; }
</style>
</head>
<body>
    <h1>Inventory Report</h1>
    <p class="sub">
        @if ($categoryId) Category: {{ optional($categories->firstWhere('id', $categoryId))->name ?? '—' }} &middot; @endif
        @if ($lowStockOnly) Low Stock only @elseif ($expiredOnly) Expired only @else All stock @endif
        @isset($pdfTotalCount)
            @if ($pdfTotalCount > $products->count())
                &middot; showing the first {{ number_format($products->count()) }} of {{ number_format($pdfTotalCount) }} &mdash; use the Excel export for the full list
            @endif
        @endisset
    </p>

    <table class="kpis">
        <tr>
            <td>
                <div class="label">Total Stock Value</div>
                <div class="value">PHP {{ number_format($totalStockValue, 2) }}</div>
            </td>
            <td>
                <div class="label">Total Products</div>
                <div class="value">{{ number_format($products->count()) }}</div>
            </td>
            <td>
                <div class="label">Low Stock</div>
                <div class="value">{{ number_format($lowStockCount) }}</div>
            </td>
            <td>
                <div class="label">Expired Stock</div>
                <div class="value">{{ number_format($expiredCount) }}</div>
            </td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th class="num">Stock</th>
                <th class="num">Unit Price (PHP)</th>
                <th class="num">Stock Value (PHP)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($products as $p)
                @php
                    $status = $p->total_stock <= 0
                        ? ['st-out', 'Out of Stock']
                        : ($p->sellable_stock <= 0
                            ? ['st-expired', 'Expired Stock']
                            : ($p->total_stock <= $p->reorder_level ? ['st-low', 'Low Stock'] : ['st-ok', 'OK']));
                @endphp
                <tr>
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->sku }}</td>
                    <td>{{ $p->category->name ?? '—' }}</td>
                    <td class="num">{{ number_format($p->total_stock) }} {{ $p->unit }}</td>
                    <td class="num">{{ number_format($p->selling_price, 2) }}</td>
                    <td class="num">{{ number_format($p->total_stock * $p->selling_price, 2) }}</td>
                    <td class="{{ $status[0] }}">{{ $status[1] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
