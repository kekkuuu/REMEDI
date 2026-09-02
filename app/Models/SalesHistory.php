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
     * The last day any aggregate here will report on.
     *
     * The seeded history fills its final month to the last day of that month
     * regardless of the calendar (2026-08-31 while today is the 23rd), so
     * without this the dashboard's monthly chart, Sales Summary ring and
     * demand panels all counted sales that have not happened. The reports
     * clamp the same way -- see ReportController::clampEnd.
     */
    public static function reportableThrough(): string
    {
        return today()->toDateString();
    }

    /**
     * Expiry for the fixed-key aggregates below.
     *
     * They are clamped to "today", so a plain 24h TTL would keep yesterday's
     * cut-off for most of the following day and hold a finished day out of the
     * chart. Whichever comes first: the normal TTL, or midnight.
     */
    private static function cacheUntil()
    {
        $ttl = now()->addHours(self::CACHE_TTL_HOURS);

        return $ttl->greaterThan(now()->endOfDay()) ? now()->endOfDay() : $ttl;
    }

    /**
     * Revenue per calendar month across the whole history, oldest first:
     * [ ['ym' => '2024-01', 'label' => 'Jan 2024', 'total' => 12345.67], ... ]
     *
     * sales_history stores units only, so revenue is units x the product's
     * current selling_price -- the same derivation SalesForecastService uses,
     * kept consistent deliberately.
     */
    /**
     * Revenue by calendar month, BOTH RECORDS.
     *
     * This read sales_history alone, and once the imported record was trimmed
     * back to the day before the terminal went live (2026-08-16 here) that
     * showed on the dashboard immediately: **September was missing from the
     * chart entirely and August stopped on the 15th**, weeks after August had
     * ended. Neither month was wrong in the table it came from -- they were
     * simply the wrong table to ask.
     *
     * POS takings are `sales.total_amount`, which is what the till actually
     * charged, matching the "this terminal" column on the Sales report. (The
     * per-PRODUCT rankings in topProductsBetween() use units x current price on
     * both halves instead, because there the two sources are being compared
     * with each other rather than added up.)
     */
    public static function monthlyRevenue(): Collection
    {
        return Cache::remember(
            // The POS stamp belongs in the key now: a checkout changes this
            // series, and the fixed key would have served yesterday's chart
            // until the TTL ran out.
            self::MONTHLY_CACHE_KEY.':p'.static::posCacheVersion(),
            self::cacheUntil(),
            fn () => DB::table('sales_history')
                ->where('sale_date', '<=', self::reportableThrough())
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
                ->pluck('total', 'ym')
                ->map(fn ($t) => (float) $t)
                ->pipe(function (Collection $history) {
                    // Add the till, month by month. A month present in only one
                    // record still appears -- that is the entire point.
                    foreach (static::posMonthlyRevenue() as $ym => $total) {
                        $history[$ym] = ($history[$ym] ?? 0) + $total;
                    }

                    return $history->sortKeys();
                })
                ->map(fn ($total, $ym) => [
                    'ym' => $ym,
                    // Month AND year: across 30+ months a bare "January"
                    // would repeat and read as the same bar.
                    'label' => Carbon::createFromFormat('Y-m', $ym)->format('M Y'),
                    'total' => round($total, 2),
                ])
                ->values()
        );
    }

    /**
     * Terminal takings by calendar month, clamped like every other aggregate.
     */
    private static function posMonthlyRevenue(): Collection
    {
        return DB::table('sales')
            ->whereDate('created_at', '<=', static::reportableThrough())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total_amount) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym')
            ->map(fn ($t) => (float) $t);
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
        // dependsOnPos, because monthlyRevenue() below does. Derived caches
        // inherit their source's invalidation: while this key was a bare
        // constant, a checkout refreshed the monthly chart and left the
        // quarterly ring beside it serving the previous figures until the TTL
        // expired -- two panels on one dashboard disagreeing about the same
        // quarter. Ask what a cached value READS, not what it is named after.
        return Cache::remember(
            self::QUARTER_CACHE_KEY.':p'.static::posCacheVersion(),
            self::cacheUntil(),
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
                    // Same cut-off as the revenue above it, or the ring
                    // would be labelled with a record count that includes
                    // days the revenue deliberately excludes.
                    'records' => (int) DB::table('sales_history')
                        ->whereYear('sale_date', $year)
                        ->where('sale_date', '<=', self::reportableThrough())
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
        // dependsOnPos: the window now spans the till as well, so a checkout
        // has to retire this. The key was a bare constant while this read
        // history alone.
        return Cache::remember(
            self::DEMAND_CACHE_KEY.':p'.static::posCacheVersion(),
            self::cacheUntil(),
            function () use ($days, $topN, $lowN) {
                // Anchored to TODAY, and spanning both records.
                //
                // It used to anchor to the newest row in sales_history, which
                // was right while that table ran to (and past) the present. It
                // stopped being right the moment the imported record was cut
                // back to the day before the terminal went live: the anchor
                // froze on 2026-08-15, so the dashboard's High/Low Demand cards
                // described the thirty days ending THERE and could not see a
                // single one of the 918 sales the till had taken since. A card
                // labelled "high demand" has to mean demand now.
                //
                // reportableThrough() still does the clamping the old anchor was
                // there for -- no window may include days that have not
                // happened.
                $through = self::reportableThrough();
                $since = Carbon::parse($through)->subDays($days)->toDateString();

                $history = DB::table('sales_history')
                    ->whereBetween('sale_date', [$since, $through])
                    ->selectRaw('product_sku, SUM(quantity_sold) as total_qty')
                    ->groupBy('product_sku')
                    ->pluck('total_qty', 'product_sku')
                    ->map(fn ($q) => (float) $q);

                foreach (static::posUnitsBetween($since, $through) as $sku => $qty) {
                    $history[$sku] = ($history[$sku] ?? 0) + $qty;
                }

                if ($history->isEmpty()) {
                    return ['top' => collect(), 'low' => collect()];
                }

                // Sorted once, then read from both ends: ranking "slowest" by a
                // separate ORDER BY ASC over the same rows is the same list
                // upside down, and doing it twice invited the two to disagree.
                $sorted = $history->sortDesc();

                $names = DB::table('products')
                    ->whereIn('sku', $sorted->keys())
                    ->pluck('name', 'sku');

                $shape = fn ($rows) => $rows->map(fn ($qty, $sku) => (object) [
                    'product_sku' => $sku,
                    'name' => $names[$sku] ?? $sku,
                    'total_qty' => (float) $qty,
                ])->values();

                return [
                    'top' => $shape($sorted->take($topN)),
                    'low' => $shape($sorted->reverse()->take($lowN)),
                ];
            }
        );
    }

    /** Units sold per SKU within a range — powers the slow-mover filter. */
    public static function unitsSoldBetween(string $start, string $end): Collection
    {
        // dependsOnPos: this now folds in the till, so a checkout has to retire
        // it. See rangeKey() -- the POS stamp is bumped by every sale, the
        // history stamp only by a reseed or an import.
        return Cache::remember(
            static::rangeKey('units', [$start, $end], true),
            now()->addHours(self::CACHE_TTL_HOURS),
            function () use ($start, $end) {
                $units = DB::table('sales_history')
                    ->whereBetween('sale_date', [$start, $end])
                    ->selectRaw('product_sku, SUM(quantity_sold) AS qty')
                    ->groupBy('product_sku')
                    ->pluck('qty', 'product_sku');

                foreach (static::posUnitsBetween($start, $end) as $sku => $qty) {
                    $units[$sku] = (float) ($units[$sku] ?? 0) + $qty;
                }

                return $units;
            }
        );
    }

    /**
     * Units sold per SKU ON THIS TERMINAL in a range.
     *
     * The imported record stops the day before the till went live (2026-08-16
     * here), so anything reading sales_history alone reports nothing at all for
     * a recent period -- which is what made the Analytics report print "No sales
     * data." for the current month while the terminal had rung up 73 sales.
     *
     * Keyed on products.sku, like everything else that joins these two records:
     * sale_items carries product_id, sales_history carries the SKU string, and
     * products is the only place they meet.
     */
    private static function posUnitsBetween(string $start, string $end): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
            ->selectRaw('products.sku AS sku, SUM(sale_items.quantity) AS qty')
            ->groupBy('products.sku')
            ->pluck('qty', 'sku')
            ->map(fn ($q) => (float) $q);
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

        // Never past today. The seeded history fills its final month to the
        // last day of that month regardless of the calendar -- on this install
        // it runs to 2026-08-31 while today is the 23rd -- so an unclamped
        // bound put 2,005 rows of not-yet-happened sales into every report's
        // default range. Clamping rather than deleting keeps the data intact:
        // those days come back into range as the calendar reaches them.
        $end = Carbon::parse(max($maxs));

        if ($end->isAfter(today())) {
            $end = today();
        }

        return [
            Carbon::parse(min($mins))->toDateString(),
            $end->toDateString(),
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
            static::rangeKey('top', [$start, $end, $limit], true),
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
            // Ordered by REVENUE, which is what every consumer of this list
            // actually presents: the analytics chart plots total_revenue, the
            // table's Revenue column is the one people read down, and the KPI
            // beside it is captioned "best seller by revenue".
            //
            // It used to order by total_qty, so the bars came out plainly
            // unsorted -- P910,893.84 above P983,800.88 above P24,761.36 -- and
            // the Top Product KPI named HERACLENE 1MG TAB X100 when HEMARATE FA
            // TAB X100 had earned P72,907.04 more. The sort has to happen in SQL
            // rather than on the result, or LIMIT still picks the top N by units
            // and a high-revenue product that sells in small numbers never
            // reaches the list to be re-sorted.
            ->orderByDesc('total_revenue')
            // NO LIMIT here any more: the till's units are folded in below, and
            // a product the terminal sold well could sit outside the top N of
            // the imported half. The ordering stays in SQL for the reason above;
            // it is the CUT that has to wait. ~2,600 rows either way, so the
            // scan is the cost, not the transfer.
            ->get();

        // Fold in the terminal, then re-rank. Revenue on both halves is
        // units x products.selling_price -- the same rule sales_history revenue
        // has always used -- so the two sources are compared like for like. The
        // Sales report's money columns are a different question: there, POS
        // takings are what was actually charged.
        $prices = DB::table('products')->pluck('selling_price', 'sku');
        $names = DB::table('products')->pluck('name', 'sku');

        $byKey = $rows->keyBy('product_sku');

        foreach (static::posUnitsBetween($start, $end) as $sku => $qty) {
            $price = (float) ($prices[$sku] ?? 0);

            if ($existing = $byKey->get($sku)) {
                $existing->total_qty += $qty;
                $existing->total_revenue += $qty * $price;

                continue;
            }

            $byKey->put($sku, (object) [
                'product_sku' => $sku,
                'name' => $names[$sku] ?? $sku,
                'total_qty' => $qty,
                'total_revenue' => $qty * $price,
            ]);
        }

        $rows = $byKey->sortByDesc('total_revenue')->take($limit)->values();

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
     * same way, across the WHOLE requested range.
     *
     * This used to start at MAX(sales_history.sale_date) + 1 day, on the theory
     * that the import owns everything up to its own last day and the POS owns
     * everything after, so summing both would double-count the overlap.
     *
     * There is no overlap to guard against. `sales_history` is written only by
     * SalesHistorySeeder and the receiving import; the POS records into `sales`
     * / `sale_items` and never touches it. The two populations are disjoint by
     * construction, so a boundary can only ever drop real rows — it can never
     * prevent a double count.
     *
     * And drop them it did. The seeded history fills its final month to that
     * month's last day regardless of the calendar (2026-08-31 while today is
     * the 24th), while every report clamps its end to reportableThrough()
     * (today). So `$posFrom` landed a week in the FUTURE, past every possible
     * `$end`, the guard below returned early on every single call, and
     * `$includePos` was a no-op: `trendBetween($s, $e, true)` and
     * `trendBetween($s, $e, false)` returned byte-identical rows. Every live
     * checkout was silently missing from the Sales and Analytics reports —
     * ₱1,459.76 of real takings on the day this was found.
     *
     * Clamping `$historyMax` to today does NOT fix it: the history covers today
     * too, so the boundary still lands on tomorrow, and today is exactly when
     * POS sales happen. The boundary itself had to go.
     *
     * The per-key merge below already sums a day present in both sources, which
     * is what makes dropping the boundary safe — and is a hint that it was
     * written expecting the overlap it then refused to look at.
     */
    private static function mergePos(Collection $rows, string $granularity, string $start, string $end, bool $includePos): Collection
    {
        if (! $includePos) {
            return $rows;
        }

        $pos = static::posTotalsBetween($start, $end);

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
