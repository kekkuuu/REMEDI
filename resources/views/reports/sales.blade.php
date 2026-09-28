@extends('layouts.app')

@section('title', 'Sales Report')

@section('content')

<style>
/* Period: a segmented control sized to sit level with .report-select
   (36px, same border and radius) so the buttons and the pickers read as one
   row of filters. */
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

/* Live filters: the report fades while its replacement is on the way, and
   the status line says so. The old figures stay readable underneath rather
   than blanking, since most refreshes land in well under a second. */
#salesReportBody { transition: opacity .15s ease; }
#salesReportBody.is-updating { opacity: .45; pointer-events: none; }
.sr-status { font-size: 12.5px; color: #64748b; min-height: 18px; display: inline-flex; align-items: center; gap: 6px; }
.sr-status.is-error { color: #b91c1c; }
.sr-status .ti-loader-2 { animation: sr-spin .8s linear infinite; }
@keyframes sr-spin { to { transform: rotate(360deg); } }
.report-select.is-invalid { border-color: #ef4444; }
/* `.btn` sets display as an author rule, which beats the `hidden`
   attribute's UA display:none -- same trap as #confirmModalConfirm. */
#salesClear[hidden] { display: none; }

/* The report body (reports/_sales-body). Class-based, not inline: the
   breakdown renders on screen AND in the print copy, and per-cell inline
   styles were most of what every filter change downloaded. */
.sr-card { border: 0.5px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #fff; margin-bottom: 1.5rem; }
.sr-pad { padding: 16px; }
.sr-card-head { padding: 14px 16px; border-bottom: 0.5px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.sr-card-title { font-size: 14px; font-weight: 500; color: #111; }
.sr-card-title i { font-size: 14px; vertical-align: -1px; margin-right: 6px; color: #185FA5; }
.sr-card-note { font-size: 12px; color: #6b7280; }
.sr-empty { color: #9ca3af; font-size: 13px; text-align: center; padding: 24px 0; margin: 0; }
.sr-banner { margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; font-size: 13px; color: #1e40af; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sr-muted { color: #64748b; }
.sr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sr-table th { padding: 9px 14px; text-align: left; font-size: 11px; font-weight: 500; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: .5px solid #e5e7eb; background: #f9fafb; }
.sr-table td { padding: 10px 14px; color: #6b7280; }
.sr-table tbody tr { border-bottom: .5px solid #e5e7eb; }
.sr-table tfoot td { background: #f9fafb; border-top: .5px solid #e5e7eb; font-weight: 600; }
.sr-table .idx { width: 38px; color: #9ca3af; font-size: 12px; }
.sr-table .lbl { color: #374151; }
.sr-table .num { text-align: right; }
.sr-table .pos { color: #2563eb; }
.sr-table .nil { color: #cbd5e1; }
.sr-table .tot { color: #16a34a; font-weight: 600; }

.sr-print-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1.5px solid #111; }
.sr-print-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .04em; }
.sr-print-title { font-size: 13px; font-weight: 500; color: #111; margin-bottom: 10px; }
.sr-print-title span { color: #6b7280; font-weight: 400; }
.sr-print { width: 100%; border-collapse: collapse; font-size: 12px; }
.sr-print th { padding: 9px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .04em; border: 1px solid #e5e7eb; background: #f3f4f6; }
.sr-print td { padding: 8px 12px; border: 1px solid #e5e7eb; color: #6b7280; }
.sr-print tbody tr:nth-child(even) td { background: #f9fafb; }
.sr-print tfoot td { background: #f3f4f6; }
.sr-print .num { text-align: right; }
.sr-print .idx { color: #9ca3af; font-size: 11px; }
.sr-print .strong { color: #111; font-weight: 500; }
.sr-print tfoot .strong { font-weight: 600; }
.sr-print .tot { color: #16a34a; font-weight: 600; }

/* The printable report is a second, unpaginated copy of the whole dataset --
   useful on paper, ruinous on screen. */
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
    #no-print, .no-print { display: none !important; }

    @page {
        size: A4;
        margin: 14mm 12mm;
    }

    /* Repeat the table header/footer on every printed page */
    table thead { display: table-header-group; }
    table tfoot { display: table-footer-group; }

    /* Never split a row across pages; let long tables flow. */
    table { page-break-inside: auto; }
    table tr { page-break-inside: avoid; break-inside: avoid; }

    /* Keep a section's title glued to the content that follows it */
    .print-section-title { page-break-after: avoid; break-after: avoid; }
}
</style>

<div id="salesReport">

{{-- Controls (hidden on print) --}}
<div id="no-print">

    <div class="page-head">
        <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
        <div class="page-head-text">
            <h3><i class="ti ti-chart-bar" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Sales Report</h3>
            <p>Sales by date range &mdash; the report updates as you change a filter</p>
        </div>
    </div>

    {{-- Filters apply the moment they change (2026-09-28, at the user's
         request): there is no Generate button and no Month picker any more.
         The script at the foot of this view fetches reports/_sales-body alone
         and swaps it in. This is still a real GET form of plain links and
         fields, so with JavaScript off Enter submits it and the period links
         navigate -- the enhancement sits on top of a page that works without
         it. The server still honours ?month= for old bookmarks. --}}
    <form method="GET" action="{{ route('reports.sales') }}" class="report-filters" id="salesFilters"
          data-default-start="{{ $defaultStart }}" data-default-end="{{ $defaultEnd }}">
        {{-- Quick ranges, resolved on the server from today
             (ReportController::periodRange) -- no client-side date arithmetic
             to get wrong across midnight or a timezone. --}}
        @php
            $quickRanges = [
                'daily' => ['Daily', 'ti-calendar-event', 'Today'],
                'weekly' => ['Weekly', 'ti-calendar-week', 'This week, Monday to today'],
                'monthly' => ['Monthly', 'ti-calendar-month', 'This month, 1st to today'],
                'yearly' => ['Yearly', 'ti-calendar-stats', 'This year, January 1 to today'],
            ];
        @endphp
        <input type="hidden" name="period" value="{{ $period }}">
        <div class="report-field">
            <label id="periodLabel">Period</label>
            <div class="period-toggle" role="group" aria-labelledby="periodLabel">
                @foreach($quickRanges as $key => [$label, $icon, $hint])
                    @php [$qs, $qe] = \App\Http\Controllers\ReportController::periodRange($key); @endphp
                    <a href="{{ route('reports.sales', ['period' => $key]) }}" data-report-nav data-period="{{ $key }}"
                       class="period-btn {{ $period === $key ? 'is-active' : '' }}"
                       title="{{ $hint }} ({{ \Carbon\Carbon::parse($qs)->format('M j') }}{{ $qs === $qe ? '' : ' – '.\Carbon\Carbon::parse($qe)->format('M j') }})"
                       @if($period === $key) aria-current="true" @endif>
                        <i class="ti {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- max is today: the imported data can run past it, and a picker
             must not offer days that have not happened. --}}
        <div class="report-field">
            <label for="start_date">Start date</label>
            <input type="date" name="start_date" id="start_date" value="{{ $start }}"
                   min="{{ $dataStart }}" max="{{ min($dataEnd, now()->toDateString()) }}" class="report-select">
        </div>
        <div class="report-field">
            <label for="end_date">End date</label>
            <input type="date" name="end_date" id="end_date" value="{{ $end }}"
                   min="{{ $dataStart }}" max="{{ min($dataEnd, now()->toDateString()) }}" class="report-select">
        </div>

        {{-- Category / Product narrow every figure on the page. A product
             wins over a category, both here (picking one clears the other)
             and on the server. --}}
        <div class="report-field">
            <label for="category_id">Category</label>
            <select name="category_id" id="category_id" class="report-select">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ $scopeCategory?->id === $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="report-field">
            <label for="product_sku">Product</label>
            {{-- <datalist>: the option VALUE is the SKU the server filters on,
                 with the product name as its label. Lists only products that
                 have sold (ReportController::salesFilterOptions). --}}
            <input type="text" name="product_sku" id="product_sku" class="report-select" list="productSkuOptions"
                   value="{{ $scopeProduct->sku ?? '' }}" placeholder="Search by name or SKU…" autocomplete="off">
            <datalist id="productSkuOptions">
                @foreach($productOptions as $p)
                    <option value="{{ $p->sku }}">{{ $p->name }}</option>
                @endforeach
            </datalist>
        </div>

        {{-- Cashier narrows only the POS-only figures -- sales_history has no
             cashier (see buildSalesReportData()'s $scopeCashier comment). --}}
        <div class="report-field">
            <label for="cashier_id">Cashier</label>
            <select name="cashier_id" id="cashier_id" class="report-select">
                <option value="">All cashiers</option>
                @foreach($cashiers as $cashier)
                    <option value="{{ $cashier->id }}" {{ $scopeCashier?->id === $cashier->id ? 'selected' : '' }}>{{ $cashier->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
            <noscript><button type="submit" class="btn btn-primary btn-sm">Apply</button></noscript>
            <a href="{{ route('reports.sales') }}" id="salesClear" data-report-nav class="btn btn-clear btn-sm" @unless($isFiltered) hidden @endunless><i class="ti ti-filter-off" aria-hidden="true"></i> Clear</a>
            <button type="button" id="printReport" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" aria-hidden="true"></i> Print
            </button>
            {{-- data-no-skeleton: a download, not a navigation -- without it
                 the layout's "Loading…" pill never clears. Kept in step with
                 the report by the live-filter script (state.export_*). --}}
            <a href="{{ route('reports.sales.export', ['format' => 'xlsx'] + $exportParams) }}" id="exportXlsx" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-spreadsheet" aria-hidden="true"></i> Excel
            </a>
            <a href="{{ route('reports.sales.export', ['format' => 'pdf'] + $exportParams) }}" id="exportPdf" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-type-pdf" aria-hidden="true"></i> PDF
            </a>
        </div>

        <span class="sr-status" id="salesStatus" role="status" aria-live="polite"></span>
    </form>

</div>{{-- end #no-print --}}

<div id="salesReportBody">
    @include('reports._sales-body')
</div>

</div>{{-- end #salesReport --}}

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
/* Gradient fill for a chart series. Chart.js calls this per element with the
   chart area available; before the first layout pass chartArea is undefined,
   hence the flat-colour fallback (returning undefined paints the bars black). */
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

/* Draws both charts from the JSON the report body carries. Run on load and
   again after every in-place refresh -- the one copy of the chart code, since
   a <script> swapped in with innerHTML never executes. */
var salesCharts = [];
function renderSalesCharts() {
    salesCharts.forEach(function (c) { c.destroy(); });
    salesCharts = [];

    var source = document.getElementById('salesChartData');
    if (!source || !window.Chart) return;
    var data = JSON.parse(source.textContent);
    var money = function (v) { return '₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2 }); };
    var yAxis = {
        beginAtZero: true,
        ticks: { color: '#9ca3af', font: { size: 11 }, callback: function (v) { return '₱' + v.toLocaleString(); } },
        grid: { color: '#f1f5f9' },
    };

    // Bars with a trend line over them -- the line summarises the SAME
    // series, so the direction of the movement reads across the range.
    var daily = document.getElementById('dailySalesChart');
    if (data.daily && daily) {
        salesCharts.push(new Chart(daily, {
            type: 'bar',
            data: {
                labels: data.daily.labels,
                datasets: [{
                    label: 'Sales',
                    data: data.daily.revenue,
                    backgroundColor: function (ctx) { return chartGradient(ctx, '#3b82f6', false); },
                    borderRadius: 4,
                    maxBarThickness: 36,
                    order: 1,
                }, {
                    type: 'line',
                    label: 'Trend',
                    data: data.daily.revenue,
                    borderColor: '#1d4ed8',
                    backgroundColor: '#1d4ed8',
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#1d4ed8',
                    fill: false,
                    order: 0,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return money(ctx.parsed.y); } } },
                },
                scales: { y: yAxis, x: { ticks: { color: '#374151', font: { size: 11 } }, grid: { display: false } } },
            },
        }));
    }

    // Hourly: transaction count rides along in the tooltip rather than as a
    // second dataset at a different scale.
    var hourly = document.getElementById('hourlySalesChart');
    if (data.hourly && hourly) {
        salesCharts.push(new Chart(hourly, {
            type: 'bar',
            data: {
                labels: data.hourly.labels,
                datasets: [{
                    label: 'Revenue',
                    data: data.hourly.revenue,
                    backgroundColor: function (ctx) { return chartGradient(ctx, '#0ea5e9', false); },
                    borderRadius: 4,
                    maxBarThickness: 28,
                    order: 1,
                }, {
                    type: 'line',
                    label: 'Trend',
                    data: data.hourly.revenue,
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
                            label: function (ctx) { return money(ctx.parsed.y); },
                            afterLabel: function (ctx) { return data.hourly.transactions[ctx.dataIndex] + ' transaction(s)'; },
                        },
                    },
                },
                scales: { y: yAxis, x: { ticks: { color: '#374151', font: { size: 10 } }, grid: { display: false } } },
            },
        }));
    }
}
renderSalesCharts();

/* Live filters.
 *
 * Every control applies itself: a period button, either date, the category,
 * the product (once it names a real product) and the cashier. Each change
 * fetches ONLY the report body -- ReportController::sales() answers an AJAX
 * request with {html, state} -- so a refresh moves a fraction of the full
 * page (no layout, no filter bar, no product list). The filter bar then
 * re-syncs from `state`, because the SERVER resolves the range: a period
 * becomes dates, a reversed pair is reordered, a future day is clamped, and
 * the boxes must show the range that was actually queried.
 *
 * Clicks are caught on #salesReport, below the document-level handler in
 * layouts/app.blade.php that paints the navigation skeleton: preventDefault
 * here makes that handler stand down, so no skeleton appears for a refresh
 * that never leaves the page.
 */
(function () {
    var root = document.getElementById('salesReport');
    var form = document.getElementById('salesFilters');
    var body = document.getElementById('salesReportBody');
    var status = document.getElementById('salesStatus');
    var printBtn = document.getElementById('printReport');
    if (!root || !form || !body || !window.fetch) return;

    var f = form.elements;
    var controller = null;
    var inFlight = null;
    var dateTimer = null;
    var lastUrl = null;
    var skus = new Set(Array.prototype.map.call(
        document.querySelectorAll('#productSkuOptions option'), function (o) { return o.value; }));

    function setStatus(text, isError, busy) {
        status.classList.toggle('is-error', !!isError);
        status.innerHTML = '';
        if (!text) return;
        if (busy) {
            var i = document.createElement('i');
            i.className = 'ti ti-loader-2';
            i.setAttribute('aria-hidden', 'true');
            status.appendChild(i);
        }
        status.appendChild(document.createTextNode(text));
    }

    // The URL for what the form currently says. A period stands in for dates
    // (sending both would let the dates win and switch the period off), and
    // dates equal to the unfiltered default are left out to keep URLs clean.
    function formUrl() {
        var params = new URLSearchParams();
        if (f.period.value) {
            params.set('period', f.period.value);
        } else if (f.start_date.value !== form.dataset.defaultStart || f.end_date.value !== form.dataset.defaultEnd) {
            if (f.start_date.value) params.set('start_date', f.start_date.value);
            if (f.end_date.value) params.set('end_date', f.end_date.value);
        }
        ['category_id', 'product_sku', 'cashier_id'].forEach(function (name) {
            if (f[name].value) params.set(name, f[name].value.trim());
        });
        var qs = params.toString();

        return form.action + (qs ? '?' + qs : '');
    }

    function syncForm(state) {
        f.start_date.value = state.start;
        f.end_date.value = state.end;
        f.period.value = state.period || '';
        f.category_id.value = state.category_id ? String(state.category_id) : '';
        f.product_sku.value = state.product_sku || '';
        f.product_sku.classList.remove('is-invalid');
        f.cashier_id.value = state.cashier_id ? String(state.cashier_id) : '';
        document.querySelectorAll('.period-btn').forEach(function (btn) {
            var on = btn.dataset.period === state.period;
            btn.classList.toggle('is-active', on);
            if (on) btn.setAttribute('aria-current', 'true'); else btn.removeAttribute('aria-current');
        });
        document.getElementById('salesClear').hidden = !state.filtered;
        document.getElementById('exportXlsx').href = state.export_xlsx;
        document.getElementById('exportPdf').href = state.export_pdf;
    }

    function load(url) {
        // A datalist pick raises `input` and then `change` on blur -- one
        // filter, one request.
        if (url === lastUrl) return inFlight || Promise.resolve();
        lastUrl = url;
        if (controller) controller.abort();
        controller = new AbortController();
        var mine = controller;
        body.classList.add('is-updating');
        setStatus('Updating…', false, true);

        inFlight = fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: mine.signal,
        }).then(function (res) {
            return res.json().then(function (data) { return { res: res, data: data }; }, function () { return { res: res, data: null }; });
        }).then(function (r) {
            if (mine !== controller) return;
            if (!r.res.ok) lastUrl = null;
            if (r.res.status === 422 && r.data) {
                var errs = r.data.errors ? Object.values(r.data.errors).flat() : [];
                setStatus(errs[0] || r.data.message || 'That filter could not be applied.', true);
                return;
            }
            if (!r.res.ok || !r.data || typeof r.data.html !== 'string') {
                // Anything unexpected (a signed-out session, a server error):
                // fall back to a real navigation, which handles every case.
                window.location.href = url;
                return;
            }
            body.innerHTML = r.data.html;
            syncForm(r.data.state);
            renderSalesCharts();
            // The address bar records the range as RESOLVED (a reversed pair
            // reordered, a future day clamped), so a refresh reproduces it.
            lastUrl = formUrl();
            if (window.history.replaceState) window.history.replaceState(null, '', lastUrl);
            setStatus('');
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            lastUrl = null;
            if (mine === controller) setStatus('Could not update the report. Check the connection and try again.', true);
        }).finally(function () {
            if (mine === controller) {
                body.classList.remove('is-updating');
                controller = null;
                inFlight = null;
            }
        });

        return inFlight;
    }

    // Period buttons, Clear, and the banner's "Clear filter" link.
    lastUrl = formUrl();

    root.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-report-nav]');
        if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        load(link.href);
    });

    form.addEventListener('change', function (e) {
        var name = e.target.name;

        if (name === 'start_date' || name === 'end_date') {
            // A date the person set replaces any quick period. Debounced:
            // typing a date fires a change per segment.
            f.period.value = '';
            clearTimeout(dateTimer);
            dateTimer = setTimeout(function () {
                dateTimer = null;
                if (f.start_date.value && f.end_date.value) load(formUrl());
            }, 350);
            return;
        }

        if (name === 'product_sku') {
            var sku = f.product_sku.value.trim();
            // Only a real product, or empty. A half-typed name is not a
            // filter yet, and would come back as a validation error.
            if (sku !== '' && !skus.has(sku)) {
                f.product_sku.classList.add('is-invalid');
                setStatus('Pick a product from the list.', true);
                return;
            }
            f.product_sku.classList.remove('is-invalid');
            if (sku !== '') f.category_id.value = '';
        }

        if (name === 'category_id' && f.category_id.value) f.product_sku.value = '';

        if (name === 'category_id' || name === 'product_sku' || name === 'cashier_id') load(formUrl());
    });

    // A datalist pick fires `input` before `change` in some browsers; apply
    // it straight away rather than waiting for the field to lose focus.
    f.product_sku.addEventListener('input', function () {
        var sku = f.product_sku.value.trim();
        if (skus.has(sku)) f.product_sku.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Enter in any field applies the form as it stands, instead of reloading.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(dateTimer);
        var sku = f.product_sku.value.trim();
        if (sku !== '' && !skus.has(sku)) {
            f.product_sku.classList.add('is-invalid');
            setStatus('Pick a product from the list.', true);
            return;
        }
        if (f.start_date.value && f.end_date.value) load(formUrl());
    });

    // Print what the controls say: a date edit still waiting out its
    // debounce is applied now, and a refresh on its way is waited for.
    printBtn.addEventListener('click', function () {
        if (dateTimer) {
            clearTimeout(dateTimer);
            dateTimer = null;
            if (f.start_date.value && f.end_date.value) load(formUrl());
        }
        if (inFlight) {
            inFlight.then(function () { window.print(); });
        } else {
            window.print();
        }
    });
})();
</script>

@endsection
