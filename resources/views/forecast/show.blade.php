@extends('layouts.app')

@section('title', 'Demand Forecast — ' . ($product->name ?? $product_sku))

@section('content')
@php
    // Merge actual sales months + forecast months into one sorted label
    // axis, same "dashed continuation" technique as the trend charts on
    // the other forecasting pages, plus a shaded confidence band that
    // only appears across the forecast portion.
    $forecastByMonth = $forecast->keyBy(fn ($row) => $row->forecast_date->format('Y-m'));
    $months = $actual->keys()->merge($forecastByMonth->keys())->unique()->sort()->values();
    $lastActualMonth = $actual->keys()->last();

    $actualSeries = $months->map(fn ($m) => $actual[$m] ?? null);

    $forecastSeries = $months->map(function ($m) use ($forecastByMonth, $lastActualMonth, $actual) {
        if ($forecastByMonth->has($m)) {
            return (float) $forecastByMonth[$m]->forecast_value;
        }
        return $m === $lastActualMonth ? (float) $actual[$m] : null;
    });

    $lowerSeries = $months->map(function ($m) use ($forecastByMonth, $lastActualMonth, $actual) {
        if ($forecastByMonth->has($m) && $forecastByMonth[$m]->lower_ci !== null) {
            return (float) $forecastByMonth[$m]->lower_ci;
        }
        return $m === $lastActualMonth ? (float) $actual[$m] : null;
    });

    $upperSeries = $months->map(function ($m) use ($forecastByMonth, $lastActualMonth, $actual) {
        if ($forecastByMonth->has($m) && $forecastByMonth[$m]->upper_ci !== null) {
            return (float) $forecastByMonth[$m]->upper_ci;
        }
        return $m === $lastActualMonth ? (float) $actual[$m] : null;
    });

    $nextMonth = $forecast->first();
    $sixMonthTotal = $forecast->sum('forecast_value');
@endphp

<div class="page-back"><a href="{{ route('forecast.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to Demand Forecasts</a></div>

<div class="card" style="margin-bottom:18px;">
    

    <h2 style="margin-top:10px; margin-bottom:2px; font-size:22px; font-weight:500;">
        {{ $product->name ?? $product_sku }}
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        SKU {{ $product_sku }}
        @if ($product && $product->category) &middot; {{ $product->category->name }} @endif
    </p>

    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:18px;">
        <div style="background:#f8fafc; border-radius:8px; padding:1rem;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Last actual month</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ $lastActualMonth ?? '—' }}</p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Next month forecast</p>
            <p style="font-size:22px; font-weight:500; margin:0;">
                {{ $nextMonth ? number_format($nextMonth->forecast_value) : '—' }}
            </p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast total ({{ $forecast->count() }}-month)</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ number_format($sixMonthTotal) }}</p>
        </div>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">
        Units sold — actual vs. forecast, with 80% confidence band
    </p>
    <div style="position:relative; width:100%; height:320px;">
        <canvas id="productForecastChart" role="img" aria-label="Line chart of actual units sold with a dashed forecast continuation and shaded confidence band"></canvas>
    </div>
</div>

{{-- Seasonal pattern for this specific product — average units sold per
     calendar month, across however many years of sales_history exist for
     this SKU. See DemandForecastService::forProduct(). --}}
<div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0; margin-bottom:4px; font-size:20px; font-weight:500;">
        Seasonal Pattern
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 14px;">
        Average units sold per calendar month, based on this product's sales history
    </p>
    @if ($seasonal->isNotEmpty())
        <canvas id="productSeasonalChart" height="90"></canvas>
    @else
        <p style="color:#94a3b8; font-size:13px;">Not enough sales history for this product yet to show a seasonal pattern.</p>
    @endif
</div>

<div class="card">
    <h2 style="margin-top:0; margin-bottom:18px; font-size:20px; font-weight:500;">
        Forecast detail
    </h2>
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>Month</th>
                <th>Forecast</th>
                <th>Low (80% CI)</th>
                <th>High (80% CI)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($forecast as $row)
                <tr>
                    <td>{{ $row->forecast_date->format('F Y') }}</td>
                    <td>{{ number_format($row->forecast_value) }}</td>
                    <td>{{ $row->lower_ci !== null ? number_format($row->lower_ci) : '—' }}</td>
                    <td>{{ $row->upper_ci !== null ? number_format($row->upper_ci) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('productForecastChart'), {
    type: 'line',
    data: {
        labels: @json($months),
        datasets: [
            {
                label: 'Lower bound',
                data: @json($lowerSeries),
                borderWidth: 0,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
            },
            {
                label: 'Confidence band',
                data: @json($upperSeries),
                borderWidth: 0,
                pointRadius: 0,
                backgroundColor: 'rgba(79, 70, 229, 0.12)',
                fill: '-1',
                spanGaps: false,
            },
            {
                label: 'Actual',
                data: @json($actualSeries),
                borderColor: '#4f46e5',
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
            {
                label: 'Forecast',
                data: @json($forecastSeries),
                borderColor: '#4f46e5',
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
            legend: {
                display: true,
                labels: {
                    filter: (item) => item.text === 'Actual' || item.text === 'Forecast',
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                filter: (item) => item.dataset.label === 'Actual' || item.dataset.label === 'Forecast',
                callbacks: {
                    label: (item) => `${item.dataset.label}: ${Math.round(item.parsed.y)}`,
                },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 18 } },
            y: { beginAtZero: true, grid: { color: '#e2e8f0' } },
        },
    },
});

@if ($seasonal->isNotEmpty())
new Chart(document.getElementById('productSeasonalChart'), {
    type: 'bar',
    data: {
        labels: @json($seasonal->pluck('month')),
        datasets: [{
            label: 'Avg. units sold',
            data: @json($seasonal->pluck('avg_qty')),
            backgroundColor: '#4f46e5',
            borderRadius: 4,
            maxBarThickness: 30,
        }],
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.y} units avg` } },
        },
        scales: {
            y: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { color: '#f1f5f9' } },
            x: { ticks: { color: '#334155', font: { size: 11, weight: '600' } }, grid: { display: false } },
        },
    },
});
@endif
</script>
@endsection
