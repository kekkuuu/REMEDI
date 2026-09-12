<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
{{-- Money here reads "PHP 1,234.56", not "₱1,234.56" -- deliberately,
     matching app/Exports/*.php's "(PHP)" column headers. dompdf's core
     Helvetica/Arial fonts have no glyph for U+20B1 and render it as a
     literal "?"; the only TTF dompdf bundles by default (DejaVu) turns
     out not to have the glyph either (verified by rendering both and
     reading the actual PDF back, not just the source markup -- a font
     that LOOKS like it should cover this is not evidence that it does).
     A real system font (Arial, Segoe UI) does have it, but those are
     Microsoft-licensed and can't be bundled into this repo to fix it
     for production. Plain text sidesteps the whole font problem. --}}
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1e293b; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    p.sub { color: #64748b; margin: 0 0 16px; font-size: 11px; }
    table.kpis { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    table.kpis td { width: 25%; padding: 8px; border: 1px solid #e2e8f0; }
    table.kpis .label { color: #64748b; font-size: 9px; text-transform: uppercase; }
    table.kpis .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
    table.rows { width: 100%; border-collapse: collapse; }
    table.rows th, table.rows td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; }
    table.rows th { background: #f8fafc; font-size: 10px; text-transform: uppercase; color: #475569; }
    table.rows td.num { text-align: right; }
    tfoot td { font-weight: bold; background: #f8fafc; }
</style>
</head>
<body>
    <h1>Sales Report</h1>
    <p class="sub">{{ \Carbon\Carbon::parse($start)->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($end)->format('M j, Y') }}</p>

    <table class="kpis">
        <tr>
            <td>
                <div class="label">Total Sales</div>
                <div class="value">PHP {{ number_format($totalSales, 2) }}</div>
            </td>
            <td>
                <div class="label">Units Sold</div>
                <div class="value">{{ number_format($totalUnits) }}</div>
            </td>
            <td>
                <div class="label">Transactions (this terminal)</div>
                <div class="value">{{ number_format($totalTransactions) }}</div>
            </td>
            <td>
                <div class="label">Average / Day</div>
                <div class="value">PHP {{ number_format($activeDays > 0 ? $totalSales / $activeDays : 0, 2) }}</div>
            </td>
        </tr>
    </table>

    <table class="rows">
        <thead>
            <tr>
                <th>Period</th>
                <th class="num">Units</th>
                <th class="num">Imported (PHP)</th>
                <th class="num">This Terminal (PHP)</th>
                <th class="num">Total (PHP)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dailyBreakdown as $row)
                @php
                    $rowPos = (float) ($posByBucket[$row['key']] ?? 0);
                    $rowTotal = (float) $row['revenue'];
                @endphp
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['units']) }}</td>
                    <td class="num">{{ number_format($rowTotal - $rowPos, 2) }}</td>
                    <td class="num">{{ number_format($rowPos, 2) }}</td>
                    <td class="num">{{ number_format($rowTotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num">{{ number_format($totalUnits) }}</td>
                <td class="num">{{ number_format($historyTotal, 2) }}</td>
                <td class="num">{{ number_format($posTotal, 2) }}</td>
                <td class="num">{{ number_format($totalSales, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
