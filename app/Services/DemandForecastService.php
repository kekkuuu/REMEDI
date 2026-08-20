<?php

namespace App\Services;

use App\Models\DemandForecast;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemandForecastService
{
    /**
     * One row per product: next month's forecast + 6-month total,
     * for a product-list / inventory dashboard view.
     *
     * $search matches against product name OR SKU. $categoryId, when
     * given, restricts to that category.
     */
    public function allProductsSummary(?string $search = null, ?int $categoryId = null)
    {
        // Forecasts always start the month after the last month of source
        // data (sales_history here), not after today's real date. If
        // that source data is older than today -- common with seed/sample
        // data, or if a scheduled regeneration was missed -- every forecast
        // row would land in the past and a hard ">= today" filter would
        // hide the whole table even though valid forecasts exist. So: use
        // today's cutoff only when the data is actually current; otherwise
        // fall back to showing whatever forecast window was generated.
        $today = now()->startOfMonth()->toDateString();
        $earliestForecastDate = DemandForecast::min('forecast_date');
        $cutoff = ($earliestForecastDate && $earliestForecastDate < $today) ? $earliestForecastDate : $today;

        // Paginate PRODUCTS, not forecast rows.
        //
        // This used to select from demand_forecasts with ->distinct(), but
        // Laravel's paginator builds its own `count(*)` query and drops the
        // DISTINCT, so it counted every forecast row: 2,623 products x 6
        // months = 15,738 "results" and 315 pages, most of them repeats of
        // the same products. Driving off `products` and using a subquery for
        // "has a forecast" makes the count honest.
        $productPage = Product::query()
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('products.sku', function ($q) use ($cutoff) {
                $q->select('product_sku')
                    ->from('demand_forecasts')
                    ->where('forecast_date', '>=', $cutoff);
            })
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%");
            }))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->select(
                'products.sku as product_sku',
                'products.name as product_name',
                'categories.name as category_name'
            )
            ->orderBy('products.name')
            ->paginate(50);

        $skusOnPage = collect($productPage->items())->pluck('product_sku');
        $namesBySku = collect($productPage->items())->pluck('product_name', 'product_sku');
        $categoriesBySku = collect($productPage->items())->pluck('category_name', 'product_sku');

        $grouped = DemandForecast::query()
            ->where('forecast_date', '>=', $cutoff)
            ->whereIn('product_sku', $skusOnPage)
            ->orderBy('forecast_date')
            ->get()
            ->groupBy('product_sku');

        // Rebuild rows in the same order as the paginated (by product name)
        // SKU list, not the arbitrary order whereIn/groupBy would give.
        $rows = $skusOnPage
            ->map(function ($sku) use ($grouped, $namesBySku, $categoriesBySku) {
                $rowsForSku = $grouped->get($sku);
                if (! $rowsForSku) {
                    return null;
                }
                // "Next month" must mean the next month that is actually
                // ahead of us. Each product's forecast window starts the month
                // after ITS OWN last month of sales history, so a product that
                // stopped selling in mid-2025 has a window that ended long ago,
                // while most have rows running into 2027. Taking ->first()
                // blindly showed whichever row was oldest -- for hundreds of
                // products a year-old figure, and a stale forecast of 0.4 units
                // renders as a flat "0". That is what "the forecast shows 0"
                // was: a real number from the wrong month.
                //
                // So: the first row from this month onward, falling back to the
                // product's most recent row when its whole window is in the
                // past. $isStale drives the "as of <month>" note in the view --
                // an old figure is fine to show, but not to label as next
                // month's.
                $currentMonth = now()->startOfMonth();
                $upcoming = $rowsForSku->first(fn ($r) => $r->forecast_date >= $currentMonth);
                $isStale = $upcoming === null;
                $first = $upcoming ?? $rowsForSku->last();

                return (object) [
                    'product_sku' => $sku,
                    'product_name' => $namesBySku[$sku] ?? null,
                    'category_name' => $categoriesBySku[$sku] ?? null,
                    'next_month_forecast' => $first->forecast_value,
                    'is_stale' => $isStale,
                    'forecast_date' => $first->forecast_date,
                    'generated_at' => $first->generated_at,
                    'trend_labels' => $rowsForSku->pluck('forecast_date')->map(fn ($d) => $d->format('M')),
                    'trend_values' => $rowsForSku->pluck('forecast_value'),
                ];
            })
            ->filter()
            ->values();

        // reattach pagination metadata so the view can render page links
        $productPage->setCollection($rows);

        return $productPage;
    }

    /**
     * Full history for one product's forecast detail page: the product
     * itself, its actual monthly sales (from sales_history, the same
     * table the forecast model is trained on) so the chart can show real
     * data leading into the forecast, and the full forecast curve with
     * confidence bands.
     *
     * Returns an empty collection (via 'forecast') when nothing exists
     * for this SKU, which the controller treats as a 404.
     */
    public function forProduct(string $productSku): array
    {
        $product = Product::where('sku', $productSku)->first();

        $actual = DB::table('sales_history')
            ->where('product_sku', $productSku)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(quantity_sold) as total_qty")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_qty', 'month');

        $forecast = DemandForecast::forProduct($productSku)->get();

        // Per-product seasonality: average units sold per calendar month,
        // across however many years of sales_history this SKU has. Same
        // "group by (month, year) then average across years" approach as
        // SalesHistory::seasonalTrends() (the store-wide version), just scoped to
        // one product and driven off sales_history/quantity instead of
        // Sale/total_amount.
        $seasonal = DB::table('sales_history')
            ->where('product_sku', $productSku)
            ->selectRaw('MONTH(sale_date) as month_num, YEAR(sale_date) as year_num, SUM(quantity_sold) as qty')
            ->groupBy('month_num', 'year_num')
            ->get()
            ->groupBy('month_num')
            ->map(fn ($rows, $monthNum) => [
                'month_num' => (int) $monthNum,
                'month' => Carbon::create()->month((int) $monthNum)->format('M'),
                'avg_qty' => round((float) $rows->avg('qty'), 1),
                'years_observed' => $rows->count(),
            ])
            ->sortBy('month_num')
            ->values();

        return [
            'product' => $product,
            'product_sku' => $productSku,
            'actual' => $actual,
            'forecast' => $forecast,
            'seasonal' => $seasonal,
        ];
    }
}
