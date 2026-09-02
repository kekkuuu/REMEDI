<?php

namespace App\Services;

use App\Models\SalesForecast;
use App\Models\SalesHistory;
use Illuminate\Support\Carbon;
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
    public const CACHE_KEY = 'sales_forecast_overall_trend';

    public const CACHE_TTL_HOURS = 6;

    public function overallMonthlyTrend(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
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

        $lastActualMonth = $actualUnits->keys()->last();
        $forecastCutoff = $lastActualMonth
            ? Carbon::createFromFormat('Y-m', $lastActualMonth)->endOfMonth()
            : null;

        // ONE pass over sales_forecasts for all six forecast series, including
        // the confidence bounds the charts shade.
        //
        // This is not the merge the note above warns against: that one was
        // about the two ACTUAL queries, where folding units into the revenue
        // query makes the units total pay for a join it does not need. These
        // six aggregates come from the same table with no join and the same
        // GROUP BY, so they were two queries doing identical work twice.
        $forecastAgg = SalesForecast::query()
            ->when($forecastCutoff, fn ($q) => $q->where('forecast_date', '>', $forecastCutoff))
            ->selectRaw("DATE_FORMAT(forecast_date, '%Y-%m') as month,
                         SUM(forecast_units)   as units,
                         SUM(lower_ci_units)   as units_lo,
                         SUM(upper_ci_units)   as units_hi,
                         SUM(forecast_revenue) as revenue,
                         SUM(lower_ci_revenue) as revenue_lo,
                         SUM(upper_ci_revenue) as revenue_hi")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $forecastUnits = $forecastAgg->pluck('units', 'month');
        $forecastUnitsLower = $forecastAgg->pluck('units_lo', 'month');
        $forecastUnitsUpper = $forecastAgg->pluck('units_hi', 'month');
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
}
