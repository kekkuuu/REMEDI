@extends('layouts.app')

@section('title', 'Forecasting')

@section('content')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')

@php
    // Store-wide trend, same merge-into-one-axis technique the per-product
    // detail chart below uses: actual months from sales_history, continued
    // by the aggregate forecast, stitched at the last actual month so the
    // dashed line starts exactly where the solid one ends.
    $months = $trend['actualUnits']->keys()->merge($trend['forecastUnits']->keys())->unique()->sort()->values();

    // The till's own sales for the months AFTER the sales record ends
    // (2026-10-25, at the user's request: "make the forecasting the actual"),
    // drawn on the Actual line so Aug-Oct 2026 show what really sold. Display
    // only: the models still learn from the record alone while
    // config('forecast.include_pos') is off. A month the till did not run in
    // (July 2026) stays a gap.
    // Their VALUES stay (in the tooltip); the LINE and dots are not
    // drawn (2026-10-26, at the user's request: "hide the line, not the value")
    // -- see actualLineToRecordEnd() in the script below.
    $showTillOnActual = true;
    $tillAfterRecord = (! $showTillOnActual || config('forecast.include_pos')) ? collect()
        : \App\Models\SalesHistory::posMonthly()
            // Complete months only: the month in progress would read as a fall.
            ->filter(fn ($v, $ym) => $ym > $trend['lastActualMonth'] && $ym < now()->format('Y-m'));

    $actualUnitsSeries = $months->map(fn ($m) => $trend['actualUnits'][$m] ?? ($tillAfterRecord[$m]['units'] ?? null));
    $forecastUnitsSeries = $months->map(function ($m) use ($trend) {
        if (array_key_exists($m, $trend['forecastUnits']->toArray())) {
            return (float) $trend['forecastUnits'][$m];
        }
        return $m === $trend['lastActualMonth'] ? (float) $trend['actualUnits'][$m] : null;
    });

    $actualRevenueSeries = $months->map(fn ($m) => $trend['actualRevenue'][$m] ?? (isset($tillAfterRecord[$m]) ? round($tillAfterRecord[$m]['revenue'], 2) : null));
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

    // HIDDEN 2026-10-23, at the user's request: the accuracy metrics (Model
    // accuracy and the 80/20 train/test card) and the "Top 5 products in
    // demand" chart. Hidden, not deleted -- flip either flag to bring them back;
    // the controller still computes them. The other Top 5 chart, "Top 5 sales
    // forecast by sales product", followed on 2026-10-24, also at the user's
    // request -- the page now has no Top 5 chart at all.
    $showAccuracyMetrics = false;
    $showTopDemand = false;
    $showTopSales = false;
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
            {{-- Not "last month with sales" (2026-10-25): the till records sales
                 after it, which the dashboard shows. It is the last month of the
                 sales record the forecast learns from -- the imported record
                 alone while config('forecast.include_pos') is off. --}}
            <p style="font-size:13px; color:#64748b; margin:0 0 4px;">Forecast learned from sales through</p>
            <p style="font-size:22px; font-weight:500; margin:0;">{{ $trend['lastActualMonth'] ?? '—' }}</p>
            @unless(config('forecast.include_pos'))
                <p style="font-size:11.5px; color:#94a3b8; margin:4px 0 0;">the imported sales record; the till's own sales are not used</p>
            @endunless
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

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Units sold — actual vs. forecast</p>
    <div style="position:relative; width:100%; height:230px; margin-bottom:24px;">
        <canvas id="unitsTrendChart" role="img" aria-label="Line chart of total units sold per month, actual with a dashed forecast continuation"></canvas>
    </div>

    <p style="font-size:13px; color:#64748b; margin:0 0 8px;">Revenue — actual vs. forecast</p>
    <div style="position:relative; width:100%; height:230px;">
        <canvas id="revenueTrendChart" role="img" aria-label="Line chart of total revenue per month, actual with a dashed forecast continuation"></canvas>
    </div>
</div>

<div class="card">
    {{-- Model accuracy, back on the page 2026-10-13 at the user's request (removed
         2026-10-06). Measured on a holdout rather than asserted: each product's own
         SARIMA model is refitted without its last few months and scored against
         them, averaged ACROSS PRODUCTS. The median sits beside the average because
         a few slow sellers with very large percentage misses pull the average up.
         The grade split runs every scored product through ForecastGrade. --}}
    @if ($showAccuracyMetrics && $accuracy)
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
                @if (($accuracy['scorable'] ?? 0) > $accuracy['scored'])
                    {{ number_format($accuracy['scored']) }} of {{ number_format($accuracy['scorable']) }} products scored
                    ({{ number_format($accuracy['scorable'] - $accuracy['scored']) }} have too little sales history to test) &middot;
                @else
                    {{ number_format($accuracy['scored']) }} products &middot;
                @endif
                @if ($accuracy['holdout_label'] ?? null)
                    checked on {{ $accuracy['holdout_label'] }}, hidden from the model and then forecast
                @else
                    {{ $accuracy['holdout_months'] }}-month holdout
                @endif
                @if (($accuracy['short_holdout'] ?? 0) > 0)
                    ({{ number_format($accuracy['short_holdout']) }} newer {{ Str::plural('product', $accuracy['short_holdout']) }} on fewer of those months)
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
                {{-- Plain words (2026-10-15, at the user's request): "undefined"
                     read as "unidentified". MAPE divides by units sold, so a
                     product that sold 0 in every test month has none. --}}
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">
                    @if ($accuracy['mape_undefined'] > 0)
                        average of {{ number_format($accuracy['scored'] - $accuracy['mape_undefined']) }} products &middot;
                        can't be calculated for {{ number_format($accuracy['mape_undefined']) }} that sold nothing in
                        {{ ($accuracy['holdout_label'] ?? null) ? 'the months checked ('.$accuracy['holdout_label'].')' : 'the months checked' }}
                    @else
                        average of all {{ number_format($accuracy['scored']) }} products
                    @endif
                </p>
            </div>
            @if (($accuracy['mape_median'] ?? null) !== null)
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">Median MAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">{{ number_format($accuracy['mape_median'], 1) }}%</p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">the typical product, not pulled up by a few large misses</p>
            </div>
            @endif
            <div style="background:#f8fafc; border-radius:8px; padding:12px 14px;">
                <p style="font-size:12px; color:#64748b; margin:0 0 4px;">sMAPE</p>
                <p style="font-size:20px; font-weight:600; margin:0;">
                    {{ $accuracy['smape'] !== null ? number_format($accuracy['smape'], 1).'%' : '—' }}
                </p>
                <p style="font-size:11px; color:#94a3b8; margin:4px 0 0;">still works when a product sold nothing</p>
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
        @if (! empty($accuracy['env']))
            <p style="font-size:11px; color:#94a3b8; margin:6px 0 0;">
                @if ($accuracy['generated_at'])
                    Run {{ \Carbon\Carbon::parse($accuracy['generated_at'])->format('M j, Y g:i A') }} &middot;
                @endif
                {{ collect($accuracy['env'])->map(fn ($v, $k) => ucfirst($k).' '.$v)->implode(' · ') }}
            </p>
        @endif
    </div>
    @endif


    {{-- The chronological train/test split (2026-10-02, at the user's
         request; removed 2026-10-06, back 2026-10-16, both at the user's
         request): each product's first 80% of months train the model, the
         last 20% test it, never shuffled. Overall figures from
         forecast:evaluate-split --summary, read through App\Support\ForecastSplit.
         The two baselines are what a buyer could do with no model at all, so
         the model's numbers mean something only beside them -- and the card
         says plainly when the model loses to them. --}}
    @if ($showAccuracyMetrics && $split)
    @php
        $splitRows = [
            'model' => 'SARIMA model (as in the app)',
            'mean_last_3' => 'Baseline: average of last 3 months',
            'repeat_last' => 'Baseline: repeat last month',
        ];
        $splitPct = fn ($v) => $v === null ? '—' : number_format($v, 1).'%';
        $sm = $split['metrics']['model'];
        $sb = $split['metrics']['mean_last_3'] ?? null;
    @endphp
    <div style="border:0.5px solid #e5e7eb; border-radius:12px; background:#fff; padding:16px; margin-bottom:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
            <span style="font-size:14px; font-weight:500; color:#111;">
                <i class="ti ti-arrows-split-2" style="font-size:14px; vertical-align:-1px; margin-right:6px; color:#185FA5;"></i>
                {{ $split['train_pct'] }}/{{ $split['test_pct'] }} train/test evaluation
            </span>
            <span style="font-size:12px; color:#6b7280;">
                {{ number_format($split['scored']) }} of {{ number_format($split['products']) }} products &middot; run {{ \Carbon\Carbon::parse($split['generated_at'])->format('M j, Y') }}
            </span>
        </div>
        <p style="font-size:12px; color:#64748b; margin:0 0 12px;">
            Each product's months are split in time order, never shuffled: the first {{ $split['train_pct'] }}% train the model, the last
            {{ $split['test_pct'] }}% test it. Most products train on {{ $split['typical']['train_period'] }}
            ({{ $split['typical']['train_months'] }} months) and are tested on {{ $split['typical']['test_period'] }}
            ({{ $split['typical']['test_months'] }} months ahead, in one go).
        </p>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:14px;">
            @foreach ([['MAE', number_format($sm['mae'], 2), 'units out per month'],
                       ['RMSE', number_format($sm['rmse'], 2), 'large misses weighted heavier'],
                       ['MAPE', $splitPct($sm['mape']), 'average of '.number_format($split['mape_defined']).' products'],
                       ['sMAPE', $splitPct($sm['smape']), 'still works when a product sold nothing'],
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
            @if ($split['storewide'] ?? null)
                <span style="display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#f0fdf4; border:0.5px solid #bbf7d0; font-size:12px; color:#166534;">
                    Whole store (all products added up): MAPE <strong>{{ $splitPct($split['storewide']['mape']) }}</strong>
                </span>
            @endif
        </div>

        <p style="font-size:11px; color:#94a3b8; margin:0;">
            The model has a lower MAE than the 3-month average on {{ number_format($split['wins']['mean_last_3']) }}
            of {{ number_format($split['scored']) }} products, and lower than repeating last month on
            {{ number_format($split['wins']['repeat_last']) }}.
            @if ($sb && $sm['mae'] > $sb['mae'])
                Over this long a horizon the simple average does better overall: the test months follow a period of
                fast growth, and the model carries that growth forward while sales levelled off.
            @endif
            An evaluation only &mdash; the live forecasts still train on every month.
        </p>
    </div>
    @endif

    {{-- Two "top 5" charts, side by side conceptually but stacked here since
         each needs its own width: demand (units to buy, DemandForecastService)
         and sales forecast (revenue to expect, SalesForecastService). They
         answer different questions on purpose -- what to reorder vs. what
         earns -- rather than the same ranking told twice, which is why a
         product can appear on one and not the other. --}}
    @if ($showTopDemand && !empty($topDemand['series']))
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

    @if ($showTopSales && !empty($topSales['series']))
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

/* Tooltips read plain "Actual" / "Forecast" (2026-10-25, at the user's
   request). At the JOIN month (the record's last month) the forecast only
   repeats the actual, so the tooltip shows the actual alone there -- and only
   there. Same rule as the product page (forecast/show). */
const trendJoin = @json($months).indexOf(@json($trend['lastActualMonth']));

/* The Actual LINE stops where the sales record ends (2026-10-26, at the user's
   request: "hide the line, not the value", then "and also the dots"): the
   till's months after it have no line and no dot, only their tooltip value. */
const trendMonths = @json($months);
const recordEnd = @json($trend['lastActualMonth']);
const actualLineToRecordEnd = {
    segment: { borderColor: (ctx) => (recordEnd && trendMonths[ctx.p1DataIndex] > recordEnd ? 'transparent' : undefined) },
    // No dot either ("and also the dots"): the value is in the tooltip.
    pointRadius: (ctx) => (recordEnd && trendMonths[ctx.dataIndex] > recordEnd ? 0 : 2),
    pointHoverRadius: (ctx) => (recordEnd && trendMonths[ctx.dataIndex] > recordEnd ? 0 : 4),
};
function trendTooltip(fmt) {
    return {
        filter: (item) => item.dataset.label === 'Actual'
            || (item.dataset.label === 'Forecast' && item.dataIndex !== trendJoin),
        label: (c) => c.dataset.label + ': ' + fmt(c.parsed.y),
    };
}
const unitsTrendTip = trendTooltip((v) => Math.round(v).toLocaleString());
const revenueTrendTip = trendTooltip((v) => '₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

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
                ...actualLineToRecordEnd,
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
                filter: unitsTrendTip.filter,
                callbacks: { label: unitsTrendTip.label },
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
                ...actualLineToRecordEnd,
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
                // Two decimals for pesos; the units chart above rounds on purpose.
                filter: revenueTrendTip.filter,
                callbacks: { label: revenueTrendTip.label },
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
