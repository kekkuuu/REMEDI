@extends('layouts.app')

@section('title', 'Forecasting')

@section('content')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
@include('partials._chart-gradient')

@php
    // Store-wide trend, same merge-into-one-axis technique the per-product
    // detail chart below uses: actual months from sales_history, continued
    // by the aggregate forecast, stitched at the last actual month so the
    // dashed line starts exactly where the solid one ends.
    $months = $trend['actualUnits']->keys()->merge($trend['forecastUnits']->keys())->unique()->sort()->values();

    $actualUnitsSeries = $months->map(fn ($m) => $trend['actualUnits'][$m] ?? null);
    $forecastUnitsSeries = $months->map(function ($m) use ($trend) {
        if (array_key_exists($m, $trend['forecastUnits']->toArray())) {
            return (float) $trend['forecastUnits'][$m];
        }
        return $m === $trend['lastActualMonth'] ? (float) $trend['actualUnits'][$m] : null;
    });

    $actualRevenueSeries = $months->map(fn ($m) => $trend['actualRevenue'][$m] ?? null);
    $forecastRevenueSeries = $months->map(function ($m) use ($trend) {
        if (array_key_exists($m, $trend['forecastRevenue']->toArray())) {
            return (float) $trend['forecastRevenue'][$m];
        }
        return $m === $trend['lastActualMonth'] ? (float) $trend['actualRevenue'][$m] : null;
    });

    // Confidence bounds for the shaded band. Each anchors to the last actual
    // month exactly like the forecast line does, so the band opens from the
    // actual series rather than appearing out of nowhere a month later.
    //
    // ?? collect() because overallMonthlyTrend() is cached for 6 hours: a
    // payload cached before these keys existed must render an unshaded chart,
    // not a 500.
    $bandSeries = function (string $key) use ($trend, $months) {
        $bound = $trend[$key] ?? collect();

        return $months->map(function ($m) use ($bound, $trend) {
            if ($bound->has($m)) {
                return (float) $bound[$m];
            }

            return $m === $trend['lastActualMonth']
                ? (float) ($trend['actualUnits'][$m] ?? 0)
                : null;
        });
    };

    $unitsLowerSeries = $bandSeries('forecastUnitsLower');
    $unitsUpperSeries = $bandSeries('forecastUnitsUpper');

    $revenueBand = function (string $key) use ($trend, $months) {
        $bound = $trend[$key] ?? collect();

        return $months->map(function ($m) use ($bound, $trend) {
            if ($bound->has($m)) {
                return (float) $bound[$m];
            }

            return $m === $trend['lastActualMonth']
                ? (float) ($trend['actualRevenue'][$m] ?? 0)
                : null;
        });
    };

    $revenueLowerSeries = $revenueBand('forecastRevenueLower');
    $revenueUpperSeries = $revenueBand('forecastRevenueUpper');

    $hasUnitsBand = $unitsUpperSeries->filter(fn ($v) => $v !== null)->isNotEmpty();
    $hasRevenueBand = $revenueUpperSeries->filter(fn ($v) => $v !== null)->isNotEmpty();

    // Same boundary as the per-product detail page (App\Support\ForecastHorizon):
    // the horizon OPENS on the current month, a nowcast against a partial
    // actual that nobody can still act on. The chart keeps that row -- the
    // model's estimate read against the partial month is informative -- but
    // the KPI total below must not count it.
    $actionableMonthKey = \App\Support\ForecastHorizon::firstActionableMonthKey();
    $actionableUnits = $trend['forecastUnits']->filter(fn ($v, $m) => $m >= $actionableMonthKey);
    $actionableRevenue = $trend['forecastRevenue']->filter(fn ($v, $m) => $m >= $actionableMonthKey);
    $forecastUnitsTotal = $actionableUnits->sum();
    $forecastRevenueTotal = $actionableRevenue->sum();
    $forecastMonths = $actionableUnits->count();
@endphp

<div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0; margin-bottom:4px; font-size:20px; font-weight:500;">
        Forecasting
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        Store-wide sales trend, per-product demand, and per-product sales forecast, all modeled from the same sales
        history. Click a product below to see its demand and sales forecast together.
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:18px;">
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Last month with sales</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ $trend['lastActualMonth'] ?? '—' }}</p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast total, units ({{ $forecastMonths }}-month)</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ number_format($forecastUnitsTotal) }}</p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast total, revenue ({{ $forecastMonths }}-month)</p>
            {{-- Money keeps its centavos. number_format() with no precision rounds
                 to whole pesos, so this KPI silently reported a figure that was
                 up to 50 centavos away from the number it was summing. The units
                 KPI above it stays whole on purpose -- demand is integral. --}}
            <p style="font-size:22px; font-weight:500; margin:0;">₱{{ number_format($forecastRevenueTotal, 2) }}</p>
        </div>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Units sold — actual vs. forecast, with 80% confidence band</p>
    <div style="position:relative; width:100%; height:230px; margin-bottom:24px;">
        <canvas id="unitsTrendChart" role="img" aria-label="Line chart of total units sold per month, actual with a dashed forecast continuation"></canvas>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Revenue — actual vs. forecast, with 80% confidence band</p>
    <div style="position:relative; width:100%; height:230px;">
        <canvas id="revenueTrendChart" role="img" aria-label="Line chart of total revenue per month, actual with a dashed forecast continuation"></canvas>
    </div>
</div>

<div class="card">
    {{-- The "Model accuracy" card (MAE/RMSE/MAPE/sMAPE, measured on a
         holdout -- see DemandForecastService::accuracySummary()) is hidden
         here at the user's request; nothing about the computation changed,
         it's a single cheap aggregate query, and $accuracy is still passed
         to this view by DemandForecastController -- only unused now, not
         removed there, in case this comes back. --}}

    {{-- Two "top 5" charts, side by side conceptually but stacked here since
         each needs its own width: demand (units to buy, DemandForecastService)
         and sales forecast (revenue to expect, SalesForecastService). They
         answer different questions on purpose -- what to reorder vs. what
         earns -- rather than the same ranking told twice, which is why a
         product can appear on one and not the other. --}}
    @if (!empty($topDemand['series']))
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:4px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-chart-line" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                Top 5 products in demand
            </span>
            <span style="font-size:12px; color:#6b7280;">forecast units per month</span>
        </div>
        <p style="font-size:12px; color:#94a3b8; margin:0 0 12px;">
            {{ $topDemand['months'][0] ?? '' }} &ndash; {{ $topDemand['months'][count($topDemand['months']) - 1] ?? '' }}
            &middot; ranked by total forecast demand over the period
        </p>
        <div style="height:340px;">
            <canvas id="topDemandChart"></canvas>
        </div>
    </div>
    @endif

    @if (!empty($topSales['series']))
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:4px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-currency-peso" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#0f6e56;"></i>
                Top 5 products by sales forecast
            </span>
            <span style="font-size:12px; color:#6b7280;">forecast revenue per month</span>
        </div>
        <p style="font-size:12px; color:#94a3b8; margin:0 0 12px;">
            {{ $topSales['months'][0] ?? '' }} &ndash; {{ $topSales['months'][count($topSales['months']) - 1] ?? '' }}
            &middot; ranked by total forecast revenue over the period
        </p>
        <div style="height:340px;">
            <canvas id="topSalesForecastChart"></canvas>
        </div>
    </div>
    @endif

    <form id="search-form" method="GET" action="{{ route('forecast.index') }}" style="margin-bottom:18px; display:flex; gap:10px; align-items:center;">
        <input
            type="text"
            name="search"
            id="search-input"
            data-suggest-url="{{ route('suggest.products') }}"
            value="{{ $search }}"
            placeholder="Search by name or SKU..."
            style="width:260px;"
            autocomplete="off"
        >
        <select name="category" id="category-select" style="width:200px;">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        {{-- No Search button: the box refreshes on a debounced keystroke and
             the category select on change (see the script below), so it only
             ever re-ran a search that had already run -- same redundancy
             already removed from Products/Inventory's search boxes. The form
             stays a real GET form, and stays working without JS: the text
             input is the only field that blocks implicit submission (a
             <select> does not), so Enter still submits it with no button
             present. --}}
        <a href="{{ route('forecast.index') }}" id="clear-link" class="btn btn-secondary" style="{{ ($search || $categoryId) ? '' : 'display:none' }}">
            <i class="ti ti-x" aria-hidden="true"></i> Clear
        </a>
    </form>

    <div id="results-wrapper">
        @if ($forecasts->isEmpty())
            <p id="empty-message" style="color:#64748b;">
                @if ($search || $categoryId)
                    No forecasts found matching those filters.
                @else
                    No forecasts generated yet. Run
                    <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">php artisan forecast:generate --source=csv ...</code>
                @endif
            </p>
        @else
            @include('forecast._rows')
        @endif

        <div style="margin-top:18px;" id="pagination-wrapper">
            {{ $forecasts->links() }}
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('unitsTrendChart'), {
    type: 'line',
    data: {
        labels: @json($months),
        datasets: [
            {{-- Band first: the fill dataset points back one index with
                 fill:'-1', so the lower bound has to already exist. --}}
            @if ($hasUnitsBand)
            {
                label: 'Lower bound',
                data: @json($unitsLowerSeries),
                borderWidth: 0,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
            },
            {
                label: '80% confidence band',
                data: @json($unitsUpperSeries),
                borderWidth: 0,
                pointRadius: 0,
                backgroundColor: 'rgba(15, 110, 86, 0.13)',
                fill: '-1',
                spanGaps: false,
            },
            @endif
            {
                label: 'Actual',
                data: @json($actualUnitsSeries),
                borderColor: '#0f6e56',
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
            {
                label: 'Forecast',
                data: @json($forecastUnitsSeries),
                borderColor: '#0f6e56',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                mode: 'index',
                intersect: false,
                filter: (item) => item.dataset.label === 'Actual' || item.dataset.label === 'Forecast',
                callbacks: { label: (c) => `${c.dataset.label}: ${Math.round(c.parsed.y).toLocaleString()}` },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } },
            y: { beginAtZero: true, grid: { color: '#e2e8f0' } },
        },
    },
});

new Chart(document.getElementById('revenueTrendChart'), {
    type: 'line',
    data: {
        labels: @json($months),
        datasets: [
            @if ($hasRevenueBand)
            {
                label: 'Lower bound',
                data: @json($revenueLowerSeries),
                borderWidth: 0,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
            },
            {
                label: '80% confidence band',
                data: @json($revenueUpperSeries),
                borderWidth: 0,
                pointRadius: 0,
                backgroundColor: 'rgba(83, 74, 183, 0.13)',
                fill: '-1',
                spanGaps: false,
            },
            @endif
            {
                label: 'Actual',
                data: @json($actualRevenueSeries),
                borderColor: '#534ab7',
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
            {
                label: 'Forecast',
                data: @json($forecastRevenueSeries),
                borderColor: '#534ab7',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                mode: 'index',
                intersect: false,
                filter: (item) => item.dataset.label === 'Actual' || item.dataset.label === 'Forecast',
                // Two decimals, not Math.round(): this axis is pesos. The units
                // chart above rounds on purpose -- that one counts boxes.
                callbacks: { label: (c) => c.dataset.label + ': ₱' + c.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } },
            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: (v) => '₱' + (v / 1000) + 'k' } },
        },
    },
});

function renderSparklines() {
    /* Five distinct hues, spaced around the wheel rather than shaded: a
       single-hue ramp stops separating cleanly well before five lines, and
       these are the first five of the wheel the dashboard doughnuts use. */
    const palette = ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626',
                     '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'];

    function renderTopSeriesChart(elId, months, series, unitLabel, formatter) {
        const el = document.getElementById(elId);
        if (!el || el.dataset.rendered || !months.length || !series.length) return;

        new Chart(el, {
            type: 'line',
            data: {
                labels: months,
                datasets: series.map((s, i) => ({
                    label: s.name,
                    data: s.values,
                    borderColor: palette[i % palette.length],
                    backgroundColor: palette[i % palette.length],
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    // No area fill: five stacked translucent washes would hide
                    // every line underneath them.
                    fill: false,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true,
                                  pointStyle: 'circle', font: { size: 11 }, padding: 12 },
                    },
                    tooltip: {
                        position: 'nearest',
                        callbacks: {
                            label: (ctx) => ctx.dataset.label + ': ' + formatter(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#9ca3af', font: { size: 10 },
                                 callback: (v) => formatter(v) },
                        grid: { color: '#f1f5f9' },
                        title: { display: true, text: unitLabel,
                                 color: '#9ca3af', font: { size: 11 } },
                    },
                    x: { ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } },
                },
            },
        });

        el.dataset.rendered = '1';
    }

    renderTopSeriesChart(
        'topDemandChart',
        @json($topDemand['months'] ?? []),
        @json($topDemand['series'] ?? []),
        'Forecast units',
        (v) => Math.round(v).toLocaleString() + ' units'
    );

    renderTopSeriesChart(
        'topSalesForecastChart',
        @json($topSales['months'] ?? []),
        @json($topSales['series'] ?? []),
        'Forecast revenue',
        (v) => '₱' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })
    );

    document.querySelectorAll('canvas.sparkline').forEach(canvas => {
        if (canvas.dataset.rendered) return;
        const labels = JSON.parse(canvas.dataset.labels);
        const values = JSON.parse(canvas.dataset.values);
        new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data: values,
                    borderColor: '#4f46e5',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false,
                }],
            },
            options: {
                responsive: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            label: (item) => Math.round(item.parsed.y),
                        },
                    },
                },
                scales: { x: { display: false }, y: { display: false } },
            },
        });
        canvas.dataset.rendered = "1";
    });
}
renderSparklines();

const input = document.getElementById('search-input');
const categorySelect = document.getElementById('category-select');
const wrapper = document.getElementById('results-wrapper');
const clearLink = document.getElementById('clear-link');
const baseUrl = "{{ route('forecast.index') }}";

let debounceTimer;
let currentController;

function runSearch(term, category, pushState = true) {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    const url = new URL(baseUrl);
    if (term) {
        url.searchParams.set('search', term);
    }
    if (category) {
        url.searchParams.set('category', category);
    }

    // Swap the stale rows for a skeleton so a search/filter reads as
    // 'working' instead of leaving the previous results on screen.
    // See REMEDI.holdScroll: read the offset before the rows are gone.
    const restoreScroll = REMEDI.holdScroll();

    REMEDI.showListSkeleton(wrapper, { rows: 6 });

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        signal: currentController.signal,
    })
        .then(res => res.json())
        .then(data => {
            wrapper.innerHTML = data.empty
                ? `<p id="empty-message" style="color:#64748b;">No forecasts found matching those filters.</p>`
                : data.html + `<div style="margin-top:18px;" id="pagination-wrapper">${data.pagination}</div>`;
            restoreScroll();

            clearLink.style.display = (term || category) ? '' : 'none';
            renderSparklines();

            if (pushState) {
                window.history.pushState({}, '', url);
            }
        })
        .catch(err => {
            if (err.name !== 'AbortError') console.error(err);
        });
}

input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const term = input.value.trim();
    debounceTimer = setTimeout(() => runSearch(term, categorySelect.value), 300);
});

    // Choosing a suggestion runs the same search the field would.
    // `term` isn't in scope here -- it's local to the separate 'input'
    // listener above -- so this threw ReferenceError on every keystroke
    // REMEDI.attachSuggest fires 'suggest:live' for (layouts/app.blade.php).
    // Read the field fresh instead, same as the other two call sites in
    // this file and every other page's copy of this exact handler.
    input.addEventListener('suggest:live', () => { runSearch(input.value.trim(), categorySelect.value); });

categorySelect.addEventListener('change', () => {
    runSearch(input.value.trim(), categorySelect.value);
});

document.getElementById('search-form').addEventListener('submit', (e) => {
    e.preventDefault();
    runSearch(input.value.trim(), categorySelect.value);
});

clearLink.addEventListener('click', (e) => {
    e.preventDefault();
    input.value = '';
    categorySelect.value = '';
    runSearch('', '');
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const term = params.get('search') || '';
    const category = params.get('category') || '';
    input.value = term;
    categorySelect.value = category;
    runSearch(term, category, false);
});
</script>
@endsection
