{{-- The Sales Report itself -- everything below the filter bar.

     One partial behind both shapes of the page (see ReportController::sales):
     the full page includes it, and a filter change fetches it alone as
     {html, state} and swaps it in place, so the two cannot drift. It carries
     no <script> that must run -- innerHTML never executes one -- only the
     chart data as JSON, which the page's renderSalesCharts() reads after
     every swap. Styling is class-based (.sr-* in reports/sales.blade.php):
     the breakdown renders twice (screen + print), and per-cell inline styles
     were most of what a filter change had to download. --}}

<div class="no-print">

    {{-- Filtered-by banner. Every figure below narrows to this the moment
         it's set -- see ReportController::buildSalesReportData()'s
         $isScoped branch and $scopeCashier comment. --}}
    @if($isScoped || $scopeCashier)
        <div class="sr-banner">
            <i class="ti ti-filter" aria-hidden="true"></i>
            <span>
                Showing sales for
                @if($isScoped)
                    <strong>{{ $scopeProduct ? $scopeProduct->name : $scopeCategory->name }}</strong>
                    @if($scopeProduct) <span class="sr-muted">(SKU {{ $scopeProduct->sku }})</span> @endif
                @endif
                @if($isScoped && $scopeCashier) rung up by @endif
                @if($scopeCashier) <strong>{{ $scopeCashier->name }}</strong> @endif
                only.
                @if($isScoped)
                    Average Transaction Value/Count and the hourly chart describe THIS TERMINAL's
                    whole till and are hidden here, since they'd otherwise read as if they were about
                    just this {{ $scopeProduct ? 'product' : 'category' }}.
                @elseif($scopeCashier)
                    Total Sales above still covers every cashier and the imported record -- only
                    Average Transaction Value/Count, the hourly chart and the transaction list below
                    narrow to this cashier, since sales_history has no cashier to filter by.
                @endif
            </span>
            <a href="{{ route('reports.sales', ['start_date' => $start, 'end_date' => $end]) }}" data-report-nav style="margin-left:auto;white-space:nowrap;">Clear filter</a>
        </div>
    @endif

    {{-- Stat cards: the shared .kpi primitive from layouts/app.blade.php. --}}
    <div class="kpi-grid">
        <div class="kpi" style="--kpi-accent:#22c55e;">
            <div class="kpi-head">
                <i class="ti ti-cash" aria-hidden="true"></i>
                <span class="kpi-label">Total Sales</span>
            </div>
            <span class="kpi-value">&#8369;{{ number_format($totalSales, 2) }}</span>
            {{-- Say what the figure is made of: it merges the imported record
                 with live POS checkouts, and only the POS half can be listed
                 as transactions. --}}
            @if($historyTotal > 0 && $posTotal > 0)
                <span class="kpi-sub">
                    &#8369;{{ number_format($historyTotal, 2) }} imported
                    + &#8369;{{ number_format($posTotal, 2) }} from POS
                </span>
            @elseif($posTotal > 0)
                <span class="kpi-sub">all from POS transactions</span>
            @else
                <span class="kpi-sub">from the imported sales record</span>
            @endif
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

        {{-- ATV/ATC are POS-only (an imported row has no transaction to divide
             by), and hidden while a Category/Product filter is active. --}}
        @unless($isScoped)
        <div class="kpi" style="--kpi-accent:#ef4444;">
            <div class="kpi-head">
                <i class="ti ti-receipt-2" aria-hidden="true"></i>
                <span class="kpi-label">Avg. Transaction Value</span>
            </div>
            <span class="kpi-value">{!! $atv !== null ? '&#8369;'.number_format($atv, 2) : '&mdash;' !!}</span>
            <span class="kpi-sub">
                {{ $atv !== null ? 'per '.($scopeCashier ? $scopeCashier->name.'\'s ' : '').'POS transaction' : 'no POS transactions in range' }}
            </span>
        </div>

        <div class="kpi" style="--kpi-accent:#0ea5e9;">
            <div class="kpi-head">
                <i class="ti ti-chart-bar" aria-hidden="true"></i>
                <span class="kpi-label">Avg. Transaction Count</span>
            </div>
            <span class="kpi-value">{{ number_format($atc, 1) }}</span>
            <span class="kpi-sub">{{ $scopeCashier ? $scopeCashier->name.'\'s ' : '' }}POS transactions/day over the range</span>
        </div>
        @endunless

        <div class="kpi" style="--kpi-accent:#8b5cf6;">
            <div class="kpi-head">
                <i class="ti ti-calendar-event" aria-hidden="true"></i>
                <span class="kpi-label">Date Range</span>
            </div>
            {{-- The start year too whenever the range crosses years. --}}
            @php
                $s = \Carbon\Carbon::parse($start);
                $e = \Carbon\Carbon::parse($end);
            @endphp
            <span class="kpi-value" style="font-size:17px;">
                {{ $s->format($s->year === $e->year ? 'M d' : 'M d, Y') }} &ndash; {{ $e->format('M d, Y') }}
            </span>
            <span class="kpi-sub">{{ $s->diffInDays($e) + 1 }} days</span>
        </div>
    </div>

    {{-- Sales Trend Chart --}}
    <div class="sr-card sr-pad">
        <div class="sr-card-title" style="margin-bottom:12px;">
            <i class="ti ti-chart-bar" aria-hidden="true"></i>
            Daily Sales — {{ $s->format('M d') }} to {{ $e->format('M d, Y') }}
        </div>
        @if($dailyBreakdown->isNotEmpty())
            <canvas id="dailySalesChart" height="90"></canvas>
        @else
            <p class="sr-empty">No transactions in this date range.</p>
        @endif
    </div>

    {{-- Hourly Sales / Transaction Volume -- BEFORE the per-day / per-month
         summary (moved 2026-09-28, at the user's request), so both charts sit
         together above the long table. POS-only: sales_history carries a DATE
         per row, never a time, so this is this terminal's own pattern. Stays
         visible on a quiet range -- a flat row of zeros IS the answer there.
         Hidden while scoped: $hourlyBreakdown is deliberately empty then, and
         it is a whole-till metric like ATV/ATC. --}}
    @unless($isScoped)
    <div class="sr-card">
        <div class="sr-card-head">
            <span class="sr-card-title">
                <i class="ti ti-clock" aria-hidden="true"></i>
                Hourly Sales &amp; Transaction Volume
            </span>
            <span class="sr-card-note">
                {{ $scopeCashier ? $scopeCashier->name.'\'s POS activity only' : 'this terminal\'s POS activity only' }}
            </span>
        </div>
        @if($totalTransactions > 0)
            <div class="sr-pad">
                <canvas id="hourlySalesChart" height="80"></canvas>
            </div>
        @else
            <p class="sr-empty">No POS transactions in this date range.</p>
        @endif
    </div>
    @endunless

    {{-- Breakdown of the headline figure: every bucket in the range with its
         imported and POS halves, and a footer that adds up to the KPI
         exactly -- the transaction list is POS-only, so without this the
         biggest part of Total Sales went unaccounted for on the page. --}}
    @if($dailyBreakdown->isNotEmpty())
    <div class="sr-card">
        <div class="sr-card-head">
            <span class="sr-card-title">
                <i class="ti ti-list-details" aria-hidden="true"></i>
                Where the total comes from
            </span>
            <span class="sr-card-note">
                {{ $granularity === 'day' ? 'per day' : 'per month' }} &middot;
                imported sales record + this terminal
            </span>
        </div>
        <div class="table-scroll"><table class="sr-table">
            <thead>
                <tr>
                    <th class="idx">#</th>
                    <th>{{ $granularity === 'day' ? 'Date' : 'Month' }}</th>
                    <th class="num" style="width:90px;">Units</th>
                    <th class="num" style="width:150px;">Imported</th>
                    <th class="num" style="width:140px;">This terminal</th>
                    <th class="num" style="width:150px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dailyBreakdown as $row)
                    @php
                        $rowTotal = (float) ($row['revenue'] ?? 0);
                        $rowPos = (float) ($posByBucket[$row['key']] ?? 0);
                        // mergePos adds POS onto the history figure, so the
                        // subtraction is the right way round -- but a rounding
                        // cent must not show as a negative "imported" cell.
                        $rowHistory = max(0, round($rowTotal - $rowPos, 2));
                    @endphp
                    <tr>
                        <td class="idx">{{ $loop->iteration }}</td>
                        <td class="lbl">{{ $row['label'] }}</td>
                        <td class="num">{{ number_format($row['units'] ?? 0) }}</td>
                        <td class="num">&#8369;{{ number_format($rowHistory, 2) }}</td>
                        <td class="num {{ $rowPos > 0 ? 'pos' : 'nil' }}">&#8369;{{ number_format($rowPos, 2) }}</td>
                        <td class="num tot">&#8369;{{ number_format($rowTotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="num" style="color:#111;font-size:12px;">Total</td>
                    <td class="num">&#8369;{{ number_format($historyTotal, 2) }}</td>
                    <td class="num pos">&#8369;{{ number_format($posTotal, 2) }}</td>
                    <td class="num tot" style="font-weight:700;">&#8369;{{ number_format($totalSales, 2) }}</td>
                </tr>
            </tfoot>
        </table></div>
    </div>
    @endif

</div>{{-- end .no-print --}}


{{-- ===================== PRINT AREA ===================== --}}
<div id="print-area">

    <div class="sr-print-head">
        <div>
            <div style="font-size:22px;font-weight:700;color:#111;letter-spacing:-0.02em;">REMEDI</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">Point of Sale System</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:18px;font-weight:600;color:#111;">Sales Report{{ $scopeLabel ? ' — '.$scopeLabel : '' }}</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                {{ $s->format('M d, Y') }} — {{ $e->format('M d, Y') }}
            </div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                Generated: {{ now()->format('M d, Y h:i A') }}
            </div>
        </div>
    </div>

    {{-- Print Summary --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #22c55e;background:#f0fdf4;border-radius:8px;padding:12px 14px;">
            <div class="sr-print-label">Total Sales</div>
            <div style="font-size:20px;font-weight:600;color:#16a34a;">₱{{ number_format($totalSales, 2) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #3b82f6;background:#eff6ff;border-radius:8px;padding:12px 14px;">
            <div class="sr-print-label">Units Sold</div>
            <div style="font-size:20px;font-weight:600;color:#3b82f6;">{{ number_format($totalUnits) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #f59e0b;background:#fffbeb;border-radius:8px;padding:12px 14px;">
            <div class="sr-print-label">Average per Trading Day</div>
            <div style="font-size:20px;font-weight:600;color:#b45309;">
                &#8369;{{ $activeDays > 0 ? number_format($totalSales / $activeDays, 2) : '0.00' }}
            </div>
        </div>
    </div>

    {{-- PRINT TABLE 1: where the total comes from. Same rows, figures and
         footer as the screen table, so the printed total matches the printed
         KPI -- and a period whose sales are all imported still prints its
         breakdown rather than "No transactions found". --}}
    <div class="print-section-title sr-print-title">
        Sales {{ $granularity === 'day' ? 'per day' : 'per month' }} &mdash; {{ $s->format('M d, Y') }} to {{ $e->format('M d, Y') }}
        <span>(imported sales record + this terminal)</span>
    </div>

    @if($dailyBreakdown->isNotEmpty())
    <div class="table-scroll"><table class="sr-print" style="margin-bottom:26px;">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ $granularity === 'day' ? 'Date' : 'Month' }}</th>
                <th class="num">Units</th>
                <th class="num">Imported</th>
                <th class="num">This terminal</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dailyBreakdown as $row)
                @php
                    $rowTotal = (float) ($row['revenue'] ?? 0);
                    $rowPos = (float) ($posByBucket[$row['key']] ?? 0);
                    $rowHistory = max(0, round($rowTotal - $rowPos, 2));
                @endphp
            <tr>
                <td class="idx">{{ $loop->iteration }}</td>
                <td class="strong">{{ $row['label'] }}</td>
                <td class="num">{{ number_format($row['units'] ?? 0) }}</td>
                <td class="num">&#8369;{{ number_format($rowHistory, 2) }}</td>
                <td class="num">&#8369;{{ number_format($rowPos, 2) }}</td>
                <td class="num tot">&#8369;{{ number_format($rowTotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="num strong">Total</td>
                <td class="num">&#8369;{{ number_format($historyTotal, 2) }}</td>
                <td class="num">&#8369;{{ number_format($posTotal, 2) }}</td>
                <td class="num tot" style="font-weight:700;font-size:13px;">&#8369;{{ number_format($totalSales, 2) }}</td>
            </tr>
        </tfoot>
    </table></div>
    @else
    <p style="margin:0 0 26px;padding:14px;border:1px solid #e5e7eb;text-align:center;color:#6b7280;font-size:12px;">
        No sales recorded in this period.
    </p>
    @endif

    {{-- PRINT TABLE 2: the POS transactions, and ONLY when there are some.
         Scoped mode prints LINE ITEMS instead of whole transactions -- a
         transaction can carry OTHER products too, and listing its full total
         would overstate what this product/category earned. --}}
    @if($isScoped)
        @if($scopedItems->isNotEmpty())
        <div class="print-section-title sr-print-title">
            Line items for {{ $scopeProduct ? $scopeProduct->name : $scopeCategory->name }} &mdash; {{ $s->format('M d, Y') }} to {{ $e->format('M d, Y') }}
            @if($totalTransactions > 0)
                <span>(across {{ number_format($totalTransactions) }} {{ Str::plural('transaction', $totalTransactions) }})</span>
            @endif
            @if($scopedItems->count() > $scopedItemsForPrint->count())
                <span>(the first {{ number_format($scopedItemsForPrint->count()) }} of {{ number_format($scopedItems->count()) }}; the total below covers all of them)</span>
            @endif
        </div>

        <div class="table-scroll"><table class="sr-print">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Transaction No</th>
                    <th>Date</th>
                    @unless($scopeProduct)<th>Product</th>@endunless
                    <th>Cashier</th>
                    <th class="num">Qty</th>
                    <th class="num">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($scopedItemsForPrint as $item)
                <tr>
                    <td class="idx">{{ $loop->iteration }}</td>
                    <td class="strong">{{ $item->sale->transaction_no }}</td>
                    <td>{{ $item->sale->created_at->format('M d, Y h:i A') }}</td>
                    @unless($scopeProduct)<td>{{ $item->product->name ?? 'N/A' }}</td>@endunless
                    <td>{{ $item->sale->user->name ?? 'N/A' }}</td>
                    <td class="num">{{ number_format($item->quantity) }}</td>
                    <td class="num tot">₱{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    {{-- $listedPosTotal: the list is capped and cashier-
                         filtered, and this footer must equal what's listed. --}}
                    <td colspan="{{ $scopeProduct ? 5 : 6 }}" class="num strong">
                        Grand Total &mdash; all {{ number_format($scopedItems->count()) }} {{ Str::plural('line item', $scopedItems->count()) }}
                    </td>
                    <td class="num tot" style="font-size:13px;">&#8369;{{ number_format($listedPosTotal, 2) }}</td>
                </tr>
            </tfoot>
        </table></div>
        @endif
    @elseif($sales->isNotEmpty())
    <div class="print-section-title sr-print-title">
        Point-of-sale transactions recorded on this terminal &mdash; {{ $s->format('M d, Y') }} to {{ $e->format('M d, Y') }}
        @if($totalTransactions > $salesForPrint->count())
            <span>(the first {{ number_format($salesForPrint->count()) }} of {{ number_format($totalTransactions) }}; the total below covers all of them)</span>
        @endif
    </div>

    <div class="table-scroll"><table class="sr-print">
        <thead>
            <tr>
                <th>#</th>
                <th>Transaction No</th>
                <th>Date</th>
                <th>Cashier</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($salesForPrint as $sale)
            <tr>
                <td class="idx">{{ $loop->iteration }}</td>
                <td class="strong">{{ $sale->transaction_no }}</td>
                <td>{{ $sale->created_at->format('M d, Y h:i A') }}</td>
                <td>{{ $sale->user->name ?? 'N/A' }}</td>
                <td class="num tot">₱{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        @if($totalTransactions > 0)
        <tfoot>
            <tr>
                <td colspan="4" class="num strong">
                    Grand Total &mdash; all {{ number_format($totalTransactions) }} {{ Str::plural('transaction', $totalTransactions) }}
                </td>
                <td class="num tot" style="font-size:13px;">&#8369;{{ number_format($listedPosTotal, 2) }}</td>
            </tr>
        </tfoot>
        @endif
    </table></div>
    @endif

    {{-- Print Footer --}}
    <div style="margin-top:40px;padding-top:12px;border-top:0.5px solid #e5e7eb;display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;">
        <span>REMEDI Point of Sale System</span>
        <span>Printed by: {{ auth()->user()->name ?? 'Admin' }} &nbsp;|&nbsp; {{ now()->format('M d, Y h:i A') }}</span>
    </div>

</div>{{-- end #print-area --}}

{{-- Chart data for renderSalesCharts() in reports/sales.blade.php. Data, not
     a script: a swapped-in <script> would never run, and this way the chart
     code exists once rather than once per refresh. --}}
@php
    $chartData = [
        'daily' => $dailyBreakdown->isNotEmpty() ? [
            'labels' => $dailyBreakdown->pluck('label'),
            'revenue' => $dailyBreakdown->pluck('revenue'),
        ] : null,
        'hourly' => ($totalTransactions > 0 && ! $isScoped) ? [
            'labels' => $hourlyBreakdown->pluck('label'),
            'revenue' => $hourlyBreakdown->pluck('revenue'),
            'transactions' => $hourlyBreakdown->pluck('transactions'),
        ] : null,
    ];
@endphp
{{-- Not @json(): Blade splits its argument on commas, which breaks an array literal. --}}
<script type="application/json" id="salesChartData">{!! json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
