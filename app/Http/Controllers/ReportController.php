<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** How many rows the analytics top/bottom lists show. */
    private const TOP_N = 10;

    /** Units sold below which a product counts as slow-moving. */
    /**
     * "Slow moving" is a RATE, not a count.
     *
     * This was a flat 5 units applied to whatever window was selected, which
     * only reads correctly over the full four years. Over ONE MONTH it asks
     * "did this product sell 5 units in 30 days?" -- and on a small pharmacy's
     * volumes the answer is no for most of the catalogue, so the report
     * flagged ~2,400 of 2,638 products as slow moving and printed a 3.25 MB
     * page of them. Scaled to the window, the same 5-per-30-days rule means
     * the same thing whichever period is chosen.
     */
    private const SLOW_MOVING_PER_30_DAYS = 5;

    /**
     * How many of them to actually RENDER.
     *
     * The list is ranked slowest-first with the deepest stock on top, so the
     * cap keeps the rows that matter -- the dead stock you would act on --
     * and $slowMovingCount still reports the true total beside it. Without a
     * cap the print copy is ~60 pages of paper for one month.
     */
    private const SLOW_MOVING_LIST_CAP = 100;

    /**
     * How many POS transactions the PRINT copy lists.
     *
     * The screen does not render this table at all -- it shows the day/month
     * breakdown instead -- so this is purely about paper. Once the terminal
     * had a full month of sales behind it, "every transaction in the period"
     * was ~890 rows and a 0.83 MB page, growing with every day the shop
     * trades. The rows are capped; the FOOTER is not, because a grand total
     * that quietly counted only the printed hundred would be the same class
     * of bug as a KPI that disagrees with its own list.
     */
    private const POS_PRINT_CAP = 100;

    public function index()
    {
        return view('reports.index');
    }

    /**
     * A report never covers days that have not happened yet.
     *
     * SalesHistory::dateBounds() already clamps the default range, but the
     * month picker builds its own range with endOfMonth(), which for the
     * current month runs past today -- picking August plotted bars through
     * Aug 31 when the calendar said the 23rd, and the tallest bar on the chart
     * was a day in the future. Every range the reports use funnels through
     * here.
     *
     * String comparison is safe and intentional: both sides are Y-m-d, which
     * sorts lexicographically.
     */
    private function clampEnd(string $end): string
    {
        $today = today()->toDateString();

        return $end > $today ? $today : $end;
    }

    /**
     * Normalise a user-supplied range into one the data can actually answer.
     *
     * clampEnd() only ever moved the END back, which is where the trouble was:
     * a range entirely in the future came out INVERTED. Asking for
     * 2030-01-01 → 2030-12-31 produced start 2030-01-01, end 2026-08-24, and the
     * page then printed that pair as its heading and logged it to the audit
     * trail — a backwards range presented as the range you asked for, with
     * ₱0.00 under it. So did a plainly reversed one (start_date after
     * end_date): the report rendered "no sales" rather than saying the window
     * was the wrong way round, which for a sales report is the worst possible
     * failure mode — an admin reads it as "we sold nothing".
     *
     * Both ends are clamped, then ordered. The range that is queried, the range
     * shown in the heading and the range written to the audit trail are then
     * always the same three dates.
     */
    private function clampRange(?string $start, ?string $end, string $dataStart, string $dataEnd): array
    {
        $start = $this->clampEnd($start ?: $dataStart);
        $end = $this->clampEnd($end ?: $dataEnd);

        // A reversed pair is a slip, not a request for nothing. Order it.
        // A wholly future window collapses to today at both ends, which is the
        // nearest thing the data can answer and is labelled honestly.
        return $start > $end ? [$end, $start] : [$start, $end];
    }

    public function sales(Request $request)
    {
        // The date inputs are type="date", but nothing stops a hand-edited or
        // bookmarked query string. Unvalidated, `?start_date=banana` reached
        // Carbon::parse() inside trendBetween() and came back as a 500
        // (InvalidFormatException) rather than a form error.
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'month' => 'nullable|date_format:Y-m',
        ]);

        // Reports run off `sales_history` -- the imported sales record, which
        // on this install spans 2024-2026. The `sales` table only holds live
        // POS checkouts (a handful of rows, one month), so reporting off it
        // showed an almost-empty report while years of data sat unused.
        [$dataStart, $dataEnd] = SalesHistory::dateBounds();
        $months = SalesHistory::availableMonths();

        // A month picker is the primary control; the date inputs stay for
        // arbitrary ranges. Picking a month overrides the dates.
        $month = $request->get('month');

        // A month only wins when it is the control the user actually used.
        //
        // The form submits every field it owns, so once a month had been chosen
        // its value rode along with every later submission -- and month took
        // priority unconditionally. Editing the start date to the 21st and
        // pressing Generate snapped it straight back to the 1st, so the date
        // inputs looked broken. The form now clears one control when the other
        // is used; this is the server-side half of that rule, and it also
        // covers a hand-edited query string carrying both.
        if ($request->filled('start_date') || $request->filled('end_date')) {
            $month = null;
        }

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
            $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        } else {
            $month = null;
            // Default to the whole history rather than the current calendar
            // month, which would land past the end of the data and read empty.
            $start = $request->get('start_date', $dataStart);
            $end = $request->get('end_date', $dataEnd);
        }

        [$start, $end] = $this->clampRange($start, $end, $dataStart, $dataEnd);

        // Day buckets for short ranges, month buckets for long ones -- see
        // SalesHistory::trendBetween().
        $trend = SalesHistory::trendBetween($start, $end);
        $dailyBreakdown = $trend['rows'];
        $granularity = $trend['granularity'];

        $totalSales = $dailyBreakdown->sum('revenue');
        $totalUnits = $dailyBreakdown->sum('units');
        // Trading days is a day count regardless of how the trend is bucketed.
        $activeDays = $granularity === 'day'
            ? $dailyBreakdown->count()
            : (int) DB::table('sales_history')
                ->whereBetween('sale_date', [$start, $end])
                ->distinct()
                ->count('sale_date');

        // Live POS transactions in the same window, listed separately: they
        // are a different kind of record (transaction no., cashier) and only
        // exist for dates this install actually rang up.
        $sales = Sale::with('user')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->orderBy('created_at')
            ->get();

        $totalTransactions = $sales->count();

        // What the print copy lists, oldest first, so a capped page still reads
        // as the start of the period rather than an arbitrary slice.
        $salesForPrint = $sales->take(self::POS_PRINT_CAP);

        // Split the headline figure into the two records it is actually made of.
        //
        // `Total Sales` comes from trendBetween(), which merges the imported
        // sales_history with live POS checkouts -- but the table underneath
        // lists POS transactions ONLY, because a history row has no transaction
        // number or cashier to show. So the KPI and the table could never agree,
        // and the gap read as a broken sum: Aug 21-25 showed Total Sales
        // P283,265.21 above 16 transactions worth P31,195.98, with nothing to
        // say where the other P252,069.23 came from.
        //
        // Both numbers were right. Neither said what it was counting.
        $posTotal = round((float) $sales->sum('total_amount'), 2);
        $historyTotal = round($totalSales - $posTotal, 2);

        // POS takings per bucket, keyed the same way trendBetween() keys its
        // rows (Y-m-d for a day-bucketed range, Y-m for a month-bucketed one).
        // The breakdown table uses it to show the imported and POS halves of
        // each row side by side, so the headline figure can be followed down
        // the page instead of having to be taken on trust.
        $posByBucket = $sales
            ->groupBy(fn ($sale) => $granularity === 'day'
                ? $sale->created_at->toDateString()
                : $sale->created_at->format('Y-m'))
            ->map(fn ($group) => round((float) $group->sum('total_amount'), 2));

        AuditTrail::log('Viewed', "Generated Sales Report ({$start} to {$end})");

        return view('reports.sales', compact(
            'sales', 'start', 'end', 'totalSales', 'totalTransactions',
            'dailyBreakdown', 'months', 'month', 'totalUnits', 'activeDays',
            'dataStart', 'dataEnd', 'granularity', 'posTotal', 'historyTotal', 'posByBucket',
            'salesForPrint'
        ));
    }

    public function inventory(Request $request)
    {
        $categoryId = $request->get('category_id');
        $lowStockOnly = $request->boolean('low_stock');
        $expiredOnly = $request->boolean('expired');

        $query = Product::with('category', 'batches')->orderBy('name');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get();

        // What "low stock" means ON THIS REPORT.
        //
        // Product::is_low_stock compares SELLABLE stock, which is right for the
        // till and for the alerts -- expired units cannot be dispensed, so a
        // shelf full of them is functionally empty. It reads wrong in a report,
        // though: a product with 40 expired units and a reorder level of 1 was
        // listed as "Low Stock", when its problem is not that it needs
        // reordering. It needs clearing, and the Expired filter and KPI beside
        // this one already say so.
        //
        // So the report asks two things instead, and a row has to pass both:
        //
        //   1. Is it running out of stock it PHYSICALLY has? total_stock <=
        //      reorder_level. This alone drops the obvious case -- 40 expired
        //      units against a reorder level of 1 is not a reorder problem.
        //
        //   2. Is what it is holding actually usable? A product with stock on
        //      the shelf but NOTHING sellable is holding expired units and
        //      nothing else. Clearing them is the job, not reordering, and the
        //      Expired filter beside this one already lists it. On this
        //      catalogue that is every remaining overlap: of the 41 low-stock
        //      rows carrying expired batches, all 41 had zero sellable stock
        //      and none had good stock left alongside.
        //
        // total_stock <= 0 deliberately still counts as low: a product with
        // nothing at all is genuinely out and does need reordering. The
        // exclusion is only for shelves holding stock that cannot be sold.
        //
        // Both tests were worked out here first, then lifted into
        // Product::is_running_out once the Inventory tab, the bell and the
        // dashboard panel turned out to need the same answer. This report is
        // no longer an exception to the rule; only the POS grid still reads
        // is_low_stock, where "can I sell this?" really is the whole question.
        //
        // Product::is_running_out is the one definition -- the Inventory tab,
        // the bell, the dashboard panel and this report all read it, so the
        // four cannot drift into four different ideas of "low stock".
        $isRunningOut = fn ($p) => $p->is_running_out;

        if ($lowStockOnly) {
            // Ordered by what the filter is about, the way the Inventory page
            // already orders its own low-stock tab: worst first, so the row you
            // need to act on is the row at the top rather than whichever
            // product happens to start with "A".
            //
            // Sorted on sellable_stock, tie-broken on total_stock -- the column
            // this table shows -- so equal rows are not in an arbitrary order.
            $products = $products
                ->filter($isRunningOut)
                ->sortBy([
                    fn ($a, $b) => $a->sellable_stock <=> $b->sellable_stock,
                    fn ($a, $b) => $a->total_stock <=> $b->total_stock,
                ])
                ->values();
        }

        // Filtered in PHP for the same reason low_stock is: expiry state is a
        // computed accessor over the loaded batches, not a column. Narrows to
        // products with expired stock STILL ON THE SHELF -- the same set the
        // Expired Stock KPI counts, so the figure and the rows agree.
        if ($expiredOnly) {
            $products = $products->filter(fn ($p) => $p->expired_batches->isNotEmpty())->values();
        }

        $totalStockValue = $products->sum(fn ($p) => $p->total_stock * $p->selling_price);
        // Same definition the filter uses, so the KPI and the rows agree.
        $lowStockCount = $products->filter($isRunningOut)->count();
        $expiredCount = $products->filter(fn ($p) => $p->expired_batches->isNotEmpty())->count();

        // Stock value grouped by category, for the category breakdown chart.
        $stockValueByCategory = $products
            ->groupBy(fn ($p) => $p->category->name ?? 'Uncategorized')
            ->map(fn ($group, $name) => [
                'category' => $name,
                'value' => (float) $group->sum(fn ($p) => $p->total_stock * $p->selling_price),
                'count' => $group->count(),
            ])
            ->sortByDesc('value')
            ->values();

        $categories = Category::orderBy('name')->get();

        $logMsg = 'Generated Inventory Report';
        if ($categoryId || $lowStockOnly || $expiredOnly) {
            $logMsg .= ' (filtered)';
        }
        AuditTrail::log('Viewed', $logMsg);

        return view('reports.inventory', compact(
            'products', 'totalStockValue', 'lowStockCount', 'expiredCount',
            'stockValueByCategory', 'categories', 'categoryId', 'lowStockOnly', 'expiredOnly'
        ));
    }

    public function analytics(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);

        // Same source switch as sales() -- see the note there.
        [$dataStart, $dataEnd] = SalesHistory::dateBounds();
        $months = SalesHistory::availableMonths();

        $month = $request->get('month');

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
            $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        } else {
            $month = null;
            $start = $dataStart;
            $end = $dataEnd;
        }

        // Same normalisation as sales(): a future ?month= would otherwise clamp
        // only the end and leave the range inverted.
        [$start, $end] = $this->clampRange($start, $end, $dataStart, $dataEnd);

        $topProducts = SalesHistory::topProductsBetween($start, $end, self::TOP_N);

        // Slow movers: catalogued products selling below SLOW_MOVING_PER_30_DAYS
        // for the LENGTH of the window (including those that sold none at all).
        $soldQuantities = SalesHistory::unitsSoldBetween($start, $end);

        $windowDays = max(1, Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1);
        $slowThreshold = max(1.0, self::SLOW_MOVING_PER_30_DAYS * $windowDays / 30);

        // Genuinely the slowest movers, not the first ten alphabetically:
        // rank by units sold ascending (0 first), then by remaining stock
        // descending so the biggest dead-stock problems surface first.
        // Stock summed in SQL rather than by hydrating every product with its
        // batches — that pulled 2,637 models plus relations to read one number
        // each and cost ~600ms of PHP on its own.
        $slowAll = DB::table('products')
            ->leftJoin('product_batches', 'product_batches.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.name, products.sku, COALESCE(SUM(product_batches.quantity), 0) AS total_stock')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->get()
            ->filter(fn ($p) => (float) ($soldQuantities[$p->sku] ?? 0) < $slowThreshold)
            ->map(function ($p) use ($soldQuantities) {
                $p->units_sold = (float) ($soldQuantities[$p->sku] ?? 0);
                $p->total_stock = (int) $p->total_stock;

                return $p;
            });

        $slowMovingCount = $slowAll->count();

        // Ranked slowest-first, deepest stock first among equals, then CAPPED:
        // the rows past the cap are all "sold nothing, holds nothing", which is
        // neither actionable nor worth a page of paper. $slowMovingCount still
        // carries the true total so the view can say how many were left out.
        $slowMoving = $slowAll
            ->sortBy([['units_sold', 'asc'], ['total_stock', 'desc']])
            ->take(self::SLOW_MOVING_LIST_CAP)
            ->values();

        $trend = SalesHistory::trendBetween($start, $end);
        $granularity = $trend['granularity'];
        $salesTrend = $trend['rows']
            ->map(fn ($row) => (object) ['date' => $row['label'], 'total' => $row['revenue']])
            ->values();

        // Seasonal trends stay all-time on purpose: a year-over-year pattern
        // is meaningless when scoped to one month.
        $seasonalTrends = SalesHistory::seasonalTrends();

        AuditTrail::log('Viewed', "Generated Analytics Report ({$start} to {$end})");

        return view('reports.analytics', compact(
            'topProducts', 'slowMoving', 'slowMovingCount', 'salesTrend', 'seasonalTrends',
            'months', 'month', 'start', 'end', 'dataStart', 'dataEnd', 'granularity'
        ));
    }
}
