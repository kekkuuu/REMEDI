@extends('layouts.app')

@section('title', 'Demand Forecasts')

@section('content')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
@include('partials._chart-gradient')

<div class="card">
    <h2 style="margin-top:0; margin-bottom:18px; font-size:20px; font-weight:500;">
        Demand Forecasts
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        Per-product demand forecast, modeled from sales history. For store-wide sales and revenue trends, see
        <a href="{{ route('sales-forecast.index') }}">Sales Forecasting</a>.
    </p>

    {{-- Model accuracy, measured on a holdout rather than asserted.

         Each product's own model is refitted without the last few months and
         scored against them, then averaged ACROSS PRODUCTS -- not pooled across
         every residual, which would let a handful of very high-volume products
         set the headline. This answers "how wrong is a typical product's
         forecast", which is the question someone reordering actually has. --}}
    @if ($accuracy)
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-target-arrow" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                Model accuracy
            </span>
            <span style="font-size:12px; color:#6b7280;">
                {{ number_format($accuracy['scored']) }} products &middot;
                {{ $accuracy['holdout_months'] }}-month holdout
            </span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:14px;">
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">MAE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">{{ number_format($accuracy['mae'], 2) }}</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">units out per month, typical product</p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">RMSE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">{{ number_format($accuracy['rmse'], 2) }}</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">large misses weighted heavier</p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">MAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">
                    {{ $accuracy['mape'] !== null ? number_format($accuracy['mape'], 1).'%' : '—' }}
                </p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">
                    undefined for {{ number_format($accuracy['mape_undefined']) }} products that sold nothing
                </p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">sMAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">
                    {{ $accuracy['smape'] !== null ? number_format($accuracy['smape'], 1).'%' : '—' }}
                </p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">stays defined at zero sales</p>
            </div>
        </div>

        {{-- Only the SARIMA-family rows from by_method are shown below -- the
             other candidates in the retired cascade (Holt-Winters, plain
             ARIMA, moving average, Croston SBA) are not displayed here, at
             the user's request. --}}
        @php $sarimaMethods = $accuracy['by_method']->filter(fn ($m) => str_contains(strtolower($m->method ?? ''), 'sarima')); @endphp
        @if ($sarimaMethods->isNotEmpty())
        <div class="table-scroll"><table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:8px 12px; text-align:left; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">Model</th>
                    <th style="padding:8px 12px; text-align:right; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">Products</th>
                    <th style="padding:8px 12px; text-align:right; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">MAE</th>
                    <th style="padding:8px 12px; text-align:right; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">RMSE</th>
                    <th style="padding:8px 12px; text-align:right; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">MAPE</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sarimaMethods as $m)
                <tr style="border-bottom:0.5px solid #e5e7eb;">
                    <td style="padding:9px 12px; color:#374151;">{{ str_replace('_', ' ', $m->method ?? 'unknown') }}</td>
                    <td style="padding:9px 12px; text-align:right; color:#6b7280;">{{ number_format($m->products) }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ number_format($m->mae, 2) }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ number_format($m->rmse, 2) }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ $m->mape !== null ? number_format($m->mape, 1).'%' : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table></div>
        @endif

        <p style="font-size:11px; color:#94a3b8; margin:10px 0 0;">
            MAPE runs high on intermittent demand by construction &mdash; being one unit out on a month that
            sold two is a 50% error &mdash; which is why MAE and sMAPE are shown beside it. Measured on this
            catalogue it falls with volume: <strong>17.3%</strong> for products selling 100+ units a month,
            against <strong>65.8%</strong> for those selling 5&ndash;20.
        </p>
    </div>
    @endif

    {{-- Top 5 products by forecast demand, one line each.

         The table below ranks products and gives each a sparkline, which answers
         "how much" per product but not "how do they compare over the coming
         months". Five lines on one shared axis do that: which product carries the
         most demand, whether it is steady or spiky, and where two of them cross.

         Ranked on the SUM over the horizon, so one spiky month cannot outrank a
         product that is busy every month. --}}
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
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-search" aria-hidden="true"></i> Search
        </button>
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
function renderSparklines() {
    // ── Top 10 in demand ──
    (function () {
        const el = document.getElementById('topDemandChart');
        if (!el || el.dataset.rendered) return;

        const months = @json($topDemand['months'] ?? []);
        const series = @json($topDemand['series'] ?? []);
        if (!months.length || !series.length) return;

        /* Five distinct hues, spaced around the wheel rather than shaded: a
           single-hue ramp stops separating cleanly well before five lines, and
           these are the first five of the wheel the dashboard doughnuts use. */
        const palette = ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626',
                         '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'];

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
                    // No area fill: ten stacked translucent washes would hide
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
                        // Anchor to the point, not the hovered average.
                        position: 'nearest',
                        callbacks: {
                            label: (ctx) => ctx.dataset.label + ': ' +
                                Math.round(ctx.parsed.y).toLocaleString() + ' units',
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#9ca3af', font: { size: 10 },
                                 callback: (v) => Number(v).toLocaleString() },
                        grid: { color: '#f1f5f9' },
                        title: { display: true, text: 'Forecast units',
                                 color: '#9ca3af', font: { size: 11 } },
                    },
                    x: { ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } },
                },
            },
        });

        el.dataset.rendered = '1';
    })();

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
