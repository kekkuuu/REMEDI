@extends('layouts.app')

@section('title', 'Forecasting — ' . ($product->name ?? $product_sku))

@section('content')
@php
    // Merge actual sales months + forecast months into one sorted label
    // axis, same "dashed continuation" technique as the trend charts on
    // the other forecasting pages, plus a shaded confidence band that
    // only appears across the forecast portion.
    $forecastByMonth = $forecast->keyBy(fn ($row) => $row->forecast_date->format('Y-m'));
    $months = $actual->keys()->merge($forecastByMonth->keys())->unique()->sort()->values();
    $lastActualMonth = $actual->keys()->last();

    // Where the dashed line is stitched to the solid one.
    //
    // The forecast, lower and upper series all carry the ACTUAL value at this
    // one month, so the dashed line starts exactly where the solid line is and
    // the confidence band pinches to zero width before opening out. Without it
    // the two lines are separate strokes with a void between them.
    //
    // It has to be the last actual month STRICTLY BEFORE the forecast begins,
    // not simply the last actual month. The horizon now opens on the current
    // month, so the last actual month usually has a forecast row too -- and the
    // old code checked `$forecastByMonth->has($m)` first, which meant the join
    // branch became unreachable the moment those two coincided. Measured on
    // GLUMET XR: the actual line ended at 90 and the dashed line began at
    // 252.6 in the same column, a 162-unit jump with nothing joining them, and
    // the band opened at full width (169.82-335.38) instead of from a point.
    $firstForecastMonth = $forecastByMonth->keys()->sort()->first();
    $joinMonth = $actual->keys()
        ->filter(fn ($m) => $firstForecastMonth === null || $m < $firstForecastMonth)
        ->last();

    $actualSeries = $months->map(fn ($m) => $actual[$m] ?? null);

    // $joinMonth is tested BEFORE the forecast lookup in all three, so the
    // stitch always wins over a forecast row for the same month.
    $forecastSeries = $months->map(function ($m) use ($forecastByMonth, $joinMonth, $actual) {
        if ($m === $joinMonth) {
            return (float) $actual[$m];
        }

        return $forecastByMonth->has($m) ? (float) $forecastByMonth[$m]->forecast_value : null;
    });

    $lowerSeries = $months->map(function ($m) use ($forecastByMonth, $joinMonth, $actual) {
        if ($m === $joinMonth) {
            return (float) $actual[$m];
        }

        return $forecastByMonth->has($m) && $forecastByMonth[$m]->lower_ci !== null
            ? (float) $forecastByMonth[$m]->lower_ci
            : null;
    });

    $upperSeries = $months->map(function ($m) use ($forecastByMonth, $joinMonth, $actual) {
        if ($m === $joinMonth) {
            return (float) $actual[$m];
        }

        return $forecastByMonth->has($m) && $forecastByMonth[$m]->upper_ci !== null
            ? (float) $forecastByMonth[$m]->upper_ci
            : null;
    });
    // The KPI cards count only months you can still act on.
    //
    // (A // comment, not {{-- --}}: inside an @php block the body is raw PHP
    // and a Blade comment there is a parse error, not a comment.)
    //
    // The horizon opens on the CURRENT month -- training stops at the last
    // complete month, so the first forecast row is a nowcast of the month in
    // progress, and the chart deliberately keeps it so the model estimate can
    // be read against the partial actual. The cards must not: first() made
    // "Next month forecast" quote a month already 80% over, and the total
    // counted it as if it were still ahead.
    // Boundary comes from the service (App\Support\ForecastHorizon), not
    // recomputed here -- the view and the product list must not be able to
    // disagree about which month counts as next.
    $nextMonthKey = $firstActionableMonth;
    $actionable = $forecast->filter(fn ($r) => $r->forecast_date->format('Y-m') >= $nextMonthKey);

    $nextMonth = $actionable->first();
    $horizonTotal = $actionable->sum('forecast_value');
    $horizonMonths = $actionable->count();

    // Recommended reorder quantity: an order-up-to-level heuristic, not a
    // second reorder POINT (that is Product::$reorder_level, a threshold
    // that triggers a low-stock alert -- see SetReorderLevels). This answers
    // a different question: given next month's forecast demand and the
    // usual reorder_level buffer, how many units to actually buy now so
    // stock doesn't fall through that buffer before next month is over.
    // Null (not zero) when there is no actionable forecast to base it on --
    // a zero here would read as "you have enough," which is not measured.
    $recommendedReorderQty = ($product && $nextMonth)
        ? max(0, (int) round($nextMonth->forecast_value + $product->reorder_level - $product->sellable_stock))
        : null;
@endphp

@php
    // Sales-forecast half of this page: same product, from sales_forecasts
    // (units + revenue) rather than demand_forecasts. A product can have a
    // demand forecast with no sales forecast (the two scripts run
    // independently and search their own SARIMA candidates), so everything
    // here is guarded on $hasSalesForecast rather than assumed present.
    $hasSalesForecast = $salesForecast['forecast']->isNotEmpty();

    if ($hasSalesForecast) {
        $sfForecastByMonth = $salesForecast['forecast']->keyBy(fn ($row) => $row->forecast_date->format('Y-m'));
        $sfMonths = collect($salesForecast['actualRevenue']->keys())
            ->merge($sfForecastByMonth->keys())->unique()->sort()->values();

        // Same join-month stitch as the demand chart above: the last actual
        // month strictly before the forecast begins, so the dashed line
        // starts exactly where the solid one ends instead of jumping to it.
        $sfFirstForecastMonth = $sfForecastByMonth->keys()->sort()->first();
        $sfJoinMonth = collect($salesForecast['actualRevenue']->keys())
            ->filter(fn ($m) => $sfFirstForecastMonth === null || $m < $sfFirstForecastMonth)
            ->last();

        $sfActualRevenueSeries = $sfMonths->map(fn ($m) => $salesForecast['actualRevenue'][$m] ?? null);
        $sfForecastRevenueSeries = $sfMonths->map(function ($m) use ($sfForecastByMonth, $sfJoinMonth, $salesForecast) {
            if ($m === $sfJoinMonth) {
                return (float) ($salesForecast['actualRevenue'][$m] ?? 0);
            }

            return $sfForecastByMonth->has($m) ? (float) $sfForecastByMonth[$m]->forecast_revenue : null;
        });

        $sfRevenueLowerSeries = $sfMonths->map(function ($m) use ($sfForecastByMonth, $sfJoinMonth, $salesForecast) {
            if ($m === $sfJoinMonth) {
                return (float) ($salesForecast['actualRevenue'][$m] ?? 0);
            }

            return $sfForecastByMonth->has($m) && $sfForecastByMonth[$m]->lower_ci_revenue !== null
                ? (float) $sfForecastByMonth[$m]->lower_ci_revenue
                : null;
        });

        $sfRevenueUpperSeries = $sfMonths->map(function ($m) use ($sfForecastByMonth, $sfJoinMonth, $salesForecast) {
            if ($m === $sfJoinMonth) {
                return (float) ($salesForecast['actualRevenue'][$m] ?? 0);
            }

            return $sfForecastByMonth->has($m) && $sfForecastByMonth[$m]->upper_ci_revenue !== null
                ? (float) $sfForecastByMonth[$m]->upper_ci_revenue
                : null;
        });

        // Same "current month is a nowcast, not actionable" boundary as the
        // demand KPIs above -- $firstActionableMonth comes from the
        // controller (App\Support\ForecastHorizon), one definition shared by
        // both halves of this page.
        $sfActionable = $salesForecast['forecast']->filter(fn ($r) => $r->forecast_date->format('Y-m') >= $nextMonthKey);
        $sfNextMonth = $sfActionable->first();
        $sfHorizonRevenue = $sfActionable->sum('forecast_revenue');
        $sfHorizonUnits = $sfActionable->sum('forecast_units');
        $sfHorizonMonths = $sfActionable->count();
    }
@endphp

{{-- Title lifted out of the card so Back can sit beside it, matching every
     other detail page. It was the card's own heading before, which left the
     back button stranded on a row of its own above. --}}
<div class="page-head">
    <a href="{{ route('forecast.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>{{ $product->name ?? $product_sku }}</h3>
        <p>
            SKU {{ $product_sku }}
            @if ($product && $product->category) &middot; {{ $product->category->name }} @endif
        </p>
    </div>
</div>

<div class="card" style="margin-bottom:18px;">

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px;">
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Last complete month</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ $lastActualMonth ?? '—' }}</p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Next month forecast</p>
            <p style="font-size:22px; font-weight:500; margin:0;">
                {{ $nextMonth ? number_format($nextMonth->forecast_value) : '—' }}
            </p>
        </div>
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast total ({{ $horizonMonths }}-month)</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ number_format($horizonTotal) }}</p>
        </div>
        <div style="background:#f0fdf4; border-radius:8px; padding:1rem; min-width:0;">
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Recommended reorder qty</p>
            <p style="font-size:22px; font-weight:500; margin:0;">
                {{ $recommendedReorderQty !== null ? number_format($recommendedReorderQty) : '—' }}
            </p>
            <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">
                @if ($recommendedReorderQty === null)
                    no actionable forecast yet
                @elseif ($recommendedReorderQty === 0)
                    current stock covers next month + buffer
                @else
                    covers next month's demand + reorder buffer
                @endif
            </p>
        </div>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">
        Units sold — actual vs. forecast, with 80% confidence band. Complete months only — the month in progress is excluded, so a half-month does not read as a fall.
    </p>
    <div style="position:relative; width:100%; height:320px;">
        <canvas id="productForecastChart" role="img" aria-label="Line chart of actual units sold with a dashed forecast continuation and shaded confidence band"></canvas>
    </div>
</div>

{{-- The "Forecast Accuracy" card ($accuracy / $grade -- MAE/RMSE/MAPE/sMAPE
     and the Normal/Acceptable/Not acceptable verdict, per product) is hidden
     here at the user's request, matching forecast/index.blade.php's Model
     accuracy card. The controller still computes and passes both variables;
     only the display is removed. --}}

{{-- Sales Forecast: the second half of the merged page. Demand Forecasting
     answers "how many units to buy"; this answers "how much revenue to
     expect" -- same product, same sales history, a different forecast run
     (sales_forecasts, fit on revenue as well as units). Shown on the same
     page rather than a separate one, per the product request, so clicking a
     product surfaces both instead of sending the two forecasts to different
     pages. --}}
<div class="card" style="margin-bottom:18px;">
    <h2 style="margin-top:0; margin-bottom:4px; font-size:20px; font-weight:500;">
        Sales Forecast
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        Revenue forecast for this product, from the same sales history as the demand forecast above.
    </p>

    @if ($hasSalesForecast)
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px;">
            <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
                <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Next month revenue forecast</p>
                <p style="font-size:22px; font-weight:500; margin:0;">
                    {{ $sfNextMonth ? '₱'.number_format($sfNextMonth->forecast_revenue, 2) : '—' }}
                </p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
                <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast units ({{ $sfHorizonMonths }}-month)</p>
                <p style="font-size:22px; font-weight:500; margin:0;">{{ number_format($sfHorizonUnits) }}</p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:1rem; min-width:0;">
                <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast revenue ({{ $sfHorizonMonths }}-month)</p>
                <p style="font-size:22px; font-weight:500; margin:0;">₱{{ number_format($sfHorizonRevenue, 2) }}</p>
            </div>
        </div>

        <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Revenue — actual vs. forecast, with 80% confidence band</p>
        <div style="position:relative; width:100%; height:260px;">
            <canvas id="productSalesForecastChart" role="img" aria-label="Line chart of actual revenue with a dashed forecast continuation and shaded confidence band"></canvas>
        </div>
    @else
        <p style="color:#94a3b8; font-size:13px;">No sales forecast available for this product yet.</p>
    @endif
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
@include('partials._chart-gradient')
<script>
/* Shades the forecast half of the plot.

   The dashed line already says "this part is predicted", but a dash pattern is
   easy to miss at a glance and impossible to see at all on a printed page. A
   tinted band behind the forecast months makes the boundary between what
   happened and what is expected readable instantly.

   Drawn in beforeDatasetsDraw so it sits BEHIND the lines and the confidence
   band rather than washing them out, and clipped to the chart area so it never
   bleeds over the axes. */
const forecastRegionPlugin = {
    id: 'forecastRegion',
    beforeDatasetsDraw(chart, args, opts) {
        const firstIndex = opts && opts.firstForecastIndex;
        if (firstIndex === null || firstIndex === undefined || firstIndex < 0) return;

        const x = chart.scales.x;
        const area = chart.chartArea;
        if (!x || !area) return;

        // Start half a step left of the first forecast point, so the band opens
        // between the last actual and the first forecast rather than cutting
        // through the forecast's own marker.
        const step = L.length > 1 ? (x.getPixelForValue(1) - x.getPixelForValue(0)) : 0;
        const left = Math.max(area.left, x.getPixelForValue(firstIndex) - step / 2);

        const ctx = chart.ctx;
        ctx.save();
        ctx.beginPath();
        ctx.rect(area.left, area.top, area.right - area.left, area.bottom - area.top);
        ctx.clip();
        ctx.fillStyle = 'rgba(79, 70, 229, 0.06)';
        ctx.fillRect(left, area.top, area.right - left, area.bottom - area.top);

        // A hairline at the boundary itself -- the moment 'recorded' becomes
        // 'expected' is worth marking precisely, not just tinting past.
        ctx.beginPath();
        ctx.setLineDash([4, 4]);
        ctx.strokeStyle = 'rgba(79, 70, 229, 0.35)';
        ctx.lineWidth = 1;
        ctx.moveTo(left, area.top);
        ctx.lineTo(left, area.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

// Index of the first month that is forecast-only, i.e. where the actual series
// has run out. -1 (no shading) when everything on the axis is actual.
const L = @json($months);
const actualForShade = @json($actualSeries);
const firstForecastIndex = actualForShade.findIndex((v, i) => v === null && i > 0);

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
    plugins: [forecastRegionPlugin],
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            forecastRegion: { firstForecastIndex },
            legend: {
                display: true,
                labels: {
                    filter: (item) => item.text === 'Actual' || item.text === 'Forecast',
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                // Anchor the label to the nearest data POINT rather than the
                // average of the hovered index. On a spiky series the default
                // parks the tooltip mid-air between the lines; 'nearest' puts
                // it on the peak you are actually pointing at.
                position: 'nearest',
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

@if ($hasSalesForecast)
new Chart(document.getElementById('productSalesForecastChart'), {
    type: 'line',
    data: {
        labels: @json($sfMonths),
        datasets: [
            {
                label: 'Lower bound',
                data: @json($sfRevenueLowerSeries),
                borderWidth: 0,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
            },
            {
                label: 'Confidence band',
                data: @json($sfRevenueUpperSeries),
                borderWidth: 0,
                pointRadius: 0,
                backgroundColor: 'rgba(15, 110, 86, 0.12)',
                fill: '-1',
                spanGaps: false,
            },
            {
                label: 'Actual',
                data: @json($sfActualRevenueSeries),
                borderColor: '#0f6e56',
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 2,
                tension: 0.25,
                spanGaps: false,
            },
            {
                label: 'Forecast',
                data: @json($sfForecastRevenueSeries),
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
            legend: {
                display: true,
                labels: { filter: (item) => item.text === 'Actual' || item.text === 'Forecast' },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                position: 'nearest',
                filter: (item) => item.dataset.label === 'Actual' || item.dataset.label === 'Forecast',
                callbacks: {
                    label: (item) => `${item.dataset.label}: ₱${item.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 18 } },
            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: (v) => '₱' + (v / 1000) + 'k' } },
        },
    },
});
@endif

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
