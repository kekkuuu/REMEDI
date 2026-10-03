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
    {{-- Model accuracy, measured on a holdout rather than asserted (shown
         again 2026-10-02, at the user's request). Each product's own model is
         refitted without the last few months and scored against them, then
         averaged ACROSS PRODUCTS -- not pooled across every residual, which
         would let a handful of very high-volume products set the headline.
         The grade split runs every scored product through ForecastGrade, the
         same grader the detail page's badge uses. --}}
    {{-- Store-wide accuracy (2026-10-02, at the user's request): the same
         model scored on the whole pharmacy's monthly units, every product
         summed. Per-product errors partly cancel when added up, so this is
         lower than the per-product MAPE below -- it answers "how much will the
         store sell?", and is labelled store-wide for exactly that reason. --}}
    @if ($storewide || ($split['storewide'] ?? null))
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-building-store" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                Store-wide forecast accuracy
            </span>
            <span style="font-size:12px; color:#6b7280;">all products added together, units per month</span>
        </div>
        <p style="font-size:12px; color:#64748b; margin:0 0 12px;">
            The same model, forecasting the whole pharmacy's monthly units. Individual products' misses partly
            cancel out when added up, so this is lower than the per-product figures below.
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px;">
            @if ($storewide)
            <div style="background:#f0fdf4; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">MAPE &middot; {{ $storewide['holdout_months'] }}-month holdout</p>
                <p style="font-size:22px; font-weight:600; margin:0; color:#15803d;">{{ number_format($storewide['mape'], 1) }}%</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">
                    {{ \Carbon\Carbon::parse($storewide['months'][0].'-01')->format('M Y') }} – {{ \Carbon\Carbon::parse(end($storewide['months']).'-01')->format('M Y') }}
                </p>
            </div>
            @endif
            @if ($split['storewide'] ?? null)
            <div style="background:#f0fdf4; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">MAPE &middot; {{ $split['train_pct'] }}/{{ $split['test_pct'] }} split</p>
                <p style="font-size:22px; font-weight:600; margin:0; color:#15803d;">{{ number_format($split['storewide']['mape'], 1) }}%</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">tested on {{ $split['storewide']['test_period'] }}</p>
            </div>
            @endif
        </div>

        @if ($storewide)
        <div class="table-scroll"><table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:12px;">
            <thead>
                <tr style="background:#f9fafb;">
                    @foreach (['Month', 'Actual units', 'Forecast', 'Off by'] as $i => $h)
                        <th style="padding:8px 12px; text-align:{{ $i ? 'right' : 'left' }}; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($storewide['months'] as $i => $m)
                    @php $act = $storewide['actual'][$i]; $fc = $storewide['forecast'][$i]; @endphp
                    <tr style="border-bottom:0.5px solid #e5e7eb;">
                        <td style="padding:9px 12px; color:#374151;">{{ \Carbon\Carbon::parse($m.'-01')->format('M Y') }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ number_format($act) }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ number_format($fc) }}</td>
                        <td style="padding:9px 12px; text-align:right; color:#64748b;">{{ $act ? number_format(abs($fc - $act) / $act * 100, 1).'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        @endif
    </div>
    @endif

    {{-- The walk-forward test across the whole record (2026-10-02, at the
         user's request: "test accuracy over 4 years"). Every month forecast
         ONE month ahead from the months before it only -- forecast:evaluate-
         rolling, read through App\Support\ForecastSplit::rolling(). --}}
    @if ($rolling)
    @php
        $rs = $rolling['storewide'];
        $rp = $rolling['per_product'];
        $rPct = fn ($v) => $v === null ? '—' : number_format($v, 1).'%';
        $rMonth = fn ($ym) => \Carbon\Carbon::parse($ym.'-01')->format('M Y');
    @endphp
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-calendar-stats" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                Rolling test over the whole record
            </span>
            <span style="font-size:12px; color:#6b7280;">
                {{ $rMonth($rolling['first_month']) }} – {{ $rMonth($rolling['last_month']) }} &middot; {{ $rs['months'] }} months &middot; run {{ \Carbon\Carbon::parse($rolling['generated_at'])->format('M j, Y') }}
            </span>
        </div>
        <p style="font-size:12px; color:#64748b; margin:0 0 12px;">
            Every month was forecast one month ahead using only the months before it &mdash; what the app does each month
            &mdash; then compared with what actually sold. "Average of last 3 months" is the simple guess, scored on the same months.
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:14px;">
            <div style="background:#f0fdf4; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">Store-wide MAPE</p>
                <p style="font-size:22px; font-weight:600; margin:0; color:#15803d;">{{ $rPct($rs['mape']) }}</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">average of last 3 months: {{ $rPct($rs['baseline_mape']) }}</p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">Per-product MAPE</p>
                <p style="font-size:22px; font-weight:600; margin:0;">{{ $rPct($rp['mape']) }}</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">average of last 3 months: {{ $rPct($rp['baseline_mape']) }} &middot; {{ number_format($rp['products']) }} products</p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">Per-product WAPE</p>
                <p style="font-size:22px; font-weight:600; margin:0;">{{ $rPct($rp['wape']) }}</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">average of last 3 months: {{ $rPct($rp['baseline_wape']) }}</p>
            </div>
        </div>

        <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Store-wide units &mdash; actual vs. the one-month-ahead forecast made the month before</p>
        <div style="position:relative; width:100%; height:220px; margin-bottom:12px;">
            <canvas id="rollingChart" role="img" aria-label="Line chart of actual store-wide units per month against the forecast made one month earlier"></canvas>
        </div>

        <div class="table-scroll"><table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f9fafb;">
                    @foreach (['Year', 'Months', 'Store-wide MAPE', 'Avg of last 3', 'Per-product MAPE', 'Avg of last 3'] as $i => $h)
                        <th style="padding:8px 12px; text-align:{{ $i ? 'right' : 'left' }}; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rs['by_year'] as $year => $y)
                    @php $py = $rp['by_year'][$year] ?? []; @endphp
                    <tr style="border-bottom:0.5px solid #e5e7eb;">
                        <td style="padding:9px 12px; color:#374151;">{{ $year }}</td>
                        <td style="padding:9px 12px; text-align:right; color:#6b7280;">{{ $y['months'] }}</td>
                        <td style="padding:9px 12px; text-align:right; font-weight:600;">{{ $rPct($y['mape']) }}</td>
                        <td style="padding:9px 12px; text-align:right; color:#6b7280;">{{ $rPct($y['baseline_mape']) }}</td>
                        <td style="padding:9px 12px; text-align:right; font-weight:600;">{{ $rPct($py['mape'] ?? null) }}</td>
                        <td style="padding:9px 12px; text-align:right; color:#6b7280;">{{ $rPct($py['baseline_mape'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>
    @endif

    @if ($accuracy)
    @php
        $gradeRows = [
            \App\Support\ForecastGrade::NORMAL => ['Normal', '#16a34a'],
            \App\Support\ForecastGrade::ACCEPTABLE => ['Acceptable', '#d97706'],
            \App\Support\ForecastGrade::NOT_ACCEPTABLE => ['Not acceptable', '#dc2626'],
            \App\Support\ForecastGrade::UNRATED => ['Not rated', '#94a3b8'],
        ];
    @endphp
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-target-arrow" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                Model accuracy
            </span>
            <span style="font-size:12px; color:#6b7280;">
                {{ number_format($accuracy['scored']) }} products &middot;
                {{ $accuracy['holdout_months'] }}-month holdout
                @if (($accuracy['short_holdout'] ?? 0) > 0)
                    ({{ number_format($accuracy['short_holdout']) }} newer {{ Str::plural('product', $accuracy['short_holdout']) }} on a shorter one)
                @endif
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
                    undefined for {{ number_format($accuracy['mape_undefined']) }} {{ Str::plural('product', $accuracy['mape_undefined']) }} that sold nothing
                </p>
            </div>
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">sMAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">
                    {{ $accuracy['smape'] !== null ? number_format($accuracy['smape'], 1).'%' : '—' }}
                </p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">stays defined at zero sales</p>
            </div>
            @if ($accuracy['wape'] !== null)
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">WAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">{{ number_format($accuracy['wape'], 1) }}%</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">total units missed ÷ total sold</p>
            </div>
            @endif
        </div>

        {{-- MAPE by how fast a product sells (2026-10-02). The headline MAPE is
             an average over mostly slow movers, where one unit out is a large
             percentage; this shows the same forecasts per volume band. Bands
             come from the 12 months BEFORE the holdout. --}}
        @if (!empty($accuracy['by_volume']))
        <div class="table-scroll"><table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:12px;">
            <thead>
                <tr style="background:#f9fafb;">
                    @foreach (['Products selling', 'Products', 'MAE', 'MAPE', 'WAPE'] as $i => $h)
                        <th style="padding:8px 12px; text-align:{{ $i ? 'right' : 'left' }}; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($accuracy['by_volume'] as $band)
                <tr style="border-bottom:0.5px solid #e5e7eb;">
                    <td style="padding:9px 12px; color:#374151;">{{ $band['label'] }}</td>
                    <td style="padding:9px 12px; text-align:right; color:#6b7280;">{{ number_format($band['products']) }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ number_format($band['mae'], 2) }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ $band['mape'] !== null ? number_format($band['mape'], 1).'%' : '—' }}</td>
                    <td style="padding:9px 12px; text-align:right;">{{ $band['wape'] !== null ? number_format($band['wape'], 1).'%' : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table></div>
        @endif

        <div style="display:flex; flex-wrap:wrap; gap:8px;">
            @foreach ($gradeRows as $key => [$label, $colour])
                <span style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#f8fafc; border:0.5px solid #e5e7eb; font-size:12px; color:#374151;">
                    <span style="width:8px; height:8px; border-radius:50%; background:{{ $colour }};"></span>
                    {{ $label }} <strong>{{ number_format($accuracy['grades'][$key] ?? 0) }}</strong>
                </span>
            @endforeach
        </div>

        <p style="font-size:11px; color:#94a3b8; margin:10px 0 0;">
            Normal / Acceptable / Not acceptable read MAPE at 20% / 50%, or sMAPE at 40% / 90% where MAPE is undefined.
            MAPE runs high on intermittent demand by construction &mdash; being one unit out on a month that
            sold two is a 50% error &mdash; which is why MAE and sMAPE are shown beside it.
        </p>
    </div>
    @endif

    {{-- The chronological train/test split (2026-10-02, at the user's
         request): each product's first 80% of months train the model, the
         last 20% test it, never shuffled. Overall figures from
         forecast:evaluate-split, read through App\Support\ForecastSplit. The
         two baselines are what a buyer could do with no model at all, so the
         model's numbers mean something only beside them. --}}
    @if ($split)
    @php
        $splitRows = [
            'model' => 'SARIMA model (as in the app)',
            'mean_last_3' => 'Baseline: average of last 3 months',
            'repeat_last' => 'Baseline: repeat last month',
        ];
        $splitPct = fn ($v) => $v === null ? '—' : number_format($v, 1).'%';
        $sm = $split['metrics']['model'];
    @endphp
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-arrows-split-2" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                {{ $split['train_pct'] }}/{{ $split['test_pct'] }} train/test evaluation
            </span>
            <span style="font-size:12px; color:#6b7280;">
                {{ number_format($split['scored']) }} products &middot; run {{ \Carbon\Carbon::parse($split['generated_at'])->format('M j, Y') }}
            </span>
        </div>
        <p style="font-size:12px; color:#64748b; margin:0 0 12px;">
            Each product's months are split in time order: the first {{ $split['train_pct'] }}% train the model, the last
            {{ $split['test_pct'] }}% test it. Most products train on {{ $split['typical']['train_period'] }}
            ({{ $split['typical']['train_months'] }} months) and are tested on {{ $split['typical']['test_period'] }}
            ({{ $split['typical']['test_months'] }} months).
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:14px;">
            @foreach ([['MAE', number_format($sm['mae'], 2), 'units out per month'],
                       ['RMSE', number_format($sm['rmse'], 2), 'large misses weighted heavier'],
                       ['MAPE', $splitPct($sm['mape']), 'on '.number_format($split['mape_defined']).' products that sold'],
                       ['sMAPE', $splitPct($sm['smape']), 'stays defined at zero sales'],
                       ['WAPE', $splitPct($sm['wape']), 'total units missed ÷ total sold']] as [$label, $value, $hint])
                <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                    <p style="font-size:12px; color:#64748b; margin:0 0 4px;">{{ $label }}</p>
                    <p style="font-size:20px; font-weight:600; margin:0;">{{ $value }}</p>
                    <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="table-scroll"><table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:12px;">
            <thead>
                <tr style="background:#f9fafb;">
                    @foreach (['Method', 'MAE', 'RMSE', 'MAPE', 'sMAPE', 'WAPE'] as $i => $h)
                        <th style="padding:8px 12px; text-align:{{ $i ? 'right' : 'left' }}; font-size:11px; font-weight:500; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em;">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($splitRows as $key => $label)
                    @php $m = $split['metrics'][$key] ?? null; @endphp
                    @if ($m)
                    <tr style="border-bottom:0.5px solid #e5e7eb; {{ $key === 'model' ? 'font-weight:600;' : '' }}">
                        <td style="padding:9px 12px; color:#374151;">{{ $label }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ number_format($m['mae'], 2) }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ number_format($m['rmse'], 2) }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ $splitPct($m['mape']) }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ $splitPct($m['smape']) }}</td>
                        <td style="padding:9px 12px; text-align:right;">{{ $splitPct($m['wape']) }}</td>
                    </tr>
                    @endif
                @endforeach
            </tbody>
        </table></div>

        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
            @foreach (['Normal' => '#16a34a', 'Acceptable' => '#d97706', 'Not acceptable' => '#dc2626'] as $g => $colour)
                <span style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#f8fafc; border:0.5px solid #e5e7eb; font-size:12px; color:#374151;">
                    <span style="width:8px; height:8px; border-radius:50%; background:{{ $colour }};"></span>
                    {{ $g }} <strong>{{ number_format($split['grades'][$g] ?? 0) }}</strong>
                </span>
            @endforeach
        </div>

        <p style="font-size:11px; color:#94a3b8; margin:0;">
            The model has a lower MAE than the 3-month average on {{ number_format($split['wins']['mean_last_3']) }}
            of {{ number_format($split['scored']) }} products, and lower than repeating last month on
            {{ number_format($split['wins']['repeat_last']) }}. An evaluation only &mdash; the live forecasts still train on every month.
        </p>
    </div>
    @endif

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
                Top 5 sales forecast by sales product
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
@if ($rolling)
new Chart(document.getElementById('rollingChart'), {
    type: 'line',
    data: {
        labels: @json(array_column($rolling['storewide']['monthly'], 'month')),
        datasets: [
            {
                label: 'Actual',
                data: @json(array_column($rolling['storewide']['monthly'], 'actual')),
                borderColor: '#0f6e56', backgroundColor: 'transparent',
                borderWidth: 2, pointRadius: 2, tension: 0.25,
            },
            {
                label: 'Forecast (made the month before)',
                data: @json(array_column($rolling['storewide']['monthly'], 'forecast')),
                borderColor: '#185FA5', backgroundColor: 'transparent',
                borderWidth: 2, borderDash: [5, 4], pointRadius: 2, tension: 0.25,
            },
        ],
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: true, labels: { boxWidth: 18, font: { size: 11 } } },
            tooltip: { mode: 'index', intersect: false,
                callbacks: { label: (c) => `${c.dataset.label}: ${Math.round(c.parsed.y).toLocaleString()}` } },
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } },
            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { precision: 0 } },
        },
    },
});
@endif

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
            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { precision: 0 } },
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
            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: (v) => (Math.abs(v) >= 1000 ? '₱' + (v / 1000).toLocaleString(undefined, { maximumFractionDigits: 1 }) + 'k' : '₱' + v.toLocaleString()) } },
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
    const url = new URL(baseUrl);
    if (term) {
        url.searchParams.set('search', term);
    }
    if (category) {
        url.searchParams.set('category', category);
    }

    loadList(url, { pushState, term, category });
}

// Scroll so the search bar -- the top of the product list -- sits just under
// the sticky topbar. A page link used to be a full page load, which landed
// at the very top of the page, three charts above the list (2026-09-30).
function scrollToList() {
    const form = document.getElementById('search-form');
    const topbar = document.querySelector('.topbar');
    const offset = (topbar ? topbar.offsetHeight : 0) + 12;
    window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - offset, behavior: 'smooth' });
}

// One loader behind search, filters, page links and back/forward.
function loadList(url, { pushState = true, term = input.value.trim(), category = categorySelect.value, toTop = false } = {}) {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    // Swap the stale rows for a skeleton so a search/filter reads as
    // 'working' instead of leaving the previous results on screen.
    // See REMEDI.holdScroll: read the offset before the rows are gone.
    const restoreScroll = toTop ? () => scrollToList() : REMEDI.holdScroll();

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

// Page links load in place and bring the top of the list into view. Caught
// here, below the layout's document-level navigation handler, so no
// full-page skeleton paints for a list refresh. A modified click (new tab)
// is left to the browser.
wrapper.addEventListener('click', (e) => {
    const link = e.target.closest('#pagination-wrapper a[href]');
    if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    loadList(link.href, { toTop: true });
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const term = params.get('search') || '';
    const category = params.get('category') || '';
    input.value = term;
    categorySelect.value = category;
    // The whole address, page included, so Back returns to the page it left.
    loadList(window.location.href, { pushState: false, term, category });
});
</script>
@endsection
