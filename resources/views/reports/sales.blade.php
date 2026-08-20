@extends('layouts.app')

@section('title', 'Sales Report')

@section('content')

<style>
/* The printable report is a second, unpaginated copy of the whole dataset.
   Useful on paper, ruinous on screen — it made the page ~167 viewports tall.
   Screen uses the capped table above; this exists only for the printout. */
@media screen {
    #print-area { display: none; }
}

@media print {
    /* Hide everything except the report */
    body * { visibility: hidden; }
    #print-area, #print-area * { visibility: visible; }
    #print-area {
        display: block;
        position: static;
        width: 100%;
        padding: 0;
        background: #fff;
    }
    #no-print { display: none !important; }

    /* Standard paper size + margins for the printed report */
    @page {
        size: A4;
        margin: 14mm 12mm;
    }

    /* Repeat the table header/footer on every printed page */
    table thead { display: table-header-group; }
    table tfoot { display: table-footer-group; }

    /* Never split a table row across two pages, but let long tables
       flow across as many pages as they need */
    table { page-break-inside: auto; }
    table tr { page-break-inside: avoid; break-inside: avoid; }

    /* Keep a section's title glued to the content that follows it */
    .print-section-title { page-break-after: avoid; break-after: avoid; }
}
</style>

{{-- Controls (hidden on print) --}}
<div id="no-print">

    {{-- Page Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1.5rem;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back </a>
            
            <div>
                <div style="font-size:20px;font-weight:500;color:#111;">
                    <i class="ti ti-chart-bar" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Sales Report
                </div>
                <div style="font-size:13px;color:#6b7280;margin-top:2px;">Generate and export sales transactions by date range</div>
            </div>
        </div>
    </div>

    {{-- Filters. The month picker is the primary control (the data spans
         2024-2026); the date inputs remain for arbitrary ranges. Choosing a
         month overrides the dates server-side. --}}
    <form method="GET" action="{{ route('reports.sales') }}" class="report-filters">
        <div class="report-field">
            <label>Month</label>
            <select name="month" onchange="this.form.submit()" class="report-select">
                <option value="">All time ({{ \Carbon\Carbon::parse($dataStart)->format('M Y') }} &ndash; {{ \Carbon\Carbon::parse($dataEnd)->format('M Y') }})</option>
                @foreach($months as $m)
                    <option value="{{ $m['ym'] }}" {{ $month === $m['ym'] ? 'selected' : '' }}>{{ $m['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="report-field">
            <label>Start date</label>
            <input type="date" name="start_date" value="{{ $start }}" min="{{ $dataStart }}" max="{{ $dataEnd }}" class="report-select">
        </div>
        <div class="report-field">
            <label>End date</label>
            <input type="date" name="end_date" value="{{ $end }}" min="{{ $dataStart }}" max="{{ $dataEnd }}" class="report-select">
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti ti-refresh" aria-hidden="true"></i> Generate
            </button>
            @if($month || request('start_date') || request('end_date'))
                <a href="{{ route('reports.sales') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" aria-hidden="true"></i> Print
            </button>
        </div>
    </form>

    {{-- Stat cards. Uses the shared .kpi primitive from layouts/app.blade.php,
         the same one the Dashboard uses, so the two pages read as one system
         instead of each inventing its own grey box. --}}
    <div class="kpi-grid">
        <div class="kpi" style="--kpi-accent:#22c55e;">
            <div class="kpi-head">
                <i class="ti ti-cash" aria-hidden="true"></i>
                <span class="kpi-label">Total Sales</span>
            </div>
            <span class="kpi-value">&#8369;{{ number_format($totalSales, 2) }}</span>
            <span class="kpi-sub">across the selected range</span>
        </div>

        <div class="kpi" style="--kpi-accent:#3b82f6;">
            <div class="kpi-head">
                <i class="ti ti-package" aria-hidden="true"></i>
                <span class="kpi-label">Units Sold</span>
            </div>
            <span class="kpi-value">{{ number_format($totalUnits) }}</span>
            <span class="kpi-sub">units sold over {{ number_format($activeDays) }} trading days</span>
        </div>

        <div class="kpi" style="--kpi-accent:#f59e0b;">
            <div class="kpi-head">
                <i class="ti ti-divide" aria-hidden="true"></i>
                <span class="kpi-label">Average / Day</span>
            </div>
            <span class="kpi-value">&#8369;{{ $activeDays > 0 ? number_format($totalSales / $activeDays, 2) : '0.00' }}</span>
            <span class="kpi-sub">per trading day</span>
        </div>

        <div class="kpi" style="--kpi-accent:#8b5cf6;">
            <div class="kpi-head">
                <i class="ti ti-calendar-event" aria-hidden="true"></i>
                <span class="kpi-label">Date Range</span>
            </div>
            {{-- Show the start year too whenever the range crosses years, or
                 "Jan 03 - Aug 14, 2026" reads as if both ends are 2026. --}}
            @php
                $s = \Carbon\Carbon::parse($start);
                $e = \Carbon\Carbon::parse($end);
            @endphp
            <span class="kpi-value" style="font-size:17px;">
                {{ $s->format($s->year === $e->year ? 'M d' : 'M d, Y') }} &ndash; {{ $e->format('M d, Y') }}
            </span>
            <span class="kpi-sub">{{ \Carbon\Carbon::parse($start)->diffInDays(\Carbon\Carbon::parse($end)) + 1 }} days</span>
        </div>
    </div>

    {{-- Sales Trend Chart --}}
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;padding:16px;margin-bottom:1.5rem;">
        <div style="font-size:14px;font-weight:500;color:#111;margin-bottom:12px;">
            <i class="ti ti-chart-bar" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#185FA5;"></i>
            Daily Sales — {{ \Carbon\Carbon::parse($start)->format('M d') }} to {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
        </div>
        @if($dailyBreakdown->isNotEmpty())
            <canvas id="dailySalesChart" height="90"></canvas>
        @else
            <p style="color:#9ca3af;font-size:13px;text-align:center;padding:24px 0;">No transactions in this date range.</p>
        @endif
    </div>

</div>{{-- end #no-print --}}


{{-- ===================== PRINT AREA ===================== --}}
<div id="print-area">

    {{-- Print Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1.5px solid #111;">
        <div>
            <div style="font-size:22px;font-weight:700;color:#111;letter-spacing:-0.02em;">REMEDI</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">Point of Sale System</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:18px;font-weight:600;color:#111;">Sales Report</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} — {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
            </div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                Generated: {{ now()->format('M d, Y h:i A') }}
            </div>
        </div>
    </div>

    {{-- Print Summary --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #22c55e;background:#f0fdf4;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Total Sales</div>
            <div style="font-size:20px;font-weight:600;color:#16a34a;">₱{{ number_format($totalSales, 2) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #3b82f6;background:#eff6ff;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Units Sold</div>
            <div style="font-size:20px;font-weight:600;color:#3b82f6;">{{ number_format($totalUnits) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #f59e0b;background:#fffbeb;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Average per Trading Day</div>
            <div style="font-size:20px;font-weight:600;color:#b45309;">
                &#8369;{{ $activeDays > 0 ? number_format($totalSales / $activeDays, 2) : '0.00' }}
            </div>
        </div>
    </div>

    {{-- Print Table --}}
    <div class="print-section-title" style="font-size:13px;font-weight:500;color:#111;margin-bottom:10px;">
        Point-of-sale transactions recorded on this terminal &mdash; {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
    </div>

    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Transaction No</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Date</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Cashier</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sales as $i => $sale)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;">{{ $sale->transaction_no }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $sale->created_at->format('M d, Y h:i A') }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $sale->user->name ?? 'N/A' }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#16a34a;font-weight:500;text-align:right;">₱{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="padding:24px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;">
                    No transactions found in this date range.
                </td>
            </tr>
            @endforelse
        </tbody>
        @if($totalTransactions > 0)
        <tfoot>
            <tr style="background:#f3f4f6;">
                <td colspan="4" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">Grand Total</td>
                <td style="padding:9px 12px;font-weight:600;font-size:13px;color:#16a34a;border:1px solid #e5e7eb;text-align:right;">&#8369;{{ number_format($sales->sum('total_amount'), 2) }}</td>
            </tr>
        </tfoot>
        @endif
    </table></div>

    {{-- Print Footer --}}
    <div style="margin-top:40px;padding-top:12px;border-top:0.5px solid #e5e7eb;display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;">
        <span>REMEDI Point of Sale System</span>
        <span>Printed by: {{ auth()->user()->name ?? 'Admin' }} &nbsp;|&nbsp; {{ now()->format('M d, Y h:i A') }}</span>
    </div>

</div>{{-- end #print-area --}}

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    @if($dailyBreakdown->isNotEmpty())
    const salesDates = {!! json_encode($dailyBreakdown->pluck('label')) !!};
    const salesTotals = {!! json_encode($dailyBreakdown->pluck('revenue')) !!};

    new Chart(document.getElementById('dailySalesChart'), {
        type: 'bar',
        data: {
            labels: salesDates,
            datasets: [{
                label: 'Sales',
                data: salesTotals,
                backgroundColor: '#3b82f6',
                borderRadius: 4,
                maxBarThickness: 36,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 }) } },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#9ca3af', font: { size: 11 }, callback: (v) => '₱' + v.toLocaleString() },
                    grid: { color: '#f1f5f9' },
                },
                x: {
                    ticks: { color: '#374151', font: { size: 11 } },
                    grid: { display: false },
                },
            },
        },
    });
    @endif
</script>

@endsection
