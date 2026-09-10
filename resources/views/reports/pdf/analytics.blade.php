<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    h2 { font-size: 13px; margin: 22px 0 8px; }
    p.sub { color: #64748b; margin: 0 0 16px; font-size: 11px; }
    table.rows { width: 100%; border-collapse: collapse; }
    table.rows th, table.rows td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; }
    table.rows th { background: #f8fafc; font-size: 10px; text-transform: uppercase; color: #475569; }
    table.rows td.num { text-align: right; }
</style>
</head>
<body>
    <h1>Analytics Report</h1>
    <p class="sub">{{ \Carbon\Carbon::parse($start)->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($end)->format('M j, Y') }}</p>

    <h2>Top {{ $topProducts->count() }} Selling Products (by revenue)</h2>
    <table class="rows">
        <thead>
            <tr><th>SKU</th><th>Product</th><th class="num">Units Sold</th><th class="num">Revenue (&#8369;)</th></tr>
        </thead>
        <tbody>
            @foreach ($topProducts as $row)
                <tr>
                    <td>{{ $row->product_sku }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="num">{{ number_format($row->total_qty) }}</td>
                    <td class="num">{{ number_format($row->total_revenue, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Slow Moving Products &mdash; showing {{ $slowMoving->count() }} of {{ number_format($slowMovingCount) }}</h2>
    <table class="rows">
        <thead>
            <tr><th>SKU</th><th>Product</th><th class="num">Units Sold</th><th class="num">Stock on Hand</th></tr>
        </thead>
        <tbody>
            @foreach ($slowMoving as $row)
                <tr>
                    <td>{{ $row->sku }}</td>
                    <td>{{ $row->name }}</td>
                    <td class="num">{{ number_format($row->units_sold) }}</td>
                    <td class="num">{{ number_format($row->total_stock) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
