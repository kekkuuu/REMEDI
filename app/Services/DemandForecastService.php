<?php

namespace App\Services;

use App\Models\DemandForecast;
use App\Models\ForecastAccuracy;
use App\Models\Product;
use App\Models\SalesHistory;
use App\Support\ForecastGrade;
use App\Support\ForecastHorizon;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemandForecastService
{
    /**
     * How many months of ACTUAL history the product detail chart shows.
     *
     * The series runs to the start of the catalogue -- 47 months here -- which
     * squeezed four years of mostly-flat line into a strip where the recent
     * months, the ones that actually inform a reorder, were a few pixels wide.
     * A single year keeps one full pass of the seasonal cycle (the Seasonal
     * Pattern panel below the chart reads the FULL history, so nothing is lost
     * by trimming here) while leaving the recent months legible.
     */
    private const CHART_HISTORY_MONTHS = 12;

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
                $like = $this->likeTerm($search);
                $q->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like);
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

        // One query for the whole page's accuracy rather than one per row.
        $accuracyBySku = ForecastAccuracy::whereIn('product_sku', $skusOnPage)->get()->keyBy('product_sku');

        $grouped = DemandForecast::query()
            ->where('forecast_date', '>=', $cutoff)
            ->whereIn('product_sku', $skusOnPage)
            ->orderBy('forecast_date')
            ->get()
            ->groupBy('product_sku');

        // Rebuild rows in the same order as the paginated (by product name)
        // SKU list, not the arbitrary order whereIn/groupBy would give.
        $rows = $skusOnPage
            ->map(function ($sku) use ($grouped, $namesBySku, $categoriesBySku, $accuracyBySku) {
                $rowsForSku = $grouped->get($sku);
                if (! $rowsForSku) {
                    return null;
                }
                // "Next month" must mean a month that is actually ahead of us,
                // and the row shown must be labelled with the month it is for.
                //
                // Two separate ways this went wrong. Each product used to
                // forecast from ITS OWN last month of sales, so a product that
                // stopped selling in mid-2025 had a window that had already
                // closed -- ->first() then showed a year-old figure, and a stale
                // 0.4 units renders as a flat "0". (Both scripts now pad to a
                // shared end month, so every window starts in the same place.)
                //
                // The second is still live and is why this reads $nextMonth and
                // not $currentMonth: the horizon OPENS on the current month,
                // because training stops at the last complete month and the
                // first row is a nowcast of the month in progress. Taking it
                // made the "Next month" column quote a month already 80% over.
                //
                // $isStale drives the "as of <month>" note in the view: showing
                // an old figure is fine, labelling it as next month's is not.
                $nextMonth = ForecastHorizon::firstActionableMonth();
                $upcoming = $rowsForSku->first(fn ($r) => $r->forecast_date >= $nextMonth);
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
                    'grade' => ForecastGrade::for($accuracyBySku->get($sku)),
                ];
            })
            ->filter()
            ->values();

        // reattach pagination metadata so the view can render page links
        $productPage->setCollection($rows);

        return $productPage;
    }

    /**
     * The N products with the most forecast demand, as month-by-month series.
     *
     * One line per product across a shared month axis, so the page can show
     * WHICH products carry the coming demand and how each one moves, rather
     * than only a ranked total. Ranked on the sum over the horizon, because a
     * single spiky month should not outrank a product that is steadily busy.
     *
     * Every series is padded to the full month axis -- a product with no
     * forecast row for a month gets 0, not a hole -- for the same reason
     * forProduct() fills its actuals: a gap in a line implies missing data,
     * and this is a zero.
     *
     * @return array{months: list<string>, series: list<array{sku:string, name:string, values:list<float>}>}
     */
    public function topDemandSeries(int $limit = 10): array
    {
        // ForecastHorizon, not SalesHistory::reportableThrough() -- that one
        // clamps ACTUAL sales_history data to today, a different concern.
        // Comparing a month-start forecast_date against today's date happened
        // to exclude the current month on every day but the 1st (any date
        // after 09-01 is already past forecast_date 2026-09-01), so this was
        // a latent bug rather than a visibly wrong one: loading this page on
        // the 1st of a month would have let that month's nowcast back into
        // "the products carrying the coming demand", one boundary disagreeing
        // with every other "next month" figure in the app for one day a month.
        $from = ForecastHorizon::firstActionableMonth();

        $rows = DB::table('demand_forecasts')
            ->where('forecast_date', '>=', $from)
            ->select('product_sku', 'forecast_date', 'forecast_value')
            ->get();

        if ($rows->isEmpty()) {
            return ['months' => [], 'series' => []];
        }

        $months = $rows->map(fn ($r) => Carbon::parse($r->forecast_date)->format('Y-m'))
            ->unique()->sort()->values();

        $topSkus = $rows->groupBy('product_sku')
            ->map(fn ($g) => (float) $g->sum('forecast_value'))
            ->sortDesc()
            ->take($limit)
            ->keys();

        $names = Product::whereIn('sku', $topSkus)->pluck('name', 'sku');

        $byProduct = $rows->whereIn('product_sku', $topSkus)
            ->groupBy('product_sku')
            ->map(fn ($g) => $g->mapWithKeys(fn ($r) => [
                Carbon::parse($r->forecast_date)->format('Y-m') => (float) $r->forecast_value,
            ]));

        $series = $topSkus->map(fn ($sku) => [
            'sku' => $sku,
            'name' => $names[$sku] ?? $sku,
            'values' => $months->map(fn ($m) => $byProduct[$sku][$m] ?? 0.0)->all(),
        ])->values()->all();

        return ['months' => $months->all(), 'series' => $series];
    }

    /**
     * Catalogue-wide forecast accuracy, and the same figures per model.
     *
     * Averaged across products rather than pooled across every residual, so a
     * handful of very high-volume products cannot dominate the headline: this
     * answers "how wrong is a typical product's forecast", which is the
     * question someone reordering actually has.
     *
     * MAPE is averaged over the products where it is DEFINED, and the count of
     * those is returned alongside it. Treating an undefined MAPE as 0 would
     * quietly flatter the model with every product that sold nothing.
     */
    public function accuracySummary(): ?array
    {
        $overall = ForecastAccuracy::selectRaw(
            'COUNT(*) as scored,'
            .' AVG(mae) as mae,'
            .' AVG(rmse) as rmse,'
            .' AVG(mape) as mape,'
            .' AVG(smape) as smape,'
            .' SUM(mape IS NULL) as mape_undefined,'
            .' MAX(holdout_months) as holdout_months,'
            .' MAX(generated_at) as generated_at'
        )->first();

        if (! $overall || (int) $overall->scored === 0) {
            return null;
        }

        $byMethod = ForecastAccuracy::selectRaw(
            'method, COUNT(*) as products, AVG(mae) as mae, AVG(rmse) as rmse, AVG(mape) as mape'
        )
            ->groupBy('method')
            ->orderByDesc('products')
            ->get();

        return [
            'scored' => (int) $overall->scored,
            'mae' => (float) $overall->mae,
            'rmse' => (float) $overall->rmse,
            'mape' => $overall->mape === null ? null : (float) $overall->mape,
            'smape' => $overall->smape === null ? null : (float) $overall->smape,
            'mape_undefined' => (int) $overall->mape_undefined,
            'holdout_months' => (int) $overall->holdout_months,
            'generated_at' => $overall->generated_at,
            'by_method' => $byMethod,

            // How the catalogue splits across the three verdicts. Counted by
            // running every scored product through the same grader the detail
            // page uses, so a product cannot be Normal on one screen and
            // Acceptable on the other.
            'grades' => $this->gradeCounts(),
        ];
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

        // Clamped to reportableThrough() like every other read of this table:
        // the seeded history runs to the end of the current month regardless of
        // the calendar, so the newest "actual" point otherwise includes days
        // that have not happened. Measured on SKU 4800058516889: 28 units
        // reported for August against 23 actually sold.
        $through = SalesHistory::reportableThrough();

        $actual = DB::table('sales_history')
            ->where('product_sku', $productSku)
            ->where('sale_date', '<=', $through)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(quantity_sold) as total_qty")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total_qty', 'month');

        // Fill the months this product sold NOTHING in with 0.
        //
        // The GROUP BY above returns only months that have rows, so a product
        // that sells sporadically came back as a sparse list -- and the chart
        // builds its x-axis from these keys. ABSOLUTE 6L plotted
        // 2025-09 immediately beside 2026-06: nine missing months collapsed
        // into a single step, so the line appeared to break and jump and the
        // time axis was not a time axis at all.
        //
        // A month with no sales is not missing data, it is a zero. The Python
        // side already treats it that way -- generate_forecasts.py does
        // `series.asfreq("MS", fill_value=0)` before fitting -- so without this
        // the chart was drawing a different series from the one the model was
        // trained on, which is also why the forecast looked unrelated to it.
        //
        // Only the INTERIOR is filled, first sale month to last sale month. It
        // deliberately does not run to today: the current month is partial, and
        // padding it with a 0 would drag the forecast's join point down to zero
        // for any product that simply has not sold yet this month.
        $actual = $this->monthlySeriesTo($actual, $this->lastCompleteDataMonth($through));

        // Trimmed to the recent window for the chart. The forecast rows are
        // untouched -- only how far BACK the actual line reaches changes.
        $actual = $actual->take(-self::CHART_HISTORY_MONTHS);

        $forecast = DemandForecast::forProduct($productSku)->get();

        // Per-product seasonality: average units sold per calendar month,
        // across however many years of sales_history this SKU has. Same
        // "group by (month, year) then average across years" approach as
        // SalesHistory::seasonalTrends() (the store-wide version), just scoped to
        // one product and driven off sales_history/quantity instead of
        // Sale/total_amount.
        // Same cut-off. Without it the current calendar month carries extra
        // days on one of its years only, which skews that month's cross-year
        // average upward -- the shape the seasonality panel exists to show.
        $seasonal = DB::table('sales_history')
            ->where('product_sku', $productSku)
            ->where('sale_date', '<=', $through)
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

            // Passed through rather than recomputed in the view: one boundary,
            // one definition. See App\Support\ForecastHorizon.
            'firstActionableMonth' => ForecastHorizon::firstActionableMonthKey(),

            // Holdout accuracy for THIS product, or null when its history was
            // too short to hold anything back. Null is shown as "not scored"
            // rather than as a zero -- an unmeasured model is not a perfect one.
            'accuracy' => $accuracy = ForecastAccuracy::where('product_sku', $productSku)->first(),

            // The verdict that goes with the numbers. Graded here rather than
            // in the view so the detail page and the list cannot disagree.
            'grade' => ForecastGrade::for($accuracy),
        ];
    }

    /**
     * Count of scored products in each accuracy band, plus the unscored.
     *
     * Graded in PHP rather than with a SQL CASE so there is exactly one
     * definition of the thresholds -- App\Support\ForecastGrade -- and the
     * summary can never drift from the per-product badge.
     *
     * @return array<string, int>
     */
    private function gradeCounts(): array
    {
        $counts = [
            ForecastGrade::NORMAL => 0,
            ForecastGrade::ACCEPTABLE => 0,
            ForecastGrade::NOT_ACCEPTABLE => 0,
            ForecastGrade::UNRATED => 0,
        ];

        ForecastAccuracy::select('mape', 'smape')->chunk(1000, function ($chunk) use (&$counts) {
            foreach ($chunk as $row) {
                $counts[ForecastGrade::for($row)['grade']]++;
            }
        });

        return $counts;
    }

    /**
     * The last month of sales history that is actually COMPLETE.
     *
     * Everything the chart draws is anchored to this one month, so the axis is
     * the same length for every product and meets the forecast without a gap.
     * Judged on the GLOBAL end of the data: an incomplete tail is a property of
     * when collection stopped, not of one product's last sale.
     */
    private function lastCompleteDataMonth(string $through): ?Carbon
    {
        $dataEnd = DB::table('sales_history')->where('sale_date', '<=', $through)->max('sale_date');

        if (! $dataEnd) {
            return null;
        }

        $dataEnd = Carbon::parse($dataEnd)->startOfDay();

        // A month that stops before its own last day is half a month, not a
        // low one. Left in, it renders as a cliff at the right-hand edge of
        // nearly every chart: history here stops on the 15th, and of the 1,043
        // products selling in both July and August, 631 (60%) showed a drop of
        // more than 30% that was purely the calendar -- GLUMET XR appeared to
        // fall from 235 units to 90. Both Python scripts drop it before fitting
        // for the same reason, so this also keeps the chart showing the series
        // the forecast was actually trained on.
        return $dataEnd->isSameDay($dataEnd->copy()->endOfMonth())
            ? $dataEnd->copy()->startOfMonth()
            : $dataEnd->copy()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * Turn a sparse month => qty map into a continuous series ending at $end.
     *
     * Keys are 'Y-m'. Every month from the product's first sale to $end is
     * present; months it sold nothing in are 0, which is what they mean, and
     * anything after $end is dropped.
     *
     * Running to a SHARED end month rather than the product's own last sale is
     * what keeps the axis continuous. Filling only the interior left the months
     * between a product's last sale and the start of the forecast missing from
     * the axis altogether -- 15 of 40 products sampled jumped straight from
     * their last sale to the first forecast month (one from 2024-06 to 2026-08),
     * so the line crossed a two-year void in a single step.
     *
     * @param  Collection<string, mixed>  $actual
     * @return Collection<string, int>
     */
    private function monthlySeriesTo(Collection $actual, ?Carbon $end): Collection
    {
        if ($actual->isEmpty() || $end === null) {
            return $actual;
        }

        $cursor = Carbon::createFromFormat('Y-m', $actual->keys()->first())->startOfMonth();

        if ($cursor->greaterThan($end)) {
            return collect();
        }

        $filled = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $filled[$key] = (int) ($actual[$key] ?? 0);
            $cursor->addMonthNoOverflow();
        }

        return collect($filled);
    }

    /**
     * Escape a user's search term for a LIKE pattern.
     *
     * `%` and `_` are LIKE's own wildcards, so an unescaped term is executed
     * rather than searched for: a bare `%` matched the entire catalogue here,
     * exactly as it did on the eight search sites Controller::likeTerm() was
     * written for. This page was the ninth and was missed.
     *
     * Duplicated rather than called because likeTerm() is `protected` on the
     * base Controller and this is a service. Keep the two in step -- if the
     * escaping rule changes there, change it here.
     */
    private function likeTerm(?string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term).'%';
    }
}
