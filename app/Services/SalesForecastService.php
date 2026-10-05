<?php

namespace App\Services;

use App\Models\DemandForecast;
use App\Models\SalesForecast;
use App\Models\SalesHistory;
use App\Support\ForecastHorizon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesForecastService
{
    /**
     * Store-wide monthly trend for the "Sales Trends" panel: actual units
     * and revenue sold per month (from sales_history, joined to products
     * for the current selling_price), continued by the aggregate forecast
     * for months after the last actual month, plus the top 5 products by
     * total units sold.
     */
    /**
     * How long the aggregate below is cached. `sales_history` is written
     * only by seeding/import -- the POS records sales into `sales` /
     * `sale_items`, never here -- so this data is effectively static
     * between imports. GenerateSalesForecast clears the key after it
     * imports, which covers the other half of what the page reads.
     */
    // v2 (2026-10-01): forecast units became whole numbers per month, so a
    // payload cached in the old shape must not be served after a deploy.
    // v3 (2026-10-05): the forecast half now comes from demand_forecasts x
    // price (see pricedForecast()), so a v2 payload holds the old numbers.
    public const CACHE_KEY = 'sales_forecast_overall_trend_v3';

    public const CACHE_TTL_HOURS = 6;

    /**
     * The live key. The POS stamp is part of it now that the actuals fold in
     * the terminal: a checkout or a void changes them. Forget THIS, never the
     * bare CACHE_KEY, which no longer names anything that is stored.
     */
    public static function cacheKey(): string
    {
        return self::CACHE_KEY.':p'.SalesHistory::posCacheVersion();
    }

    public function overallMonthlyTrend(): array
    {
        return Cache::remember(
            self::cacheKey(),
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => $this->computeOverallMonthlyTrend()
        );
    }

    private function computeOverallMonthlyTrend(): array
    {
        // Deliberately two queries, not one merged pass. Folding them into a
        // single LEFT JOIN aggregate was measured and is SLOWER (1339ms vs
        // 375+766ms): the units total needs no join at all, and merging makes
        // it pay for one. Keep them separate.
        // Both actuals stop at reportableThrough(), the same cut-off every other
        // aggregate over this table uses.
        //
        // The seeded history fills its final month to that month's last day
        // regardless of the calendar -- 2026-08-31 while today is the 24th --
        // so without this the "actual" series counted 1,730 rows and 10,640
        // units of sales that have not happened. August 2026 came out at 45,790
        // units / ₱1,699,579.63 here against the dashboard's clamped
        // ₱1,282,779.84: two panels in the same app disagreeing about the same
        // month by ₱416,799.79.
        //
        // It is the last actual point before the forecast begins, which is
        // exactly the bar a user reads to judge whether the forecast looks
        // sane -- so an inflated one discredits a correct forecast.
        $through = SalesHistory::reportableThrough();

        $actualUnits = DB::table('sales_history')
            ->where('sale_date', '<=', $through)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(quantity_sold) as total_qty")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_qty', 'month');

        $actualRevenue = DB::table('sales_history')
            ->join('products', 'products.sku', '=', 'sales_history.product_sku')
            ->where('sale_date', '<=', $through)
            // STRAIGHT_JOIN: see SalesHistory::monthlyRevenue -- the optimiser's
            // own plan for this join measures 53.6s against 3.9s forced.
            ->selectRaw("STRAIGHT_JOIN DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(sales_history.quantity_sold * products.selling_price) as total_revenue")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_revenue', 'month');

        // Plus the terminal (non-voided), then cut to the last COMPLETE month
        // -- the series both Python scripts train on. Reading history alone
        // ended the actual line at July while the forecast opened in
        // September, with August missing from the chart entirely.
        [$actualUnits, $actualRevenue] = $this->withTerminal($actualUnits, $actualRevenue, null);

        $lastActualMonth = $actualUnits->keys()->last();
        $forecastCutoff = $lastActualMonth
            ? Carbon::createFromFormat('Y-m', $lastActualMonth)->endOfMonth()
            : null;

        // ONE pass for all six forecast series, including the confidence
        // bounds the charts shade -- over the DEMAND forecast priced at each
        // product's current selling price (pricedForecast(), 2026-10-05).
        $forecastAgg = $this->pricedForecast()
            ->when($forecastCutoff, fn ($q) => $q->where('df.forecast_date', '>', $forecastCutoff))
            ->selectRaw("DATE_FORMAT(df.forecast_date, '%Y-%m') as month,"
                .' SUM(df.forecast_value) as units,'
                .' SUM(df.lower_ci) as units_lo,'
                .' SUM(df.upper_ci) as units_hi,'
                .' SUM('.self::revenueOf('df.forecast_value').') as revenue,'
                .' SUM('.self::revenueOf('df.lower_ci').') as revenue_lo,'
                .' SUM('.self::revenueOf('df.upper_ci').') as revenue_hi')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Whole units per month (2026-10-01). Each product's sales forecast is
        // stored to two decimals, so the store-wide sum was 73,783.83 etc.:
        // the chart showed it rounded while the 3-month KPI summed the raw
        // figures and rounded once, and the two disagreed by a unit
        // (73,784 + 75,107 + 75,440 = 224,331 on the chart, 224,330 on the
        // card). Rounding here gives every reader the same whole numbers.
        $wholeUnits = fn ($v) => $v === null ? null : (int) round((float) $v);
        $forecastUnits = $forecastAgg->pluck('units', 'month')->map($wholeUnits);
        $forecastUnitsLower = $forecastAgg->pluck('units_lo', 'month')->map($wholeUnits);
        $forecastUnitsUpper = $forecastAgg->pluck('units_hi', 'month')->map($wholeUnits);
        $forecastRevenue = $forecastAgg->pluck('revenue', 'month');
        $forecastRevenueLower = $forecastAgg->pluck('revenue_lo', 'month');
        $forecastRevenueUpper = $forecastAgg->pluck('revenue_hi', 'month');

        // Aggregate on the indexed product_sku FIRST, then resolve names for
        // just the winners. Grouping by products.name meant joining all
        // 100k+ history rows to products before grouping, which was the
        // single slowest query on the page.
        // Clamped like the actuals above. On this data the ORDER happens not to
        // change (42,276 -> 42,061 for the leader, and so on down), but the
        // unit counts shown beside each name were overstated, and a closer pair
        // of products would reorder.
        $topSkus = DB::table('sales_history')
            ->where('sale_date', '<=', $through)
            ->selectRaw('product_sku, SUM(quantity_sold) as total_units')
            ->groupBy('product_sku')
            ->orderByDesc('total_units')
            ->take(5)
            ->pluck('total_units', 'product_sku');

        $namesBySku = DB::table('products')
            ->whereIn('sku', $topSkus->keys())
            ->pluck('name', 'sku');

        $topProducts = $topSkus
            ->map(fn ($units, $sku) => (object) [
                'name' => $namesBySku[$sku] ?? $sku,
                'total_units' => $units,
            ])
            ->values();

        return [
            'actualUnits' => $actualUnits,
            'actualRevenue' => $actualRevenue,
            'forecastUnits' => $forecastUnits,
            'forecastRevenue' => $forecastRevenue,
            // 80% interval, summed across products — see the band datasets in
            // sales_forecast/index.blade.php. The view reads these defensively,
            // because a payload cached before they existed will not have them.
            'forecastUnitsLower' => $forecastUnitsLower,
            'forecastUnitsUpper' => $forecastUnitsUpper,
            'forecastRevenueLower' => $forecastRevenueLower,
            'forecastRevenueUpper' => $forecastRevenueUpper,
            'topProducts' => $topProducts,
            'lastActualMonth' => $lastActualMonth,
        ];
    }

    /**
     * The N products with the most forecast REVENUE over the actionable
     * horizon, as month-by-month series -- the sales-forecast twin of
     * DemandForecastService::topDemandSeries(). Same shape (months + series)
     * so the two charts on the merged forecast page read as a matched pair,
     * and the same "sum over the horizon" ranking for the same reason: a
     * single spiky month should not outrank a product that earns steadily.
     *
     * Ranked on revenue, not units -- demand's top 5 already answers "what
     * needs reordering"; this answers "what earns the most", which is what a
     * sales forecast is for.
     *
     * @return array{months: list<string>, series: list<array{sku:string, name:string, values:list<float>}>}
     */
    public function topSalesForecastSeries(int $limit = 5): array
    {
        $from = ForecastHorizon::firstActionableMonth();

        // Ranked in SQL, winners' rows only -- same change and reason as
        // DemandForecastService::topDemandSeries() (2026-09-30). Revenue is the
        // demand forecast x the current price, as everywhere on this page.
        $horizon = $this->pricedForecast()->where('df.forecast_date', '>=', $from);

        $months = (clone $horizon)->select('df.forecast_date')->distinct()->orderBy('df.forecast_date')
            ->pluck('forecast_date')
            ->map(fn ($d) => substr((string) $d, 0, 7))->unique()->values();

        if ($months->isEmpty()) {
            return ['months' => [], 'series' => []];
        }

        $topSkus = (clone $horizon)->select('df.product_sku')->groupBy('df.product_sku')
            ->orderByRaw('SUM('.self::revenueOf('df.forecast_value').') DESC')->orderBy('df.product_sku')
            ->limit($limit)->pluck('product_sku');

        $names = DB::table('products')->whereIn('sku', $topSkus)->pluck('name', 'sku');

        $byProduct = (clone $horizon)->whereIn('df.product_sku', $topSkus)
            ->select('df.product_sku', 'df.forecast_date')
            ->selectRaw(self::revenueOf('df.forecast_value').' as forecast_revenue')
            ->get()
            ->groupBy('product_sku')
            ->map(fn ($g) => $g->mapWithKeys(fn ($r) => [
                substr((string) $r->forecast_date, 0, 7) => (float) $r->forecast_revenue,
            ]));

        $series = $topSkus->map(fn ($sku) => [
            'sku' => $sku,
            'name' => $names[$sku] ?? $sku,
            'values' => $months->map(fn ($m) => $byProduct[$sku][$m] ?? 0.0)->all(),
        ])->values()->all();

        return ['months' => $months->all(), 'series' => $series];
    }

    /**
     * One product's sales-forecast detail: actual monthly units + revenue
     * (same sales_history read as the store-wide trend, scoped to this SKU,
     * revenue priced at the CURRENT selling_price like every other revenue
     * figure in the app -- see "A price edit rewrites historical revenue")
     * and the forecast curve with confidence bands.
     *
     * Feeds the Sales Forecast section on the merged forecast detail page
     * (forecast/show.blade.php) that sits alongside the Demand Forecast
     * chart, per the product request to show both for one product rather
     * than sending the per-product view to two different pages.
     */
    public function forProduct(string $productSku): array
    {
        $through = SalesHistory::reportableThrough();

        $actualUnits = DB::table('sales_history')
            ->where('product_sku', $productSku)
            ->where('sale_date', '<=', $through)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(quantity_sold) as total_qty")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_qty', 'month');

        $sellingPrice = (float) (DB::table('products')->where('sku', $productSku)->value('selling_price') ?? 0);
        $actualRevenue = $actualUnits->map(fn ($qty) => round($qty * $sellingPrice, 2));

        // Same fold as the store-wide trend: the terminal's sales, up to the
        // last complete month.
        [$actualUnits, $actualRevenue] = $this->withTerminal($actualUnits, $actualRevenue, $productSku);

        // One continuous run of months, zeros included, trimmed to the same
        // window the Demand chart above it shows. The GROUP BY returns only
        // months with sales, and the chart builds its x-axis from these keys --
        // so a sporadic seller plotted 2022-08, 2024-07, 2024-12, 2025-05 side
        // by side and the axis was not a time axis at all. The exact fault the
        // Demand chart was fixed for (DemandForecastService::monthlySeriesTo);
        // this chart never got the same fix.
        if ($actualUnits->isNotEmpty() && ($last = SalesHistory::lastCompleteMonth())) {
            $filledUnits = [];
            $filledRevenue = [];
            $cursor = Carbon::createFromFormat('Y-m', $actualUnits->keys()->first())->startOfMonth();
            $end = Carbon::createFromFormat('Y-m', $last)->startOfMonth();

            while ($cursor->lessThanOrEqualTo($end)) {
                $ym = $cursor->format('Y-m');
                $filledUnits[$ym] = (float) ($actualUnits[$ym] ?? 0);
                $filledRevenue[$ym] = (float) ($actualRevenue[$ym] ?? 0);
                $cursor->addMonthNoOverflow();
            }

            $actualUnits = collect($filledUnits)->take(-DemandForecastService::CHART_HISTORY_MONTHS);
            $actualRevenue = collect($filledRevenue)->take(-DemandForecastService::CHART_HISTORY_MONTHS);
        }

        // The DEMAND forecast's own rows, priced (2026-10-05) -- the same units
        // as the Demand chart above this card, revenue = units x the current
        // selling price, rounded to centavos per month. In-memory SalesForecast
        // models, so the view reads the fields it always has.
        $forecast = DemandForecast::forProduct($productSku)->get()->map(
            fn (DemandForecast $row) => (new SalesForecast)->forceFill([
                'product_sku' => $productSku,
                'forecast_date' => $row->forecast_date,
                'forecast_units' => (float) $row->forecast_value,
                'lower_ci_units' => $row->lower_ci === null ? null : (float) $row->lower_ci,
                'upper_ci_units' => $row->upper_ci === null ? null : (float) $row->upper_ci,
                'forecast_revenue' => round((float) $row->forecast_value * $sellingPrice, 2),
                'lower_ci_revenue' => $row->lower_ci === null ? null : round((float) $row->lower_ci * $sellingPrice, 2),
                'upper_ci_revenue' => $row->upper_ci === null ? null : round((float) $row->upper_ci * $sellingPrice, 2),
            ])
        );

        return [
            'actualUnits' => $actualUnits,
            'actualRevenue' => $actualRevenue,
            'forecast' => $forecast,
            'lastActualMonth' => $actualUnits->keys()->last(),
        ];
    }

    /**
     * The demand forecast with each row's product price beside it -- the ONE
     * source of every forecast unit and peso on the Forecasting pages
     * (2026-10-05, at the user's request: the page must show what the terminal
     * report prints). The report computes revenue as demand units x selling
     * price; sales_forecasts held the same figure but only as fresh as the
     * last 04:30 run, so a model change reached the page a night late, and
     * before 5cb714f it was a separate fit with slightly different units.
     * Current price, like every other revenue figure in the app ("A price edit
     * rewrites historical revenue"). Raw join, so an archived product's
     * forecast is priced too, as the pipeline always priced it.
     */
    private function pricedForecast(): Builder
    {
        return DB::table('demand_forecasts as df')
            ->join('products as p', 'p.sku', '=', 'df.product_sku');
    }

    /** SQL for one priced amount, rounded to centavos like the report's per-row revenue. */
    private static function revenueOf(string $units): string
    {
        return "ROUND({$units} * COALESCE(p.selling_price, 0), 2)";
    }

    /**
     * Add the terminal's non-voided monthly units/revenue to a history-only
     * series, then drop anything after the last complete month.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function withTerminal($units, $revenue, ?string $sku): array
    {
        $units = collect($units)->map(fn ($v) => (float) $v);
        $revenue = collect($revenue)->map(fn ($v) => (float) $v);

        // Same switch the models train under (config forecast.include_pos).
        foreach (config('forecast.include_pos') ? SalesHistory::posMonthly($sku) : [] as $ym => $pos) {
            $units[$ym] = ($units[$ym] ?? 0) + $pos['units'];
            $revenue[$ym] = round(($revenue[$ym] ?? 0) + $pos['revenue'], 2);
        }

        $last = SalesHistory::lastCompleteMonth();
        $keep = fn ($v, $ym) => $last === null || $ym <= $last;

        return [$units->filter($keep)->sortKeys(), $revenue->filter($keep)->sortKeys()];
    }
}
