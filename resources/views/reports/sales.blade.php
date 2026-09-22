@extends('layouts.app')

@section('title', 'Sales Report')

@section('content')

<style>
/* The printable report is a second, unpaginated copy of the whole dataset.
   Useful on paper, ruinous on screen — it made the page ~167 viewports tall.
   Screen uses the capped table above; this exists only for the printout. */
/* Period: a segmented control sized to sit level with .report-select
   (36px, same border and radius) so the four buttons and the three pickers
   read as one row of filters. */
.period-toggle {
    display: inline-flex;
    height: 36px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    background: #fff;
    overflow: hidden;
}
.period-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 14px;
    font-size: 13.5px;
    font-weight: 500;
    color: #334155;
    text-decoration: none;
    white-space: nowrap;
    transition: background .15s ease, color .15s ease;
}
.period-btn + .period-btn { border-left: 1px solid #d1d5db; }
.period-btn i { font-size: 15px; color: #64748b; }
.period-btn:hover { background: #f1f5f9; }
.period-btn.is-active { background: var(--brand, #10b981); color: #fff; }
.period-btn.is-active i { color: #fff; }
.period-btn:focus-visible { outline: 2px solid var(--brand, #10b981); outline-offset: -2px; }
@media (max-width: 767px) {
    /* The shared .report-field goes full width on phones; let the segments
       share it equally instead of huddling at the left. */
    .period-toggle { display: flex; width: 100%; }
    .period-btn { flex: 1 1 0; padding: 0 6px; }
    .period-btn i { display: none; }
}
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

    {{-- Page Header. Shared .page-head pattern (layouts/app.blade.php) rather
         than this page's own flex block, so Back lines up with the title the
         same way it does on every other page. --}}
    <div class="page-head">
        <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
        <div class="page-head-text">
            <h3><i class="ti ti-chart-bar" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Sales Report</h3>
            <p>Generate and export sales transactions by date range</p>
        </div>
    </div>

    {{-- Filters. The month picker is the primary control (the data spans
         2024-2026); the date inputs remain for arbitrary ranges. Choosing a
         month overrides the dates server-side. --}}
    <form method="GET" action="{{ route('reports.sales') }}" class="report-filters" id="salesFilters">
        {{-- Quick ranges, in the filter bar with the controls they stand in for.
             Plain links carrying only `period`; the server resolves them from
             today (ReportController::periodRange), so there is no client-side
             date arithmetic to get wrong across midnight or a timezone. Each
             says what it covers on hover, so "Weekly" is never a guess. The
             month picker and the date inputs beside them remain for anything
             else. Links, not form controls: clicking one is a navigation, and
             it works with JavaScript off. --}}
        @php
            $quickRanges = [
                'daily' => ['Daily', 'ti-calendar-event', 'Today'],
                'weekly' => ['Weekly', 'ti-calendar-week', 'This week, Monday to today'],
                'monthly' => ['Monthly', 'ti-calendar-month', 'This month, 1st to today'],
                'yearly' => ['Yearly', 'ti-calendar-stats', 'This year, January 1 to today'],
            ];
        @endphp
        <div class="report-field">
            <label id="periodLabel">Period</label>
            <div class="period-toggle" role="group" aria-labelledby="periodLabel">
                @foreach($quickRanges as $key => [$label, $icon, $hint])
                    @php [$qs, $qe] = \App\Http\Controllers\ReportController::periodRange($key); @endphp
                    <a href="{{ route('reports.sales', ['period' => $key]) }}"
                       class="period-btn {{ $period === $key ? 'is-active' : '' }}"
                       title="{{ $hint }} ({{ \Carbon\Carbon::parse($qs)->format('M j') }}{{ $qs === $qe ? '' : ' – '.\Carbon\Carbon::parse($qe)->format('M j') }})"
                       @if($period === $key) aria-current="true" @endif>
                        <i class="ti {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="report-field">
            <label>Month</label>
            {{-- Choosing a month clears the date inputs before submitting. The
                 form posts every field it owns, so a month left selected used
                 to ride along with a later date edit and win on the server --
                 which snapped the start date back to the 1st and made the date
                 pickers look broken. --}}
            @php
                // Is a CUSTOM range driving this report? The month control has
                // no value of its own then, and a <select> with nothing
                // selected displays its first option -- so after generating a
                // single day the picker sat there reading "All time (Sep 2022 -
                // Sep 2026)" above a report showing one date. The figures were
                // right; the control was describing a filter that was not in
                // force.
                //
                // Same empty value as All time, and NOT disabled: submitting
                // the form untouched must still let the dates drive, and
                // choosing All time must still clear them.
                $customRange = ! $month && ! ($start === $dataStart && $end === $dataEnd);

                // When a quick range drives the report the label says which, so
                // the picker reads "Weekly · Sep 14 – Sep 19", not just a date.
                $periodName = $period ? ucfirst($period).' · ' : 'Custom range · ';

                $customLabel = $start === $end
                    ? \Carbon\Carbon::parse($start)->format('M j, Y')
                    : \Carbon\Carbon::parse($start)->format('M j').' – '.\Carbon\Carbon::parse($end)->format('M j, Y');
            @endphp
            <select name="month" class="report-select"
                    onchange="this.form.start_date.value=''; this.form.end_date.value=''; this.form.submit();">
                @if($customRange)
                    <option value="" selected>{{ $periodName }}{{ $customLabel }}</option>
                @endif
                <option value="">All time ({{ \Carbon\Carbon::parse($dataStart)->format('M Y') }} &ndash; {{ \Carbon\Carbon::parse($dataEnd)->format('M Y') }})</option>
                @foreach($months as $m)
                    <option value="{{ $m['ym'] }}" {{ $month === $m['ym'] ? 'selected' : '' }}>{{ $m['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="report-field">
            <label>Start date</label>
            {{-- max is today, not the end of the imported record: that data
                 runs to the end of the current month, so without this the
                 picker offered days that have not happened yet. --}}
            <input type="date" name="start_date" value="{{ $start }}"
                   min="{{ $dataStart }}" max="{{ min($dataEnd, now()->toDateString()) }}"
                   onchange="this.form.month.value='';" class="report-select">
        </div>
        <div class="report-field">
            <label>End date</label>
            <input type="date" name="end_date" value="{{ $end }}"
                   min="{{ $dataStart }}" max="{{ min($dataEnd, now()->toDateString()) }}"
                   onchange="this.form.month.value='';" class="report-select">
        </div>

        {{-- Category / Product -- narrows every figure on the page to sales
             of just that category or product (see
             ReportController::buildSalesReportData()'s $isScoped branch).
             A product picked here wins over a category, both client-side
             (picking one clears the other) and server-side, so the two can
             never fight over which narrows the report. --}}
        <div class="report-field">
            <label for="category_id">Category</label>
            <select name="category_id" id="category_id" class="report-select"
                    onchange="this.form.product_sku.value=''; this.form.submit();">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ $scopeCategory?->id === $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="report-field">
            <label for="product_sku">Product</label>
            {{-- <datalist>, not a <select>: this catalogue runs ~2,600
                 products, and this app's only search component
                 (REMEDI.attachSuggest) live-filters a page's own list rather
                 than resolving a pick to a field -- there's no autocomplete-
                 with-selection component to reuse. The option VALUE is the
                 SKU (what the server actually filters on) with the product
                 NAME as its visible label, so picking a suggestion writes
                 the SKU into the input directly with no extra JS needed to
                 resolve a label back to an id. --}}
            <input type="text" name="product_sku" id="product_sku" class="report-select" list="productSkuOptions"
                   value="{{ $scopeProduct->sku ?? '' }}" placeholder="Search by name or SKU…" autocomplete="off"
                   onchange="this.form.category_id.value=''; this.form.submit();">
            <datalist id="productSkuOptions">
                @foreach($productOptions as $p)
                    <option value="{{ $p->sku }}">{{ $p->name }}</option>
                @endforeach
            </datalist>
        </div>

        {{-- Cashier -- a separate filter dimension from Category/Product:
             sales_history has no cashier at all, so this narrows only the
             POS-only figures the page already keeps apart from the merged
             Total Sales identity (see ReportController::buildSalesReportData()'s
             $scopeCashier comment). A plain <select>, not a typeahead -- the
             staff list is small, unlike the product catalogue. --}}
        <div class="report-field">
            <label for="cashier_id">Cashier</label>
            <select name="cashier_id" id="cashier_id" class="report-select" onchange="this.form.submit();">
                <option value="">All cashiers</option>
                @foreach($cashiers as $cashier)
                    <option value="{{ $cashier->id }}" {{ $scopeCashier?->id === $cashier->id ? 'selected' : '' }}>{{ $cashier->name }}</option>
                @endforeach
            </select>
        </div>
        {{-- flex-wrap: this row held Generate/Clear/Print before -- three
             buttons that fit one line even at 375px. Adding Excel/PDF pushed
             it to five, and without wrap the last one (PDF) ran off the
             right edge with no way to reach it on mobile. Same overflow
             class as the Sales Forecast KPI row and the audit preset
             buttons, both fixed here in this file's own git history. --}}
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti ti-refresh" aria-hidden="true"></i> Generate
            </button>
            @if($month || $period || request('start_date') || request('end_date') || $isScoped || $scopeCashier)
                <a href="{{ route('reports.sales') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
            {{-- No inline window.print(): see the script at the foot of this
                 view. Printing has to regenerate first when the date controls
                 have moved since the report was built, or the paper carries the
                 OLD range under the NEW dates showing in the boxes. --}}
            <button type="button" id="printReport" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" aria-hidden="true"></i> Print
            </button>
            {{-- $start/$end are the already-resolved, clamped dates for THIS
                 report -- whether a month or a custom range drove it -- so the
                 export always matches what is on screen without needing to
                 carry `month` through separately.
                 data-no-skeleton: this link triggers a file download, not a
                 page navigation -- without it the click-guard in
                 layouts/app.blade.php shows the "Loading…" pill expecting a
                 document to replace the page, which never arrives, and the
                 pill is stuck until the next real navigation. Same reason
                 audit.export and admin.backup both carry it. --}}
            @php
                // The already-resolved range PLUS whichever filter is
                // driving the page, so an export can never disagree with
                // what's on screen -- same reasoning $start/$end get passed
                // explicitly rather than re-read from the request.
                $exportParams = ['start_date' => $start, 'end_date' => $end]
                    + ($scopeProduct ? ['product_sku' => $scopeProduct->sku] : [])
                    + ($scopeCategory ? ['category_id' => $scopeCategory->id] : [])
                    + ($scopeCashier ? ['cashier_id' => $scopeCashier->id] : []);
            @endphp
            <a href="{{ route('reports.sales.export', ['format' => 'xlsx'] + $exportParams) }}" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-spreadsheet" aria-hidden="true"></i> Excel
            </a>
            <a href="{{ route('reports.sales.export', ['format' => 'pdf'] + $exportParams) }}" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-type-pdf" aria-hidden="true"></i> PDF
            </a>
        </div>
    </form>

    {{-- Filtered-by banner. Every figure below narrows to this the moment
         it's set -- see ReportController::buildSalesReportData()'s
         $isScoped branch and $scopeCashier comment. --}}
    @if($isScoped || $scopeCashier)
        <div style="margin-bottom:16px;padding:10px 14px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;font-size:13px;color:#1e40af;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <i class="ti ti-filter" aria-hidden="true"></i>
            <span>
                Showing sales for
                @if($isScoped)
                    <strong>{{ $scopeProduct ? $scopeProduct->name : $scopeCategory->name }}</strong>
                    @if($scopeProduct) <span style="color:#64748b;">(SKU {{ $scopeProduct->sku }})</span> @endif
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
            <a href="{{ route('reports.sales', ['start_date' => $start, 'end_date' => $end]) }}" style="margin-left:auto;white-space:nowrap;">Clear filter</a>
        </div>
    @endif

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
            {{-- Say what the figure is made of. It merges the imported sales
                 record with live POS checkouts, but only the POS half can be
                 listed as transactions below -- so without this the KPI and
                 the table looked like a sum that did not add up. --}}
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

        {{-- ATV/ATC are scoped to POS transactions -- sales_history rows are
             imported units with no discrete transaction to divide by, so
             these two describe THIS TERMINAL's till activity, same caveat
             Total Sales already carries for its POS half. Hidden while a
             Category/Product filter is active -- see the banner above. --}}
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

    {{-- Breakdown of the headline figure.

         The transactions table further down lists POS checkouts only, because
         an imported sales_history row has no transaction number or cashier to
         show. That left the biggest part of Total Sales unaccounted for on the
         page -- Aug 21-25 showed P283,265.21 above 16 transactions worth
         P31,195.98, with nothing to say where the rest came from.

         This table is where the two records meet: every bucket in the range
         with its imported and POS halves, and a footer that adds up to the KPI
         exactly. --}}
    @if($dailyBreakdown->isNotEmpty())
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;margin-bottom:1.5rem;">
        <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span style="font-size:14px;font-weight:500;color:#111;">
                <i class="ti ti-list-details" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#185FA5;"></i>
                Where the total comes from
            </span>
            <span style="font-size:12px;color:#6b7280;">
                {{ $granularity === 'day' ? 'per day' : 'per month' }} &middot;
                imported sales record + this terminal
            </span>
        </div>
        <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="width:38px;padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">#</th>
                    <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">{{ $granularity === 'day' ? 'Date' : 'Month' }}</th>
                    <th style="width:90px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Units</th>
                    <th style="width:150px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Imported</th>
                    <th style="width:140px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">This terminal</th>
                    <th style="width:150px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dailyBreakdown as $row)
                    @php
                        $rowTotal = (float) ($row['revenue'] ?? 0);
                        $rowPos = (float) ($posByBucket[$row['key']] ?? 0);
                        // Never render a negative "imported" cell: mergePos adds
                        // POS onto the history figure, so the subtraction is the
                        // right way round, but a rounding cent should not show.
                        $rowHistory = max(0, round($rowTotal - $rowPos, 2));
                    @endphp
                    <tr style="border-bottom:0.5px solid #e5e7eb;">
                        <td style="padding:10px 14px;color:#9ca3af;font-size:12px;">{{ $loop->iteration }}</td>
                        <td style="padding:10px 14px;color:#374151;">{{ $row['label'] }}</td>
                        <td style="padding:10px 14px;text-align:right;color:#6b7280;">{{ number_format($row['units'] ?? 0) }}</td>
                        <td style="padding:10px 14px;text-align:right;color:#6b7280;">&#8369;{{ number_format($rowHistory, 2) }}</td>
                        <td style="padding:10px 14px;text-align:right;color:{{ $rowPos > 0 ? '#2563eb' : '#cbd5e1' }};">&#8369;{{ number_format($rowPos, 2) }}</td>
                        <td style="padding:10px 14px;text-align:right;font-weight:600;color:#16a34a;">&#8369;{{ number_format($rowTotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f9fafb;">
                    <td colspan="3" style="padding:10px 14px;font-weight:600;font-size:12px;color:#111;border-top:0.5px solid #e5e7eb;text-align:right;">Total</td>
                    <td style="padding:10px 14px;text-align:right;font-weight:600;color:#6b7280;border-top:0.5px solid #e5e7eb;">&#8369;{{ number_format($historyTotal, 2) }}</td>
                    <td style="padding:10px 14px;text-align:right;font-weight:600;color:#2563eb;border-top:0.5px solid #e5e7eb;">&#8369;{{ number_format($posTotal, 2) }}</td>
                    <td style="padding:10px 14px;text-align:right;font-weight:700;color:#16a34a;border-top:0.5px solid #e5e7eb;">&#8369;{{ number_format($totalSales, 2) }}</td>
                </tr>
            </tfoot>
        </table></div>
    </div>
    @endif

    {{-- Hourly Sales / Transaction Volume -- POS-only (see buildSalesReportData:
         sales_history carries a DATE per row, never a time, so this is honestly
         this terminal's own pattern, not the four-year imported record's). Peak-
         hour identification is what this exists for, so it stays visible even on
         a quiet range rather than being hidden behind an "if any sales" guard --
         a flat row of zeros IS the answer on a range with no POS activity.

         Hidden while scoped: $hourlyBreakdown is deliberately empty then (see
         the banner above), and this is a whole-till metric same as ATV/ATC. --}}
    @unless($isScoped)
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;margin-bottom:1.5rem;">
        <div style="padding:14px 16px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span style="font-size:14px;font-weight:500;color:#111;">
                <i class="ti ti-clock" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#185FA5;"></i>
                Hourly Sales &amp; Transaction Volume
            </span>
            <span style="font-size:12px;color:#6b7280;">
                {{ $scopeCashier ? $scopeCashier->name.'\'s POS activity only' : 'this terminal\'s POS activity only' }}
            </span>
        </div>
        @if($totalTransactions > 0)
            <div style="padding:16px;">
                <canvas id="hourlySalesChart" height="80"></canvas>
            </div>
        @else
            <p style="color:#9ca3af;font-size:13px;text-align:center;padding:24px 0;">No POS transactions in this date range.</p>
        @endif
    </div>
    @endunless

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
            <div style="font-size:18px;font-weight:600;color:#111;">Sales Report{{ $scopeLabel ? ' — '.$scopeLabel : '' }}</div>
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

    {{-- PRINT TABLE 1: where the total comes from.
         ------------------------------------------------------------------
         This is the table the printout was missing, and its absence is why a
         report for any month other than the current one printed nothing.

         The only table in this print area used to be the POS transactions
         below, and POS rows exist only for the handful of days this terminal
         has actually rung up sales. So printing March -- or any month whose
         sales live entirely in the imported record -- produced KPIs in the
         millions above "No transactions found in this date range.", which
         reads as a broken report rather than as "those sales were imported".

         Same rows, same figures and the same footer as the screen's "Where
         the total comes from" table, so the printed total matches the printed
         KPI exactly. --}}
    <div class="print-section-title" style="font-size:13px;font-weight:500;color:#111;margin-bottom:10px;">
        Sales {{ $granularity === 'day' ? 'per day' : 'per month' }} &mdash; {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
        <span style="color:#6b7280;font-weight:400;">(imported sales record + this terminal)</span>
    </div>

    @if($dailyBreakdown->isNotEmpty())
    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:26px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">{{ $granularity === 'day' ? 'Date' : 'Month' }}</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Units</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Imported</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">This terminal</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dailyBreakdown as $row)
                @php
                    $rowTotal = (float) ($row['revenue'] ?? 0);
                    $rowPos = (float) ($posByBucket[$row['key']] ?? 0);
                    // Same guard as the screen table: mergePos ADDS the POS
                    // figure onto the history one, so the subtraction is the
                    // right way round, but a rounding cent must not print as
                    // a negative "imported" cell.
                    $rowHistory = max(0, round($rowTotal - $rowPos, 2));
                @endphp
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;">{{ $row['label'] }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">{{ number_format($row['units'] ?? 0) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">&#8369;{{ number_format($rowHistory, 2) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">&#8369;{{ number_format($rowPos, 2) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#16a34a;font-weight:600;text-align:right;">&#8369;{{ number_format($rowTotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#f3f4f6;">
                <td colspan="3" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">Total</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">&#8369;{{ number_format($historyTotal, 2) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">&#8369;{{ number_format($posTotal, 2) }}</td>
                <td style="padding:9px 12px;border:1px solid #e5e7eb;text-align:right;font-weight:700;color:#16a34a;font-size:13px;">&#8369;{{ number_format($totalSales, 2) }}</td>
            </tr>
        </tfoot>
    </table></div>
    @else
    <p style="margin:0 0 26px;padding:14px;border:1px solid #e5e7eb;text-align:center;color:#6b7280;font-size:12px;">
        No sales recorded in this period.
    </p>
    @endif

    {{-- PRINT TABLE 2: the POS transactions, and ONLY when there are some.
         An empty "No transactions found" table under a report full of figures
         is what made the printout look broken; a period whose sales are all
         imported simply has no terminal section.

         Scoped mode (Category/Product filter) prints LINE ITEMS instead of
         whole transactions -- $scopedItemsForPrint, not $salesForPrint -- a
         transaction can carry OTHER products too, and listing its full total
         here would overstate what this product/category actually earned. --}}
    @if($isScoped)
        @if($scopedItems->isNotEmpty())
        <div class="print-section-title" style="font-size:13px;font-weight:500;color:#111;margin-bottom:10px;">
            Line items for {{ $scopeProduct ? $scopeProduct->name : $scopeCategory->name }} &mdash; {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
            @if($totalTransactions > 0)
                <span style="color:#6b7280;font-weight:400;">(across {{ number_format($totalTransactions) }} {{ Str::plural('transaction', $totalTransactions) }})</span>
            @endif
            @if($scopedItems->count() > $scopedItemsForPrint->count())
                <span style="color:#6b7280;font-weight:400;">(the first {{ number_format($scopedItemsForPrint->count()) }} of {{ number_format($scopedItems->count()) }}; the total below covers all of them)</span>
            @endif
        </div>

        <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="background:#f3f4f6;">
                    <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                    <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Transaction No</th>
                    <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Date</th>
                    @unless($scopeProduct)
                        <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Product</th>
                    @endunless
                    <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Cashier</th>
                    <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Qty</th>
                    <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($scopedItemsForPrint as $item)
                <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;">{{ $item->sale->transaction_no }}</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $item->sale->created_at->format('M d, Y h:i A') }}</td>
                    @unless($scopeProduct)
                        <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $item->product->name ?? 'N/A' }}</td>
                    @endunless
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $item->sale->user->name ?? 'N/A' }}</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">{{ number_format($item->quantity) }}</td>
                    <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#16a34a;font-weight:500;text-align:right;">₱{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f3f4f6;">
                    {{-- $listedPosTotal, not $posTotal or a sum of the PRINTED
                         rows: the list is capped and the total is not, and
                         $posTotal is the whole (cashier-blind) scope total --
                         this footer must equal what's actually listed above
                         it, narrowed to the cashier filter when one is set. --}}
                    <td colspan="{{ $scopeProduct ? 5 : 6 }}" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">
                        Grand Total &mdash; all {{ number_format($scopedItems->count()) }} {{ Str::plural('line item', $scopedItems->count()) }}
                    </td>
                    <td style="padding:9px 12px;font-weight:600;font-size:13px;color:#16a34a;border:1px solid #e5e7eb;text-align:right;">&#8369;{{ number_format($listedPosTotal, 2) }}</td>
                </tr>
            </tfoot>
        </table></div>
        @endif
    @elseif($sales->isNotEmpty())
    <div class="print-section-title" style="font-size:13px;font-weight:500;color:#111;margin-bottom:10px;">
        Point-of-sale transactions recorded on this terminal &mdash; {{ \Carbon\Carbon::parse($start)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($end)->format('M d, Y') }}
        @if($totalTransactions > $salesForPrint->count())
            <span style="color:#6b7280;font-weight:400;">(the first {{ number_format($salesForPrint->count()) }} of {{ number_format($totalTransactions) }}; the total below covers all of them)</span>
        @endif
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
            @foreach($salesForPrint as $i => $sale)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;">{{ $sale->transaction_no }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $sale->created_at->format('M d, Y h:i A') }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $sale->user->name ?? 'N/A' }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#16a34a;font-weight:500;text-align:right;">₱{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        @if($totalTransactions > 0)
        <tfoot>
            <tr style="background:#f3f4f6;">
                {{-- $listedPosTotal, not $posTotal: see the comment on the
                     Line items footer above -- same reasoning, unscoped. --}}
                <td colspan="4" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">
                    Grand Total &mdash; all {{ number_format($totalTransactions) }} {{ Str::plural('transaction', $totalTransactions) }}
                </td>
                <td style="padding:9px 12px;font-weight:600;font-size:13px;color:#16a34a;border:1px solid #e5e7eb;text-align:right;">&#8369;{{ number_format($listedPosTotal, 2) }}</td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
    /* Gradient fill for a chart series.

       `horizontal` follows indexAxis: a column chart fades from the value end
       down to the baseline, a horizontal bar chart from the baseline out to the
       value end -- so the fade always runs ALONG the bar rather than across it,
       which is what makes it read as depth instead of a stripe.

       Chart.js calls this per element with the chart area available; before the
       first layout pass chartArea is undefined, hence the flat-colour fallback
       (returning undefined there paints the bars black). */
    function chartGradient(ctx, color, horizontal) {
        const area = ctx.chart.chartArea;
        if (!area) return color;

        const g = horizontal
            ? ctx.chart.ctx.createLinearGradient(area.left, 0, area.right, 0)
            : ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);

        g.addColorStop(horizontal ? 1 : 0, color);
        g.addColorStop(horizontal ? 0 : 1, color + '55');   // ~33% alpha
        return g;
    }

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
                backgroundColor: (ctx) => chartGradient(ctx, '#3b82f6', false),
                borderRadius: 4,
                maxBarThickness: 36,
                order: 1,   // drawn first, so the trend line sits on top
            },
                // Trend line over the bars. Same series, drawn as a line so
                // the shape of the movement reads across the whole range --
                // bar heights are easy to compare pairwise and hard to read
                // as a direction. `order` puts the line in front of the
                // bars; Chart.js draws higher `order` first.
                {
                    type: 'line',
                    label: 'Trend',
                    data: salesTotals,
                    borderColor: '#1d4ed8',
                    backgroundColor: '#1d4ed8',
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#1d4ed8',
                    fill: false,
                    order: 0,
                },
            ],
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

    {{-- Hourly Sales / Transaction Volume -- same bar+trend-line treatment as
         Daily Sales above: it is a time-ordered series (hour 0-23), which is
         exactly the shape that convention exists for. Transaction count rides
         along as a tooltip line rather than a second dataset, since a trend
         line belongs over the SAME series it summarises, not a different
         metric plotted at a different scale. Guarded on the canvas existing,
         not just $totalTransactions>0: the canvas itself is gone while
         scoped (see the HTML above), and $totalTransactions there counts
         distinct scoped SALES, which can be >0 with $hourlyBreakdown empty. --}}
    @if($totalTransactions > 0 && ! $isScoped)
    const hourlyLabels = {!! json_encode($hourlyBreakdown->pluck('label')) !!};
    const hourlyRevenue = {!! json_encode($hourlyBreakdown->pluck('revenue')) !!};
    const hourlyTransactions = {!! json_encode($hourlyBreakdown->pluck('transactions')) !!};

    new Chart(document.getElementById('hourlySalesChart'), {
        type: 'bar',
        data: {
            labels: hourlyLabels,
            datasets: [{
                label: 'Revenue',
                data: hourlyRevenue,
                backgroundColor: (ctx) => chartGradient(ctx, '#0ea5e9', false),
                borderRadius: 4,
                maxBarThickness: 28,
                order: 1,
            }, {
                type: 'line',
                label: 'Trend',
                data: hourlyRevenue,
                borderColor: '#0369a1',
                backgroundColor: '#0369a1',
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 2,
                pointBackgroundColor: '#0369a1',
                fill: false,
                order: 0,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => '₱' + ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 }),
                        afterLabel: (ctx) => hourlyTransactions[ctx.dataIndex] + ' transaction(s)',
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
                    ticks: { color: '#374151', font: { size: 10 } },
                    grid: { display: false },
                },
            },
        },
    });
    @endif
</script>

<script>
/* Print what you asked for, not what is still on the page.
 *
 * The month picker submits on change, but the two date inputs do not -- they
 * wait for Generate, which is right, since setting a start date should not fire
 * off a report before the end date has been chosen. The cost is that the form
 * and the report can describe different periods: edit the dates, click Print
 * instead of Generate, and the header, the KPIs and the daily breakdown all
 * still carry the PREVIOUS range while the date boxes above them show the new
 * one. Nothing is wrong with the report -- it is an accurate report of a period
 * you are no longer looking at, which is worse than an obviously broken one,
 * because the printout looks finished.
 *
 * So Print regenerates first when the controls have moved, and prints when the
 * new report lands. When nothing has changed it prints immediately, as before.
 */
(function () {
    var form = document.getElementById('salesFilters');
    var button = document.getElementById('printReport');
    if (!form || !button) return;

    function snapshot() {
        return ['month', 'start_date', 'end_date'].map(function (name) {
            return form.elements[name] ? form.elements[name].value : '';
        }).join('|');
    }

    // What the report on screen was actually generated from.
    var generated = snapshot();

    button.addEventListener('click', function () {
        if (snapshot() === generated) {
            window.print();

            return;
        }

        // Round-trip, then print on arrival. A hidden field rather than a URL
        // built by hand, so the form still owns which parameters are sent.
        var flag = document.createElement('input');
        flag.type = 'hidden';
        flag.name = 'print';
        flag.value = '1';
        form.appendChild(flag);
        button.disabled = true;
        form.submit();
    });

    @if(request()->boolean('print'))
        // Arrived from the branch above. Print once the page has settled, then
        // drop the flag from the URL so a refresh does not reprint.
        window.addEventListener('load', function () {
            if (window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('print');
                window.history.replaceState({}, '', url.toString());
            }

            window.print();
        });
    @endif
})();
</script>

@endsection
