@extends('layouts.app')

@section('title', 'Sales Forecasts')

@section('content')
@php
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

    // Same boundary as the Demand Forecasts detail page (App\Support\ForecastHorizon):
    // the horizon OPENS on the current month, which is a nowcast against a
    // partial actual, not something anyone can still act on. The chart above
    // deliberately keeps that row (the model's estimate read against the
    // partial month is informative), but summing it into an "upcoming
    // months" total counted a month already 9+ days gone as still ahead --
    // the same fault ForecastHorizon was written to close on Demand Forecasts,
    // just never applied here. Filtering to the first actionable month and
    // later mirrors forecast/show.blade.php's $actionable exactly.
    $actionableMonthKey = \App\Support\ForecastHorizon::firstActionableMonthKey();
    $actionableUnits = $trend['forecastUnits']->filter(fn ($v, $m) => $m >= $actionableMonthKey);
    $actionableRevenue = $trend['forecastRevenue']->filter(fn ($v, $m) => $m >= $actionableMonthKey);
    $forecastUnitsTotal = $actionableUnits->sum();
    $forecastRevenueTotal = $actionableRevenue->sum();
    $forecastMonths = $actionableUnits->count();
@endphp
<div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0; margin-bottom:4px; font-size:20px; font-weight:500;">
        Sales Trends
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        Store-wide sales and revenue trend. For per-product demand detail, see
        <a href="{{ route('forecast.index') }}">Demand Forecasting</a>.
    </p>

    {{-- grid-template-columns:repeat(3, 1fr) forced three equal columns at
         every width; grid items default to min-width:auto, which refuses to
         shrink text below its natural size, so the revenue figure overflowed
         its cell and ran off-screen on mobile with no way to scroll to it.
         auto-fit + minmax lets columns wrap on their own below ~150px each,
         and min-width:0 lets each card's own text wrap within its column. --}}
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
    <div style="position:relative; width:100%; height:230px; margin-bottom:24px;">
        <canvas id="revenueTrendChart" role="img" aria-label="Line chart of total revenue per month, actual with a dashed forecast continuation"></canvas>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Top 5 products by units sold</p>
    <div style="position:relative; width:100%; height:200px;">
        <canvas id="topProductsChart" role="img" aria-label="Horizontal bar chart of the five highest-selling products"></canvas>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
new Chart(document.getElementById('unitsTrendChart'), {
    type: 'line',
    data: {
        labels: @json($months),
        datasets: [
            {{-- Band first: the fill dataset points back one index with
                 fill:'-1', so the lower bound has to already exist. Same
                 technique as forecast/show.blade.php. --}}
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
                // The band's two datasets carry the shading, not a reading:
                // without this they show up as two extra unlabelled numbers.
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

new Chart(document.getElementById('topProductsChart'), {
    type: 'bar',
    data: {
        labels: @json($trend['topProducts']->pluck('name')),
        datasets: [{
            data: @json($trend['topProducts']->pluck('total_units')),
            backgroundColor: '#0f6e56',
            borderRadius: 4,
            barThickness: 20,
        }],
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, grid: { color: '#e2e8f0' } },
            y: { grid: { display: false } },
        },
    },
});
</script>
@endsection
