@extends('layouts.app')

@section('title', 'Inventory Report')

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
    #no-print, .no-print { display: none !important; }

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

/* Both tables below are styled from here rather than from a style="" on every
   cell, and that is a page-weight decision, not a tidiness one. This report is
   the whole catalogue rendered TWICE -- the capped screen table and the
   unpaginated print copy -- so a style attribute on a cell is paid for ~5,300
   times. Measured before this change: 58,360 style attributes totalling
   3.35 MB, of a 7.6 MB page.

   Row hover paints the CELLS, not the row (see REMEDI.md "Table row hover"),
   which is also why the two onmouseover/onmouseout handlers that used to ride
   on every row are gone: 5,276 of them, 216 KB, doing what one CSS rule does. */

/* Screen copy */
.inv-rep { width: 100%; min-width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
.inv-rep thead tr { background: #f9fafb; }
.inv-rep th {
    padding: 9px 11px; text-align: left; font-size: 11px; font-weight: 500; color: #6b7280;
    text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 0.5px solid #e5e7eb;
}
.inv-rep th:nth-child(1) { width: 4%; }
.inv-rep th:nth-child(2) { width: 31%; }
.inv-rep th:nth-child(3) { width: 15%; }
.inv-rep th:nth-child(4) { width: 11%; }
.inv-rep th:nth-child(5) { width: 12%; }
.inv-rep th:nth-child(6) { width: 14%; }
.inv-rep th:nth-child(7) { width: 13%; }
.inv-rep td { padding: 11px 14px; }
.inv-rep tbody tr { border-bottom: 0.5px solid #e5e7eb; }
.inv-rep tbody tr:hover td { background: #f9fafb; }
.inv-rep .num { text-align: right; }
.inv-rep .mid { text-align: center; }
.inv-rep .idx { color: #9ca3af; font-size: 12px; }
.inv-rep .muted { color: #6b7280; }
.inv-rep .strong { font-weight: 500; color: #111; }
.inv-rep .unit { color: #9ca3af; font-size: 12px; }
.inv-rep .expired-note { font-size: 11px; color: #dc2626; margin-top: 2px; }
.inv-rep .expired-note i { font-size: 11px; }
.inv-rep .empty { padding: 48px; text-align: center; color: #9ca3af; font-size: 14px; }
.inv-rep .empty i { font-size: 28px; display: block; margin-bottom: 8px; }

/* Stock figure. Graphite at zero, matching the alert legend: an empty shelf is
   an absence, not a louder warning. */
.inv-rep .stock { font-weight: 500; color: #111; }
.inv-rep .stock.is-out { color: #334155; }
.inv-rep .stock.is-low { color: #dc2626; }

/* Status badge. The four states are the four the Low Stock filter decided
   between, in the same order -- see the row markup for why that order. */
.inv-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 20px;
}
.inv-badge i { font-size: 11px; }
.inv-badge.is-out { background: #e2e8f0; color: #334155; }
.inv-badge.is-expired { background: #FCEBEB; color: #791F1F; }
.inv-badge.is-low { background: #fee2e2; color: #991b1b; }
.inv-badge.is-ok { background: #EAF3DE; color: #27500A; }

/* Print copy: bordered and zebra-striped, unlike the screen table. */
.inv-print { width: 100%; border-collapse: collapse; font-size: 12px; }
.inv-print thead tr, .inv-print tfoot tr { background: #f3f4f6; }
.inv-print th {
    padding: 9px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #374151;
    text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid #e5e7eb;
}
.inv-print td { padding: 8px 12px; border: 1px solid #e5e7eb; color: #111; }
/* nth-child rather than a $loop->even style attribute on every row. */
.inv-print tbody tr:nth-child(even) { background: #f9fafb; }
.inv-print .num { text-align: right; }
.inv-print .mid { text-align: center; }
.inv-print .idx { color: #9ca3af; font-size: 11px; }
.inv-print .muted { color: #6b7280; }
.inv-print .strong { font-weight: 500; }
.inv-print .expired-note { font-size: 10px; color: #dc2626; }
.inv-print .stock.is-low { color: #dc2626; }
.inv-print .st-low { font-size: 11px; font-weight: 600; color: #dc2626; }
.inv-print .st-ok { font-size: 11px; font-weight: 600; color: #16a34a; }
.inv-print .empty { padding: 24px; text-align: center; color: #9ca3af; }
.inv-print tfoot .label { padding: 9px 12px; font-weight: 600; font-size: 12px; text-align: right; }
.inv-print tfoot .total { padding: 9px 12px; font-weight: 600; font-size: 13px; color: #4f46e5; text-align: right; }

/* Live filters: the report fades while its replacement is on the way. */
#invReportBody { transition: opacity .15s ease; }
#invReportBody.is-updating { opacity: .45; pointer-events: none; }
.inv-status { font-size: 12.5px; color: #64748b; min-height: 18px; display: inline-flex; align-items: center; gap: 6px; align-self: center; }
.inv-status.is-error { color: #b91c1c; }
.inv-status .ti-loader-2 { animation: inv-spin .8s linear infinite; }
@keyframes inv-spin { to { transform: rotate(360deg); } }
#invClear[hidden] { display: none; }
</style>

<div id="invReport">

{{-- ===================== SCREEN ONLY ===================== --}}
<div id="no-print">

    {{-- Page Header. Shared .page-head pattern — see layouts/app.blade.php. --}}
    <div class="page-head">
        <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
        <div class="page-head-text">
            <h3><i class="ti ti-package" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Inventory Report</h3>
            <p>Current stock levels, values, and status for all products</p>
        </div>
    </div>

    {{-- Filter Form. Each control applies itself the moment it changes and
         the report updates IN PLACE (2026-09-28) -- no Apply button, same as
         the Sales Report: the script below fetches reports/_inventory-body
         alone and swaps it in. Still a real GET form, so with JavaScript off
         the <noscript> Apply button submits it. --}}
    <form method="GET" action="{{ route('reports.inventory') }}" id="invFilters"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem;">
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label for="report-category">Category</label>
            <select name="category_id" id="report-category" class="report-select">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ (string) $categoryId === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        {{-- ONE choice, not checkboxes: low stock and expired are kept
             disjoint on purpose (Product::is_running_out), so "both" would ask
             for rows the two KPIs disagree about. OK (2026-09-28) is the
             third: nothing to act on -- ReportController's $isOk. --}}
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label for="report-status">Status</label>
            <select name="status" id="report-status" class="report-select">
                <option value="">All stock</option>
                <option value="ok" {{ $status === 'ok' ? 'selected' : '' }}>OK only</option>
                <option value="low_stock" {{ $status === 'low_stock' ? 'selected' : '' }}>Low stock only</option>
                <option value="expired" {{ $status === 'expired' ? 'selected' : '' }}>Expired only</option>
            </select>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <noscript>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="ti ti-filter" style="font-size:14px;"></i> Apply
                </button>
            </noscript>
            <a href="{{ route('reports.inventory') }}" id="invClear" data-report-nav class="btn btn-clear btn-sm" @unless($categoryId || $status) hidden @endunless>
                <i class="ti ti-filter-off" style="font-size:14px;"></i> Clear
            </a>
            <button type="button" id="invPrint" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" style="font-size:14px;"></i> Print
            </button>
            @php
                $exportParams = array_filter(['category_id' => $categoryId, 'status' => $status]);
            @endphp
            {{-- data-no-skeleton: downloads, not navigations. Kept in step with
                 the report by the live-filter script (state.export_*). --}}
            <a href="{{ route('reports.inventory.export', $exportParams + ['format' => 'xlsx']) }}" id="invExportXlsx" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-spreadsheet" style="font-size:14px;"></i> Excel
            </a>
            <a href="{{ route('reports.inventory.export', $exportParams + ['format' => 'pdf']) }}" id="invExportPdf" class="btn btn-secondary btn-sm" data-no-skeleton>
                <i class="ti ti-file-type-pdf" style="font-size:14px;"></i> PDF
            </a>
        </div>
        <span class="inv-status" id="invStatus" role="status" aria-live="polite"></span>
    </form>

</div>{{-- end #no-print --}}

<div id="invReportBody">
    @include('reports._inventory-body')
</div>

</div>{{-- end #invReport --}}

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
    // The doughnut legends sit to the right on desktop/tablet; a phone has no
    // horizontal room for that, so they drop back underneath.
    Chart.defaults.plugins.legend.position =
        window.matchMedia('(max-width: 767px)').matches ? 'bottom' : 'right';

    /* Both charts, from the JSON the report body carries. Run on load and after
       every in-place refresh -- the one copy of the chart code. */
    var inventoryCharts = [];
    function renderInventoryCharts() {
        inventoryCharts.forEach(function (c) { c.destroy(); });
        inventoryCharts = [];

        var source = document.getElementById('inventoryChartData');
        if (!source || !window.Chart) return;
        var data = JSON.parse(source.textContent);
        // No `position` on the legends: they inherit the matchMedia default above.
        var legend = { align: 'center', labels: { boxWidth: 11, boxHeight: 11, padding: 13, usePointStyle: true, pointStyle: 'circle', font: { size: 11.5 }, color: '#334155' } };
        var layout = { padding: { top: 4, bottom: 4, left: 4, right: 8 } };

        var category = document.getElementById('categoryValueChart');
        if (data.category && category) {
            inventoryCharts.push(new Chart(category, {
                type: 'doughnut',
                data: {
                    labels: data.category.labels,
                    datasets: [{ data: data.category.values, backgroundColor: ['#6366f1', '#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9', '#ec4899'], borderWidth: 0 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%', layout: layout,
                    plugins: {
                        legend: legend,
                        tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ₱' + ctx.parsed.toLocaleString(undefined, { minimumFractionDigits: 2 }); } } },
                    },
                },
            }));
        }

        var health = document.getElementById('stockHealthChart');
        if (health) {
            inventoryCharts.push(new Chart(health, {
                type: 'doughnut',
                data: {
                    labels: ['OK', 'Low Stock', 'Expired'],
                    datasets: [{ data: data.health, backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'], borderWidth: 0 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%', layout: layout,
                    plugins: { legend: legend, tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.parsed; } } } },
                },
            }));
        }
    }
    renderInventoryCharts();

    /* The table's own search box: narrows the rows already on the page, no
       suggestion dropdown (REMEDI.md "Search: live filtering, no dropdown").
       It lives in the report body, so it is wired again after every swap --
       keeping whatever was typed. */
    function initInventorySearch(keep) {
        var input = document.getElementById('report-search');
        var count = document.getElementById('report-count');
        if (!input || !count) return;

        var rows = Array.prototype.slice.call(document.querySelectorAll('#invReportBody .no-print tbody tr[data-search]'));
        var total = parseInt(count.dataset.total || rows.length, 10);

        function apply() {
            var q = input.value.trim().toLowerCase();
            var shown = 0;

            rows.forEach(function (tr) {
                var hit = q === '' || tr.dataset.search.indexOf(q) !== -1;
                tr.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });

            count.textContent = q === ''
                ? total.toLocaleString() + ' products · scroll inside the table'
                : shown.toLocaleString() + ' of ' + total.toLocaleString() + ' products match';
        }

        input.addEventListener('input', apply);
        input.addEventListener('suggest:live', apply);
        input.addEventListener('suggest:choose', function (e) {
            if (e.detail && e.detail.label) { input.value = e.detail.label; }
            apply();
        });
        // Enter would submit the page's filter form and refresh the report.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); apply(); }
        });

        if (keep) {
            input.value = keep;
            apply();
        }
    }
    initInventorySearch('');
</script>
<script>
/* Live filters, the way the Sales Report does it: a change fetches ONLY the
   report body (ReportController::inventory answers AJAX with {html, state}),
   swaps it in and redraws the charts. Clicks are caught on #invReport, below
   the layout's document-level skeleton handler, so a refresh paints no
   navigation skeleton. */
(function () {
    var root = document.getElementById('invReport');
    var form = document.getElementById('invFilters');
    var body = document.getElementById('invReportBody');
    var status = document.getElementById('invStatus');
    if (!root || !form || !body || !window.fetch) return;

    var controller = null;
    var inFlight = null;

    function setStatus(text) {
        status.innerHTML = '';
        if (!text) return;
        var i = document.createElement('i');
        i.className = 'ti ti-loader-2';
        i.setAttribute('aria-hidden', 'true');
        status.appendChild(i);
        status.appendChild(document.createTextNode(text));
    }

    function formUrl() {
        var params = new URLSearchParams();
        if (form.elements.category_id.value) params.set('category_id', form.elements.category_id.value);
        if (form.elements.status.value) params.set('status', form.elements.status.value);
        var qs = params.toString();

        return form.action + (qs ? '?' + qs : '');
    }

    function load(url) {
        if (controller) controller.abort();
        controller = new AbortController();
        var mine = controller;
        var typed = (document.getElementById('report-search') || {}).value || '';
        body.classList.add('is-updating');
        setStatus('Updating…');

        inFlight = fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: mine.signal,
        }).then(function (res) {
            return res.ok ? res.json() : Promise.reject(res);
        }).then(function (data) {
            if (mine !== controller) return;
            if (!data || typeof data.html !== 'string') { window.location.href = url; return; }
            body.innerHTML = data.html;
            var s = data.state;
            form.elements.category_id.value = s.category_id ? String(s.category_id) : '';
            form.elements.status.value = s.status || '';
            document.getElementById('invClear').hidden = !s.filtered;
            document.getElementById('invExportXlsx').href = s.export_xlsx;
            document.getElementById('invExportPdf').href = s.export_pdf;
            renderInventoryCharts();
            initInventorySearch(typed);
            if (window.history.replaceState) window.history.replaceState(null, '', formUrl());
            setStatus('');
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            // A signed-out session or a server error: a real navigation
            // handles every case.
            if (mine === controller) window.location.href = url;
        }).finally(function () {
            if (mine === controller) {
                body.classList.remove('is-updating');
                controller = null;
                inFlight = null;
            }
        });
    }

    form.addEventListener('change', function (e) {
        if (e.target.name === 'category_id' || e.target.name === 'status') load(formUrl());
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); load(formUrl()); });

    // Clear.
    root.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-report-nav]');
        if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        form.elements.category_id.value = '';
        form.elements.status.value = '';
        load(link.href);
    });

    // Print what is on screen: wait for a refresh that is on its way.
    document.getElementById('invPrint').addEventListener('click', function () {
        if (inFlight) inFlight.then(function () { window.print(); }); else window.print();
    });
})();
</script>

@endsection
