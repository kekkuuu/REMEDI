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

                $customLabel = $start === $end
                    ? \Carbon\Carbon::parse($start)->format('M j, Y')
                    : \Carbon\Carbon::parse($start)->format('M j').' – '.\Carbon\Carbon::parse($end)->format('M j, Y');
            @endphp
            <select name="month" class="report-select"
                    onchange="this.form.start_date.value=''; this.form.end_date.value=''; this.form.submit();">
                @if($customRange)
                    <option value="" selected>Custom range &middot; {{ $customLabel }}</option>
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
            @if($month || request('start_date') || request('end_date'))
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
            <a href="{{ route('reports.sales.export', ['format' => 'xlsx', 'start_date' => $start, 'end_date' => $end]) }}" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-spreadsheet" aria-hidden="true"></i> Excel
            </a>
            <a href="{{ route('reports.sales.export', ['format' => 'pdf', 'start_date' => $start, 'end_date' => $end]) }}" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-type-pdf" aria-hidden="true"></i> PDF
            </a>
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
         imported simply has no terminal section. --}}
    @if($sales->isNotEmpty())
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
                {{-- $posTotal, not a sum of the PRINTED rows: the list is capped
                     and the total is not. --}}
                <td colspan="4" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">
                    Grand Total &mdash; all {{ number_format($totalTransactions) }} {{ Str::plural('transaction', $totalTransactions) }}
                </td>
                <td style="padding:9px 12px;font-weight:600;font-size:13px;color:#16a34a;border:1px solid #e5e7eb;text-align:right;">&#8369;{{ number_format($posTotal, 2) }}</td>
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
