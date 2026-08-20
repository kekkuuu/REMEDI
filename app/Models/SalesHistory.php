<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesHistory extends Model
{
    /**
     * Explicit: Eloquent would otherwise pluralize this to `sales_histories`,
     * which does not exist. Every caller used DB::table('sales_history')
     * directly, so the mismatch went unnoticed.
     */
    protected $table = 'sales_history';

    protected $fillable = ['product_sku', 'sale_date', 'quantity_sold'];

    protected $casts = ['sale_date' => 'date'];

    /**
     * Aggregates below scan 100k+ rows and join products for price, so they
     * are cached. Safe: this table is written only by the seeder/import --
     * the POS records into `sales` / `sale_items` and never touches it.
     */
    public const MONTHLY_CACHE_KEY = 'sales_history_monthly_revenue';

    public const DEMAND_CACHE_KEY = 'sales_history_recent_demand';

    // Long TTL on purpose: the underlying rows only change when data is
    // imported, and SalesHistorySeeder calls forgetCaches() when that
    // happens. A short TTL would just re-run a multi-second scan on a
    // schedule for data that hasn't moved.
    public const CACHE_TTL_HOURS = 24;

    /** Every key this model caches, so callers can invalidate in one go. */
    public const QUARTER_CACHE_KEY = 'sales_history_quarterly_revenue';

    public const CACHE_KEYS = [self::MONTHLY_CACHE_KEY, self::DEMAND_CACHE_KEY, self::QUARTER_CACHE_KEY];

    /**
     * Version stamp folded into every range-keyed cache key.
     *
     * Range aggregates are keyed by (start, end, limit), so the key space is
     * unbounded and can't be enumerated to invalidate. Bumping this version
     * instead retires every one of them at once — called whenever the
     * underlying sales change (a POS checkout, or a reseed).
     */
    public static function cacheVersion(): int
    {
        return (int) Cache::rememberForever('sales_cache_version', fn () => 1);
    }

    /**
     * Separate stamp for aggregates that fold in live POS takings.
     *
     * A checkout only changes the POS side, so it must not retire the pure
     * sales_history aggregates (top products, units sold) — those cost ~5s to
     * rebuild and haven't actually changed. Only the trend, which merges POS
     * rows, needs to go.
     */
    public static function posCacheVersion(): int
    {
        return (int) Cache::rememberForever('pos_cache_version', fn () => 1);
    }

    public static function bumpCacheVersion(): void
    {
        // A sale only invalidates POS-dependent aggregates.
        Cache::forever('pos_cache_version', static::posCacheVersion() + 1);
    }

    /** Retires everything, including the expensive history-only aggregates. */
    public static function bumpHistoryVersion(): void
    {
        Cache::forever('sales_cache_version', static::cacheVersion() + 1);
        Cache::forever('pos_cache_version', static::posCacheVersion() + 1);
    }

    private static function rangeKey(string $what, array $parts, bool $dependsOnPos = false): string
    {
        $v = 'v'.static::cacheVersion();

        if ($dependsOnPos) {
            $v .= 'p'.static::posCacheVersion();
        }

        return 'sh:'.$v.':'.$what.':'.implode(':', $parts);
    }

    public static function forgetCaches(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }

        // A reseed replaces sales_history itself, so retire every range
        // aggregate, not just the POS-dependent ones.
        static::bumpHistoryVersion();
    }

    /**
     * Revenue per calendar month across the whole history, oldest first:
     * [ ['ym' => '2024-01', 'label' => 'Jan 2024', 'total' => 12345.67], ... ]
     *
     * sales_history stores units only, so revenue is units x the product's
     * current selling_price -- the same derivation SalesForecastService uses,
     * kept consistent deliberately.
     */
    public static function monthlyRevenue(): Collection
    {
        return Cache::remember(
            self::MONTHLY_CACHE_KEY,
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => DB::table('sales_history')
                ->join('products', 'products.sku', '=', 'sales_history.product_sku')
                // STRAIGHT_JOIN forces sales_history to drive this join.
                // Left to itself MySQL starts from products (2.7k rows) and does
                // one lookup per row into sales_history's (product_sku,
                // sale_date) unique index -- a 1022-byte key, because
                // product_sku is VARCHAR(255) utf8mb4. That plan degrades hard
                // as the table grows: measured 53.6s against 3.9s with the
                // order forced, three runs each at 339k rows. Every other
                // aggregate below joins the same two tables and needs the same
                // treatment.
                ->selectRaw(
                    "STRAIGHT_JOIN DATE_FORMAT(sale_date, '%Y-%m') as ym,"
                    .' SUM(sales_history.quantity_sold * products.selling_price) as total'
                )
                ->groupBy('ym')
                ->orderBy('ym')
                ->get()
                ->map(fn ($row) => [
                    'ym' => $row->ym,
                    // Month AND year: across 30+ months a bare "January"
                    // would repeat and read as the same bar.
                    'label' => Carbon::createFromFormat('Y-m', $row->ym)->format('M Y'),
                    'total' => round((float) $row->total, 2),
                ])
                ->values()
        );
    }

    /**
     * Revenue split into calendar quarters for the dashboard's Sales Summary
     * ring, for the most recent year that actually has data.
     *
     * Derived from monthlyRevenue() rather than its own query, so it inherits
     * that cache and the same units x selling_price derivation. Also returns
     * the row count for the year, labelled "sales records" in the view — each
     * sales_history row is one product sold on one date, NOT a basket, so
     * calling it "transactions" would overstate what it counts.
     */
    public static function quarterlyRevenue(): array
    {
        return Cache::remember(
            self::QUARTER_CACHE_KEY,
            now()->addHours(self::CACHE_TTL_HOURS),
            function () {
                $monthly = self::monthlyRevenue();

                if ($monthly->isEmpty()) {
                    return ['year' => null, 'quarters' => [], 'total' => 0.0, 'records' => 0];
                }

                $year = substr($monthly->last()['ym'], 0, 4);
                $quarters = [0.0, 0.0, 0.0, 0.0];

                foreach ($monthly as $row) {
                    if (substr($row['ym'], 0, 4) !== $year) {
                        continue;
                    }

                    $month = (int) substr($row['ym'], 5, 2);
                    $quarters[intdiv($month - 1, 3)] += $row['total'];
                }

                $total = array_sum($quarters);

                return [
                    'year' => $year,
                    'quarters' => array_map(fn ($v) => round($v, 2), $quarters),
                    'total' => round($total, 2),
                    'records' => (int) DB::table('sales_history')
                        ->whereYear('sale_date', $year)
                        ->count(),
                ];
            }
        );
    }

    /**
     * Average revenue per calendar month, averaged ACROSS years rather than
     * summed into 12 buckets, so a partial first/last year doesn't skew
     * whichever months happen to have more years behind them.
     *
     * Same shape Sale::seasonalTrends() returned, so the dashboard and
     * analytics views consume it unchanged. Derived from monthlyRevenue()
     * in PHP -- no second scan of the table.
     */
    public static function seasonalTrends(): Collection
    {
        return static::monthlyRevenue()
            ->groupBy(fn ($row) => (int) substr($row['ym'], 5, 2))
            ->map(fn ($rows, $monthNum) => [
                'month_num' => (int) $monthNum,
                'month' => Carbon::create()->month((int) $monthNum)->format('F'),
                'avg_total' => round((float) $rows->avg('total'), 2),
                'years_observed' => $rows->count(),
            ])
            ->sortBy('month_num')
            ->values();
    }

    /**
     * Best and worst sellers by units over the most recent $days of history:
     * ['top' => Collection, 'low' => Collection], each row exposing
     * product_sku, name and total_qty. Powers the High/Low Demand cards.
     *
     * Anchored to the newest sale_date in the table, NOT to today: this data
     * is imported in batches, so "the last 30 days" measured from today can
     * land entirely after the data ends and come back empty.
     *
     * The ranking is done by the database with ORDER BY ... LIMIT rather than
     * by fetching every product and sorting in PHP. Both are correct, but the
     * fetch-everything version pulled ~1,600 rows (measured 2.1s, vs 0.85s
     * for the two limited queries) and cached all of them just to display
     * eight -- which made every cached read pay to deserialize the other
     * 1,600 as well.
     */
    /**
     * NOTE: the cache key does NOT vary with these arguments. The dashboard is
     * the only caller and always asks the same way; if a second caller ever
     * wants different limits it must either take the key apart or accept
     * whatever the first caller cached. $lowN matches $topN so the two demand
     * panels are the same length -- a five-row list beside a three-row one
     * reads as though the data ran out.
     */
    public static function recentDemand(int $days = 30, int $topN = 5, int $lowN = 5): array
    {
        return Cache::remember(
            self::DEMAND_CACHE_KEY,
            now()->addHours(self::CACHE_TTL_HOURS),
            function () use ($days, $topN, $lowN) {
                $latest = DB::table('sales_history')->max('sale_date');

                if (! $latest) {
                    return ['top' => collect(), 'low' => collect()];
                }

                $since = Carbon::parse($latest)->subDays($days)->toDateString();

                $ranked = function (string $direction, int $limit) use ($since) {
                    return DB::table('sales_history')
                        ->where('sale_date', '>=', $since)
                        ->selectRaw('product_sku, SUM(quantity_sold) as total_qty')
                        ->groupBy('product_sku')
                        ->orderBy('total_qty', $direction)
                        ->limit($limit)
                        ->get();
                };

                $top = $ranked('desc', $topN);
                $low = $ranked('asc', $lowN);

                $names = DB::table('products')
                    ->whereIn('sku', $top->pluck('product_sku')->merge($low->pluck('product_sku')))
                    ->pluck('name', 'sku');

                $shape = fn ($rows) => $rows->map(fn ($row) => (object) [
                    'product_sku' => $row->product_sku,
                    'name' => $names[$row->product_sku] ?? $row->product_sku,
                    'total_qty' => (float) $row->total_qty,
                ])->values();

                return ['top' => $shape($top), 'low' => $shape($low)];
            }
        );
    }

    /** Units sold per SKU within a range — powers the slow-mover filter. */
    public static function unitsSoldBetween(string $start, string $end): Collection
    {
        return Cache::remember(
            static::rangeKey('units', [$start, $end]),
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => DB::table('sales_history')
                ->whereBetween('sale_date', [$start, $end])
                ->selectRaw('product_sku, SUM(quantity_sold) AS qty')
                ->groupBy('product_sku')
                ->pluck('qty', 'product_sku')
        );
    }

    /**
     * Every month that has data, newest first:
     * [ ['ym' => '2026-08', 'label' => 'Aug 2026'], ... ]
     * Drives the month pickers on the report pages.
     */
    public static function availableMonths(): Collection
    {
        return static::monthlyRevenue()
            ->map(fn ($row) => ['ym' => $row['ym'], 'label' => $row['label']])
            ->reverse()
            ->values();
    }

    /**
     * The full span of recorded sales, as [start, end] Y-m-d strings.
     *
     * Spans BOTH sources: the imported history and live POS sales. Bounding
     * on history alone ended the range at the last imported day, so anything
     * rung up since then fell outside every report's default window.
     */
    public static function dateBounds(): array
    {
        $hist = DB::table('sales_history')
            ->selectRaw('MIN(sale_date) AS mn, MAX(sale_date) AS mx')
            ->first();

        $pos = DB::table('sales')
            ->selectRaw('MIN(DATE(created_at)) AS mn, MAX(DATE(created_at)) AS mx')
            ->first();

        $mins = array_filter([$hist->mn ?? null, $pos->mn ?? null]);
        $maxs = array_filter([$hist->mx ?? null, $pos->mx ?? null]);

        if (! $mins) {
            return [now()->startOfMonth()->toDateString(), now()->toDateString()];
        }

        return [
            Carbon::parse(min($mins))->toDateString(),
            Carbon::parse(max($maxs))->toDateString(),
        ];
    }

    /**
     * Daily units + revenue between two dates (inclusive).
     * Not cached: the range is user-chosen, so the key space is unbounded and
     * a cache would mostly miss. It's indexed on sale_date via the primary
     * unique key, and a single month is a small slice of the table.
     */
    public static function dailyTotals(string $start, string $end): Collection
    {
        return DB::table('sales_history')
            ->join('products', 'products.sku', '=', 'sales_history.product_sku')
            ->whereBetween('sale_date', [$start, $end])
            ->selectRaw(
                // STRAIGHT_JOIN: see monthlyRevenue.
                'STRAIGHT_JOIN sale_date,'
                .' SUM(sales_history.quantity_sold) AS units,'
                .' COUNT(DISTINCT sales_history.product_sku) AS products,'
                .' SUM(sales_history.quantity_sold * products.selling_price) AS revenue'
            )
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->sale_date)->toDateString(),
                'units' => (int) $r->units,
                'products' => (int) $r->products,
                'revenue' => round((float) $r->revenue, 2),
            ]);
    }

    /** Top products by units within a date range, names resolved. */
    public static function topProductsBetween(string $start, string $end, int $limit = 10): Collection
    {
        // Measured 2.8-3.6s uncached over the full 2024-2026 range, and it is
        // the default view of the Analytics report.
        return Cache::remember(
            static::rangeKey('top', [$start, $end, $limit]),
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => static::computeTopProductsBetween($start, $end, $limit)
        );
    }

    private static function computeTopProductsBetween(string $start, string $end, int $limit): Collection
    {
        $rows = DB::table('sales_history')
            ->join('products', 'products.sku', '=', 'sales_history.product_sku')
            ->whereBetween('sale_date', [$start, $end])
            ->selectRaw(
                // STRAIGHT_JOIN: see monthlyRevenue.
                'STRAIGHT_JOIN sales_history.product_sku,'
                .' MAX(products.name) AS name,'
                .' SUM(sales_history.quantity_sold) AS total_qty,'
                .' SUM(sales_history.quantity_sold * products.selling_price) AS total_revenue'
            )
            ->groupBy('sales_history.product_sku')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => (object) [
            'product_sku' => $r->product_sku,
            'name' => $r->name,
            'total_qty' => (int) $r->total_qty,
            'total_revenue' => round((float) $r->total_revenue, 2),
        ]);
    }

    /**
     * Units + revenue bucketed by DAY for short ranges and by MONTH for long
     * ones, returned as ['granularity' => 'day'|'month', 'rows' => Collection].
     *
     * A single month is ~30 points; the full 2024-2026 history is ~955. Charting
     * and tabulating all 955 produced a 1.3MB page and an unreadable axis, so
     * anything past a quarter is rolled up to months instead.
     */
    public const DAILY_GRANULARITY_MAX_DAYS = 92;

    /**
     * Live POS takings per day, from `sales` / `sale_items`.
     *
     * The imported history stops at whatever date was last loaded, so sales
     * rung up since then exist only here. Reporting off history alone made
     * today's till vanish from the charts.
     */
    public static function posTotalsBetween(string $start, string $end): Collection
    {
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
            ->selectRaw(
                'DATE(sales.created_at) AS d,'
                .' SUM(sale_items.quantity) AS units,'
                .' COUNT(DISTINCT sale_items.product_id) AS products,'
                .' SUM(sale_items.subtotal) AS revenue'
            )
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->d)->toDateString(),
                'units' => (int) $r->units,
                'products' => (int) $r->products,
                'revenue' => round((float) $r->revenue, 2),
            ]);
    }

    /**
     * $includePos appends live POS sales for dates AFTER the imported history
     * ends. Split at that boundary rather than summing both everywhere, so a
     * day covered by the import isn't counted twice.
     */
    public static function trendBetween(string $start, string $end, bool $includePos = true): array
    {
        // Cache only the history half. It's the expensive part (~2.5s) and a
        // POS sale doesn't change it — so a checkout no longer forces that
        // whole aggregate to be rebuilt. The POS delta is a handful of rows
        // from `sales`, cheap enough to merge fresh every time.
        $base = Cache::remember(
            static::rangeKey('trend', [$start, $end]),
            now()->addHours(self::CACHE_TTL_HOURS),
            fn () => static::computeTrendBetween($start, $end, false)
        );

        if (! $includePos) {
            return $base;
        }

        return [
            'granularity' => $base['granularity'],
            'rows' => static::mergePos($base['rows'], $base['granularity'], $start, $end, true),
        ];
    }

    private static function computeTrendBetween(string $start, string $end, bool $includePos): array
    {
        $span = Carbon::parse($start)->diffInDays(Carbon::parse($end), true);

        if ($span <= self::DAILY_GRANULARITY_MAX_DAYS) {
            $daily = static::dailyTotals($start, $end)->map(fn ($r) => [
                'key' => $r['date'],
                'label' => Carbon::parse($r['date'])->format('M j'),
                'units' => $r['units'],
                'products' => $r['products'],
                'revenue' => $r['revenue'],
            ]);

            return ['granularity' => 'day', 'rows' => static::mergePos($daily, 'day', $start, $end, $includePos)];
        }

        $rows = DB::table('sales_history')
            ->join('products', 'products.sku', '=', 'sales_history.product_sku')
            ->whereBetween('sale_date', [$start, $end])
            ->selectRaw(
                // STRAIGHT_JOIN: see monthlyRevenue.
                "STRAIGHT_JOIN DATE_FORMAT(sale_date, '%Y-%m') AS ym,"
                .' SUM(sales_history.quantity_sold) AS units,'
                .' COUNT(DISTINCT sales_history.product_sku) AS products,'
                .' SUM(sales_history.quantity_sold * products.selling_price) AS revenue'
            )
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->ym,
                'label' => Carbon::createFromFormat('Y-m', $r->ym)->format('M Y'),
                'units' => (int) $r->units,
                'products' => (int) $r->products,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        return ['granularity' => 'month', 'rows' => static::mergePos($rows, 'month', $start, $end, $includePos)];
    }

    /**
     * Fold live POS rows into an already-bucketed history series, keyed the
     * same way, only for dates the import doesn't cover.
     */
    private static function mergePos(Collection $rows, string $granularity, string $start, string $end, bool $includePos): Collection
    {
        if (! $includePos) {
            return $rows;
        }

        $historyMax = DB::table('sales_history')->max('sale_date');
        $posFrom = $historyMax
            ? Carbon::parse($historyMax)->addDay()->toDateString()
            : $start;

        if ($posFrom > $end) {
            return $rows;
        }

        $pos = static::posTotalsBetween(max($posFrom, $start), $end);

        if ($pos->isEmpty()) {
            return $rows;
        }

        $byKey = $rows->keyBy('key');

        foreach ($pos as $row) {
            $date = Carbon::parse($row['date']);
            $key = $granularity === 'month' ? $date->format('Y-m') : $row['date'];
            $label = $granularity === 'month' ? $date->format('M Y') : $date->format('M j');

            $existing = $byKey->get($key);

            $byKey->put($key, [
                'key' => $key,
                'label' => $label,
                'units' => ($existing['units'] ?? 0) + $row['units'],
                'products' => max($existing['products'] ?? 0, $row['products']),
                'revenue' => round(($existing['revenue'] ?? 0) + $row['revenue'], 2),
            ]);
        }

        return $byKey->sortKeys()->values();
    }
}
