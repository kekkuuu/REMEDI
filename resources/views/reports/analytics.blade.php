@extends('layouts.app')

@section('title', 'Analytics Report')

@section('content')

<style>
/* The printable report is a second, unpaginated copy of the whole dataset.
   Useful on paper, ruinous on screen — it made the page ~167 viewports tall.
   Screen uses the capped table above; this exists only for the printout. */
@media screen {
    #print-area { display: none; }
}

@media print {
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

    /* Keep a section's title glued to the content that follows it,
       and start each major section cleanly rather than mid-page */
    .print-section-title { page-break-after: avoid; break-after: avoid; }
    .print-section { page-break-before: auto; }
}
</style>

{{-- ===================== SCREEN ONLY ===================== --}}
<div id="no-print">

    {{-- Page Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1.5rem;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back </a>
            
            <div>
                <div style="font-size:20px;font-weight:500;color:#111;">
                    <i class="ti ti-chart-line" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Analytics Report
                </div>
                <div style="font-size:13px;color:#6b7280;margin-top:2px;">Sales trends, top products, and demand insights</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <form method="GET">
                <select name="month" onchange="this.form.submit()" class="report-select">
                    <option value="">All time ({{ \Carbon\Carbon::parse($dataStart)->format('M Y') }} &ndash; {{ \Carbon\Carbon::parse($dataEnd)->format('M Y') }})</option>
                    @foreach($months as $m)
                        <option value="{{ $m['ym'] }}" {{ $month === $m['ym'] ? 'selected' : '' }}>{{ $m['label'] }}</option>
                    @endforeach
                </select>
            </form>
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" style="font-size:14px;"></i> Print
            </button>
        </div>
    </div>

    {{-- Shared .kpi stat cards (layouts/app.blade.php), matching the Dashboard. --}}
    <div class="kpi-grid">
        <div class="kpi" style="--kpi-accent:#22c55e;">
            <div class="kpi-head">
                <i class="ti ti-cash" aria-hidden="true"></i>
                <span class="kpi-label">Total Revenue</span>
            </div>
            <span class="kpi-value">&#8369;{{ number_format($salesTrend->sum('total'), 2) }}</span>
            <span class="kpi-sub">over the selected period</span>
        </div>

        <div class="kpi" style="--kpi-accent:#3b82f6;">
            <div class="kpi-head">
                <i class="ti ti-trophy" aria-hidden="true"></i>
                <span class="kpi-label">Top Product</span>
            </div>
            {{-- Product names run long, so this one steps down a size and
                 wraps rather than overflowing its card. --}}
            <span class="kpi-value" style="font-size:15px; line-height:1.35;">
                {{ $topProducts->first()->name ?? '—' }}
            </span>
            <span class="kpi-sub">best seller by revenue</span>
        </div>

        <div class="kpi" style="--kpi-accent:#6366f1;">
            <div class="kpi-head">
                <i class="ti ti-coin" aria-hidden="true"></i>
                <span class="kpi-label">Top Product Revenue</span>
            </div>
            <span class="kpi-value">&#8369;{{ number_format($topProducts->first()->total_revenue ?? 0, 2) }}</span>
            <span class="kpi-sub">from that single product</span>
        </div>

        <div class="kpi {{ $slowMovingCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#f59e0b;">
            <div class="kpi-head">
                <i class="ti ti-snowflake" aria-hidden="true"></i>
                <span class="kpi-label">Slow-Moving Items</span>
            </div>
            <span class="kpi-value">{{ number_format($slowMovingCount) }}</span>
            <span class="kpi-sub">under 5 units &middot; showing slowest 10</span>
        </div>
    </div>

    {{-- Top Selling + Slow Moving --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:1.5rem;">

        {{-- Top Selling Chart --}}
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;background:#fff;padding:16px;">
            <div style="font-size:14px;font-weight:500;color:#111;margin-bottom:12px;">
                <i class="ti ti-chart-bar" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#27500A;"></i>
                Top 10 Selling Products
            </div>
            @if($topProducts->isNotEmpty())
                <canvas id="topProductsChart" height="200"></canvas>
            @else
                <p style="color:#9ca3af;font-size:13px;text-align:center;padding:24px 0;">No sales data.</p>
            @endif
        </div>

        {{-- Slow Moving Chart --}}
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;background:#fff;padding:16px;">
            <div style="font-size:14px;font-weight:500;color:#111;margin-bottom:12px;">
                <i class="ti ti-chart-bar" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#633806;"></i>
                Top 10 Slow-Moving Products
            </div>
            @if($slowMoving->isNotEmpty())
                <canvas id="slowMovingChart" height="200"></canvas>
            @else
                <p style="color:#9ca3af;font-size:13px;text-align:center;padding:24px 0;">No slow-moving products.</p>
            @endif
        </div>

    </div>

    {{-- Top Selling + Slow Moving (tables) --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:1.5rem;">

        {{-- Top Selling --}}
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
            <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;gap:8px;">
                <span style="font-size:14px;font-weight:500;color:#111;">Top-Selling Products</span>
                <span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:20px;background:#EAF3DE;color:#27500A;">High Demand</span>
            </div>
            <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th style="width:38px;padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">#</th>
                        <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Product</th>
                        <th style="width:80px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Qty</th>
                        <th style="width:110px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topProducts as $tp)
                    <tr style="border-bottom:0.5px solid #e5e7eb;"
                        onmouseover="this.style.background='#f9fafb'"
                        onmouseout="this.style.background=''">
                        <td style="padding:10px 14px;color:#9ca3af;font-size:12px;">{{ $loop->iteration }}</td>
                        <td style="padding:10px 14px;font-weight:500;color:#111;">{{ $tp->name ?? 'N/A' }}</td>
                        <td style="padding:10px 14px;text-align:right;">
                            <span style="font-size:11px;font-weight:500;padding:3px 8px;border-radius:20px;background:#EEEDFE;color:#3C3489;">
                                {{ $tp->total_qty }}
                            </span>
                        </td>
                        <td style="padding:10px 14px;text-align:right;font-weight:500;color:#16a34a;">₱{{ number_format($tp->total_revenue, 2) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">
                            <i class="ti ti-chart-bar-off" style="font-size:24px;display:block;margin-bottom:6px;"></i>
                            No sales data.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>

        {{-- Slow Moving --}}
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
            <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;gap:8px;">
                <span style="font-size:14px;font-weight:500;color:#111;">Slow-Moving Products</span>
                <span style="font-size:11px;color:#94a3b8;">all {{ number_format($slowMovingCount) }} &middot; scroll</span>
                <span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:20px;background:#FAEEDA;color:#633806;">Low Demand</span>
            </div>
            <div class="table-scroll list-scroll"><table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead class="sticky-head">
                    <tr style="background:#f9fafb;">
                        <th style="width:38px;padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">#</th>
                        <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Product</th>
                        <th style="width:110px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Stock</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($slowMoving as $sm)
                    <tr style="border-bottom:0.5px solid #e5e7eb;"
                        onmouseover="this.style.background='#f9fafb'"
                        onmouseout="this.style.background=''">
                        <td style="padding:10px 14px;color:#9ca3af;font-size:12px;">{{ $loop->iteration }}</td>
                        <td style="padding:10px 14px;font-weight:500;color:#111;">{{ $sm->name }}</td>
                        <td style="padding:10px 14px;text-align:right;">
                            <span style="font-size:11px;font-weight:500;padding:3px 8px;border-radius:20px;background:#FAEEDA;color:#633806;">
                                {{ $sm->total_stock }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">
                            <i class="ti ti-circle-check" style="font-size:24px;display:block;margin-bottom:6px;color:#16a34a;"></i>
                            No slow-moving products.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>

    </div>

    {{-- Sales Trend --}}
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
        <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:14px;font-weight:500;color:#111;">
                <i class="ti ti-trending-up" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#185FA5;"></i>
                Sales Trend &mdash; {{ $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y') : 'All Time' }}
            </span>
            <span style="font-size:12px;color:#6b7280;">{{ $salesTrend->count() }} days with sales</span>
        </div>
        <div style="padding:16px;">
            @if($salesTrend->isNotEmpty())
                <canvas id="salesTrendChart" height="80"></canvas>
            @else
                <p style="color:#9ca3af;font-size:13px;text-align:center;padding:12px 0;">No sales data for this period.</p>
            @endif
        </div>
        <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="width:38px;padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">#</th>
                    <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Date</th>
                    <th style="width:160px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Total Sales</th>
                    <th style="width:220px;padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Bar</th>
                </tr>
            </thead>
            <tbody>
                @php $maxSale = $salesTrend->max('total') ?: 1; @endphp
                @forelse($salesTrend as $row)
                @php $pct = min(100, round(($row->total / $maxSale) * 100)); @endphp
                <tr style="border-bottom:0.5px solid #e5e7eb;"
                    onmouseover="this.style.background='#f9fafb'"
                    onmouseout="this.style.background=''">
                    <td style="padding:10px 14px;color:#9ca3af;font-size:12px;">{{ $loop->iteration }}</td>
                    <td style="padding:10px 14px;color:#374151;">{{ $row->date }}</td>
                    <td style="padding:10px 14px;text-align:right;font-weight:500;color:#16a34a;">₱{{ number_format($row->total, 2) }}</td>
                    <td style="padding:10px 14px;">
                        <div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
                            <div style="background:#16a34a;height:8px;border-radius:4px;width:{{ $pct }}%;"></div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" style="padding:48px;text-align:center;color:#9ca3af;font-size:14px;">
                        <i class="ti ti-chart-line-off" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                        No sales data for this period.
                    </td>
                </tr>
                @endforelse
            </tbody>
            @if($salesTrend->count())
            <tfoot>
                <tr style="background:#f9fafb;">
                    <td colspan="2" style="padding:9px 14px;font-weight:500;font-size:12px;color:#111;border-top:0.5px solid #e5e7eb;text-align:right;">Total</td>
                    <td style="padding:9px 14px;font-weight:600;font-size:13px;color:#16a34a;border-top:0.5px solid #e5e7eb;text-align:right;">₱{{ number_format($salesTrend->sum('total'), 2) }}</td>
                    <td style="border-top:0.5px solid #e5e7eb;"></td>
                </tr>
            </tfoot>
            @endif
        </table></div>
    </div>

    {{-- Seasonal Trends — average sales per calendar month, across ALL
         history (not the "last N days" filter above), so year-over-year
         seasonal patterns show up regardless of the selected window. --}}
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;margin-top:1.5rem;">
        <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span style="font-size:14px;font-weight:500;color:#111;">
                <i class="ti ti-calendar-stats" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#5b21b6;"></i>
                Seasonal Trends — Average Sales by Month
            </span>
            @if($seasonalTrends->isNotEmpty())
                @php
                    $peakMonth = $seasonalTrends->sortByDesc('avg_total')->first();
                    $slowMonth = $seasonalTrends->sortBy('avg_total')->first();
                @endphp
                <span style="font-size:12px;color:#6b7280;">
                    Peak: <strong style="color:#16a34a;">{{ $peakMonth['month'] }}</strong>
                    &nbsp;&middot;&nbsp;
                    Slowest: <strong style="color:#854F0B;">{{ $slowMonth['month'] }}</strong>
                </span>
            @endif
        </div>
        <div style="padding:16px;">
            @if($seasonalTrends->isNotEmpty())
                <canvas id="seasonalTrendsChart" height="90"></canvas>
                <p style="color:#9ca3af;font-size:11.5px;margin:10px 0 0;">
                    Each bar averages that calendar month's total sales across every year of history recorded so far (hover a bar to see how many years it's based on).
                </p>
            @else
                <p style="color:#9ca3af;font-size:13px;text-align:center;padding:12px 0;">Not enough sales history yet to show seasonal patterns.</p>
            @endif
        </div>
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
            <div style="font-size:18px;font-weight:600;color:#111;">Analytics Report</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y') : 'All Time' }}</div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Generated: {{ now()->format('M d, Y h:i A') }}</div>
        </div>
    </div>

    {{-- Print Summary --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;">
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #22c55e;background:#f0fdf4;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Total Revenue</div>
            <div style="font-size:18px;font-weight:600;color:#16a34a;">₱{{ number_format($salesTrend->sum('total'), 2) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #3b82f6;background:#eff6ff;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Top Product</div>
            <div style="font-size:13px;font-weight:600;color:#185FA5;margin-top:4px;">{{ $topProducts->first()->name ?? '—' }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #6366f1;background:#eef2ff;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Top Revenue</div>
            <div style="font-size:18px;font-weight:600;color:#4f46e5;">₱{{ number_format($topProducts->first()->total_revenue ?? 0, 2) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #f59e0b;background:#fffbeb;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Slow-Moving</div>
            <div style="font-size:18px;font-weight:600;color:#854F0B;">{{ $slowMoving->count() }}</div>
        </div>
    </div>

    {{-- Print: Top Selling --}}
    <div class="print-section-title" style="font-size:13px;font-weight:600;color:#111;margin-bottom:8px;">
        Top-Selling Products &mdash; {{ $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y') : 'All Time' }}
    </div>
    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:24px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Product</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Qty Sold</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @forelse($topProducts as $tp)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:7px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;font-weight:500;color:#111;">{{ $tp->name ?? 'N/A' }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;text-align:right;color:#3C3289;">{{ $tp->total_qty }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-weight:500;color:#16a34a;">₱{{ number_format($tp->total_revenue, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" style="padding:16px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;">No sales data.</td>
            </tr>
            @endforelse
        </tbody>
    </table></div>

    {{-- Print: Slow Moving --}}
    <div class="print-section-title" style="font-size:13px;font-weight:600;color:#111;margin-bottom:8px;">
        Slow-Moving Products
    </div>
    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:24px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Product</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Current Stock</th>
            </tr>
        </thead>
        <tbody>
            @forelse($slowMoving as $sm)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:7px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;font-weight:500;color:#111;">{{ $sm->name }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;text-align:right;color:#854F0B;font-weight:500;">{{ $sm->total_stock }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="3" style="padding:16px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;">None.</td>
            </tr>
            @endforelse
        </tbody>
    </table></div>

    {{-- Print: Sales Trend --}}
    <div class="print-section-title" style="font-size:13px;font-weight:600;color:#111;margin-bottom:8px;">
        Sales Trend &mdash; {{ $month ? \Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y') : 'All Time' }}
    </div>
    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Date</th>
                <th style="padding:8px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Total Sales</th>
            </tr>
        </thead>
        <tbody>
            @forelse($salesTrend as $row)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:7px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;color:#374151;">{{ $row->date }}</td>
                <td style="padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-weight:500;color:#16a34a;">₱{{ number_format($row->total, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="3" style="padding:16px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;">No sales data.</td>
            </tr>
            @endforelse
        </tbody>
        @if($salesTrend->count())
        <tfoot>
            <tr style="background:#f3f4f6;">
                <td colspan="2" style="padding:8px 12px;font-weight:600;color:#111;border:1px solid #e5e7eb;text-align:right;">Total</td>
                <td style="padding:8px 12px;font-weight:600;color:#16a34a;border:1px solid #e5e7eb;text-align:right;">₱{{ number_format($salesTrend->sum('total'), 2) }}</td>
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
    @if($topProducts->isNotEmpty())
    new Chart(document.getElementById('topProductsChart'), {
        type: 'bar',
        data: {
            labels: {!! json_encode($topProducts->map(fn ($tp) => $tp->name ?? 'Unknown')) !!},
            datasets: [{
                label: 'Revenue',
                data: {!! json_encode($topProducts->pluck('total_revenue')) !!},
                backgroundColor: '#22c55e',
                borderRadius: 4,
                maxBarThickness: 16,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.x.toLocaleString(undefined, { minimumFractionDigits: 2 }) } },
            },
            scales: {
                x: { beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: '#f1f5f9' } },
                y: { ticks: { color: '#374151', font: { size: 11 } }, grid: { display: false } },
            },
        },
    });
    @endif

    @if($slowMoving->isNotEmpty())
    new Chart(document.getElementById('slowMovingChart'), {
        type: 'bar',
        data: {
            labels: {!! json_encode($slowMoving->take(10)->pluck('name')) !!},
            datasets: [{
                label: 'Stock',
                data: {!! json_encode($slowMoving->take(10)->pluck('total_stock')) !!},
                backgroundColor: '#f59e0b',
                borderRadius: 4,
                maxBarThickness: 16,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.x} units` } },
            },
            scales: {
                x: { beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: '#f1f5f9' } },
                y: { ticks: { color: '#374151', font: { size: 11 } }, grid: { display: false } },
            },
        },
    });
    @endif

    @if($salesTrend->isNotEmpty())
    new Chart(document.getElementById('salesTrendChart'), {
        type: 'line',
        data: {
            labels: {!! json_encode($salesTrend->pluck('date')) !!},
            datasets: [{
                label: 'Sales',
                data: {!! json_encode($salesTrend->pluck('total')) !!},
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.14)',
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointBackgroundColor: '#16a34a',
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

    @if($seasonalTrends->isNotEmpty())
    new Chart(document.getElementById('seasonalTrendsChart'), {
        type: 'bar',
        data: {
            labels: {!! json_encode($seasonalTrends->pluck('month')) !!},
            datasets: [{
                label: 'Avg. sales',
                data: {!! json_encode($seasonalTrends->pluck('avg_total')) !!},
                yearsObserved: {!! json_encode($seasonalTrends->pluck('years_observed')) !!},
                backgroundColor: '#8b5cf6',
                borderRadius: 4,
                maxBarThickness: 34,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => '₱' + ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' avg',
                        afterLabel: (ctx) => {
                            const years = ctx.dataset.yearsObserved[ctx.dataIndex];
                            return `Based on ${years} year${years === 1 ? '' : 's'} of data`;
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#9ca3af', font: { size: 11 }, callback: (v) => '₱' + v.toLocaleString() },
                    grid: { color: '#f1f5f9' },
                },
                x: {
                    ticks: { color: '#374151', font: { size: 11, weight: '600' } },
                    grid: { display: false },
                },
            },
        },
    });
    @endif
</script>

@endsection
