<?php

namespace App\Http\Controllers;

use App\Exports\AnalyticsReportExport;
use App\Exports\InventoryReportExport;
use App\Exports\SalesReportExport;
use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesHistory;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

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

    /**
     * How many products the Inventory Report's PDF export renders.
     *
     * dompdf builds the PDF from rendered HTML rather than streaming rows the
     * way the Excel export does, so the unfiltered catalogue (~2,600 products)
     * risks a slow or memory-heavy render on a serverless request. Ordered by
     * the same query as the page, so a capped export is always "the first N of
     * the report you were looking at", not an arbitrary slice.
     */
    private const INVENTORY_PDF_CAP = 500;

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
     * Normalise to Y-m-d before comparing, the same reasoning as
     * SaleController::orderedRange(): the callers' `nullable|date` validation
     * accepts far more than Y-m-d ("August 25, 2026" passes), and comparing
     * those as plain strings sorts alphabetically rather than chronologically
     * -- a non-ISO but validly-parsed date could compare greater than today
     * even when it is not, silently clamping both ends of the range to today.
     */
    private function clampEnd(string $end): string
    {
        $end = Carbon::parse($end)->toDateString();
        $today = today()->toDateString();

        return $end > $today ? $today : $end;
    }

    /**
     * The quick ranges on the Sales Report: Daily, Weekly, Monthly, Yearly.
     *
     * Each is "the current one, up to today" rather than a rolling window --
     * Weekly is Monday to today, Monthly the 1st to today, Yearly January 1 to
     * today -- because that is what "this week's sales" means to someone
     * closing it out, and because a calendar-aligned range is one they can
     * reproduce by hand from the date pickers. Anchored on
     * SalesHistory::reportableThrough() (today), never on the last row of a
     * table: the imported record stops at the handoff, and a window anchored
     * there would describe a period that ended weeks ago.
     */
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** @return array{0:string,1:string} [start, end] as Y-m-d */
    public static function periodRange(string $period): array
    {
        $today = Carbon::parse(SalesHistory::reportableThrough());

        $start = match ($period) {
            'weekly' => $today->copy()->startOfWeek(Carbon::MONDAY),
            'monthly' => $today->copy()->startOfMonth(),
            'yearly' => $today->copy()->startOfYear(),
            default => $today->copy(),
        };

        return [$start->toDateString(), $today->toDateString()];
    }

    /**
     * ATV (Average Transaction Value) and ATC (Average Transaction Count),
     * both scoped to POS transactions -- sales_history rows are imported
     * units with no discrete transaction to divide by, so there is nothing
     * for either figure to read there. ATV is per transaction; ATC is
     * transactions PER DAY across the whole selected period (not just days
     * that had one), so a mostly-quiet range reports an honest average
     * rather than one inflated by skipping its own zero days.
     *
     * Extracted as a static, pure function -- like periodRange() above --
     * so it can be unit tested without going through buildSalesReportData(),
     * which calls into MySQL-only aggregates (STRAIGHT_JOIN) the test suite's
     * sqlite connection cannot run.
     *
     * @return array{0: ?float, 1: float} [atv, atc]
     */
    public static function atvAtc(float $posTotal, int $totalTransactions, string $start, string $end): array
    {
        $atv = $totalTransactions > 0 ? round($posTotal / $totalTransactions, 2) : null;

        $periodDays = max(1, Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1);
        $atc = round($totalTransactions / $periodDays, 1);

        return [$atv, $atc];
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
        $data = $this->buildSalesReportData($request);

        AuditTrail::log('Viewed', "Generated Sales Report ({$data['start']} to {$data['end']})");

        return view('reports.sales', $data);
    }

    /**
     * Excel/PDF export of the sales report, honouring the same filters as
     * the page. Built from buildSalesReportData() -- the one place that
     * range/month resolution happens -- so an export can never disagree
     * with the page it was exported from about which range it covers.
     */
    public function exportSales(Request $request)
    {
        $request->validate(['format' => 'required|in:xlsx,pdf']);

        $data = $this->buildSalesReportData($request);
        $range = "{$data['start']}_to_{$data['end']}";

        // Carries the Category/Product filter into the download itself, so a
        // file named "sales-report-2026-09-01_to_2026-09-22.xlsx" opened a
        // week later doesn't have to be re-opened against the page to find
        // out it was actually just Baby Care. Slugified: it becomes part of
        // a filename, which a raw product name (spaces, punctuation) is not
        // safe to be.
        $scopeSuffix = $data['scopeLabel'] ? '-'.Str::slug($data['scopeLabel']) : '';

        if ($request->get('format') === 'xlsx') {
            AuditTrail::log('Viewed', 'Exported Sales Report as Excel ('.$data['start'].' to '.$data['end'].
                ($data['scopeLabel'] ? ", {$data['scopeLabel']}" : '').')');

            return Excel::download(new SalesReportExport($data), "sales-report-{$range}{$scopeSuffix}.xlsx");
        }

        AuditTrail::log('Viewed', 'Exported Sales Report as PDF ('.$data['start'].' to '.$data['end'].
            ($data['scopeLabel'] ? ", {$data['scopeLabel']}" : '').')');

        return Pdf::loadView('reports.pdf.sales', $data)->download("sales-report-{$range}{$scopeSuffix}.pdf");
    }

    /**
     * Everything the sales report page and its exports both need: the
     * validated/clamped range, the daily or monthly breakdown, and the two
     * ways the headline total is counted. Kept separate from AuditTrail::log()
     * and the view() call so the page logs "Generated" and an export logs
     * "Exported" without duplicating the range/query logic between them.
     */
    private function buildSalesReportData(Request $request): array
    {
        // The date inputs are type="date", but nothing stops a hand-edited or
        // bookmarked query string. Unvalidated, `?start_date=banana` reached
        // Carbon::parse() inside trendBetween() and came back as a 500
        // (InvalidFormatException) rather than a form error.
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'month' => 'nullable|date_format:Y-m',
            'period' => 'nullable|in:'.implode(',', self::PERIODS),
            'category_id' => 'nullable|integer|exists:categories,id',
            // A SKU, not an id: the filter field is a text input with a
            // <datalist> of every product (see reports/sales.blade.php),
            // since a <select> with 2,600+ options is unusable and this app
            // has no pick-one-item autocomplete component -- only the live-
            // list-filter kind (REMEDI.attachSuggest). The SKU is directly
            // usable as a datalist option's value; an id would need extra JS
            // to resolve a picked label back to one.
            'product_sku' => 'nullable|string|exists:products,sku',
            // A user id, not a name: rendered as a plain <select> (the staff
            // list is small, unlike the 2,600-product typeahead above) so the
            // value posted back is always one this exists() check can trust.
            'cashier_id' => 'nullable|integer|exists:users,id',
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

        // The quick-range buttons (Daily / Weekly / Monthly / Yearly). They are
        // plain links carrying only `period`, so a period can only arrive alone;
        // if a hand-edited URL carries it beside dates or a month, the control
        // the person actually set wins -- the same rule as month vs dates above.
        $period = $request->get('period');
        $quick = in_array($period, self::PERIODS, true)
            && ! $request->filled('start_date')
            && ! $request->filled('end_date')
            && ! $request->filled('month')
            ? $period
            : null;

        if ($quick) {
            $month = null;
            [$start, $end] = self::periodRange($quick);
        } elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
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

        // Category/Product filter. A product wins over a category if a hand-
        // edited URL carries both -- it is the more specific of the two, so
        // there's nothing left for the category to narrow further. Archived
        // products/categories are deliberately still selectable: this reads
        // HISTORY, and a product discontinued last month still has sales to
        // look back on.
        $scopeCategoryId = $request->filled('category_id') ? (int) $request->get('category_id') : null;
        $scopeProduct = $request->filled('product_sku')
            ? Product::withTrashed()->where('sku', $request->get('product_sku'))->first()
            : null;
        if ($scopeProduct) {
            $scopeCategoryId = null;
        }
        $scopeCategory = $scopeCategoryId ? Category::withTrashed()->find($scopeCategoryId) : null;
        $isScoped = (bool) ($scopeProduct || $scopeCategory);

        // Cashier -- a THIRD filter dimension, orthogonal to Category/Product
        // and deliberately kept separate from $isScoped: sales_history has no
        // cashier column at all (it predates this terminal, see "Two sales
        // tables"), so a cashier can only ever narrow the POS-only figures
        // this page already keeps separate from the merged Total Sales
        // identity -- $sales/$scopedItems, $totalTransactions, ATV/ATC, the
        // hourly chart and the printed transaction/line-item tables.
        // withTrashed(): a deactivated or archived cashier still rang up real
        // sales worth filtering to, same reasoning $scopeProduct/$scopeCategory
        // already apply.
        $scopeCashier = $request->filled('cashier_id')
            ? User::withTrashed()->find($request->get('cashier_id'))
            : null;

        // The one place this gets written down, so the page heading, the
        // PDF/print title, the Excel sheet name and the download filename
        // can't drift into four different descriptions of the same filter.
        $scopeParts = array_filter([
            $scopeProduct?->name ?? $scopeCategory?->name,
            $scopeCashier?->name,
        ]);
        $scopeLabel = $scopeParts ? implode(' — ', $scopeParts) : null;

        $categories = Category::orderBy('name')->get(['id', 'name']);

        // Lightweight columns only -- this feeds a <datalist> of every
        // product for the filter's typeahead, not a full product listing.
        $productOptions = Product::withTrashed()->orderBy('name')->get(['id', 'sku', 'name']);

        // Plain <select>, unlike the product field above -- there are dozens
        // of staff accounts at most, not thousands, so a typeahead would be
        // solving a problem that doesn't exist here. withTrashed(): an
        // archived/deactivated account still has real sales in range to
        // filter to.
        $cashiers = User::withTrashed()->orderBy('name')->get(['id', 'name']);

        if ($isScoped) {
            // Not trendBetween(): that aggregate is cached and has no
            // category/product dimension to key on. See
            // SalesHistory::scopedTrendBetween() for why an admin-chosen
            // filter doesn't need the same caching trendBetween() does.
            $trend = SalesHistory::scopedTrendBetween($start, $end, $scopeCategoryId, $scopeProduct?->sku);
        } else {
            // Day buckets for short ranges, month buckets for long ones -- see
            // SalesHistory::trendBetween().
            $trend = SalesHistory::trendBetween($start, $end);
        }

        $dailyBreakdown = $trend['rows'];
        $granularity = $trend['granularity'];

        $totalSales = $dailyBreakdown->sum('revenue');
        $totalUnits = $dailyBreakdown->sum('units');

        if ($isScoped) {
            // Every sale_items row for this product/category in range, each
            // carrying the sale and product it came from -- the "Where the
            // total comes from" table becomes a line-item list rather than a
            // transaction list when scoped, since a transaction can hold
            // OTHER products too and listing its FULL total here would
            // overstate what this product/category actually earned.
            $itemsQuery = SaleItem::with(['sale.user', 'product'])
                ->whereHas('sale', fn ($q) => $q->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)->where('payment_voided', false));

            if ($scopeProduct) {
                $itemsQuery->where('product_id', $scopeProduct->id);
            } else {
                $itemsQuery->whereHas('product', fn ($q) => $q->where('category_id', $scopeCategoryId));
            }

            // Fetched WITHOUT the cashier filter first -- $posTotal/$posByBucket
            // below feed the "Where the total comes from" identity
            // (historyTotal + posTotal = totalSales), which must stay whole
            // regardless of which cashier is picked, or the arithmetic on
            // screen would stop adding up. See the cashier comment above.
            $allScopedItems = $itemsQuery->get()->sortBy(fn ($item) => $item->sale->created_at)->values();

            $scopedItems = $scopeCashier
                ? $allScopedItems->filter(fn ($item) => $item->sale->user_id === $scopeCashier->id)->values()
                : $allScopedItems;

            // "Transactions" here means distinct sales that INCLUDED this
            // product/category, not line items -- a cart with two matching
            // lines is one transaction, not two.
            $totalTransactions = $scopedItems->pluck('sale_id')->unique()->count();

            // Whole-scope (cashier-blind) total, for the identity above.
            $posTotal = round((float) $allScopedItems->sum('subtotal'), 2);

            // The (possibly cashier-filtered) total of what's actually
            // LISTED below -- the "Line items" print table's own footer,
            // which must equal what it's printed under, not the whole-scope
            // figure above.
            $listedPosTotal = round((float) $scopedItems->sum('subtotal'), 2);

            $activeDays = $granularity === 'day' ? $dailyBreakdown->count() : $dailyBreakdown->pluck('key')->unique()->count();

            $posByBucket = $allScopedItems
                ->groupBy(fn ($item) => $granularity === 'day'
                    ? $item->sale->created_at->toDateString()
                    : $item->sale->created_at->format('Y-m'))
                ->map(fn ($group) => round((float) $group->sum('subtotal'), 2));

            $scopedItemsForPrint = $scopedItems->take(self::POS_PRINT_CAP);

            // ATV/ATC/Hourly are whole-TRANSACTION metrics (this till's
            // overall activity), and mixing them into a one-product slice
            // would read as "average value of a sale of just this item",
            // which is a different and much noisier number. Left unset --
            // the view hides those cards entirely while scoped, rather than
            // showing a figure that answers a question nobody asked.
            $sales = collect();
            $salesForPrint = collect();
            $atv = null;
            $atc = 0.0;
            $hourlyBreakdown = collect();
        } else {
            $scopedItems = collect();
            $scopedItemsForPrint = collect();

            // Trading days is a day count regardless of how the trend is bucketed.
            $activeDays = $granularity === 'day'
                ? $dailyBreakdown->count()
                : (int) DB::table('sales_history')
                    ->whereBetween('sale_date', [$start, $end])
                    ->distinct()
                    ->count('sale_date');

            // Live POS transactions in the same window, listed separately: they
            // are a different kind of record (transaction no., cashier) and only
            // exist for dates this install actually rang up. Voided sales are
            // excluded outright here (not just from the total) -- they
            // contributed ₱0 to revenue, so listing one under a total it
            // isn't part of would read as the report's own arithmetic being
            // wrong. The interactive Sales History list still shows a voided
            // row with its own badge; a printed report is a different case.
            $allSales = Sale::with('user')
                ->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', $end)
                ->where('payment_voided', false)
                ->orderBy('created_at')
                ->get();

            // $sales narrows to one cashier when the filter is set; $allSales
            // (whole-till, cashier-blind) stays intact below for $posTotal /
            // $posByBucket, which feed the "Where the total comes from"
            // identity -- that arithmetic can't be honestly narrowed to one
            // cashier, since sales_history (the other half of Total Sales)
            // has no cashier to filter by at all.
            $sales = $scopeCashier
                ? $allSales->where('user_id', $scopeCashier->id)->values()
                : $allSales;

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
            //
            // From $allSales, not $sales: this identity (and the bucket
            // breakdown below) describes the whole till regardless of which
            // cashier is filtered, for the same reason given above.
            $posTotal = round((float) $allSales->sum('total_amount'), 2);

            // The (possibly cashier-filtered) total of what's actually
            // LISTED below -- the printed transaction table's own footer.
            $listedPosTotal = round((float) $sales->sum('total_amount'), 2);

            // POS takings per bucket, keyed the same way trendBetween() keys its
            // rows (Y-m-d for a day-bucketed range, Y-m for a month-bucketed one).
            // The breakdown table uses it to show the imported and POS halves of
            // each row side by side, so the headline figure can be followed down
            // the page instead of having to be taken on trust. From $allSales
            // for the same reason $posTotal is, just above.
            $posByBucket = $allSales
                ->groupBy(fn ($sale) => $granularity === 'day'
                    ? $sale->created_at->toDateString()
                    : $sale->created_at->format('Y-m'))
                ->map(fn ($group) => round((float) $group->sum('total_amount'), 2));

            // Off $listedPosTotal, not $posTotal: when a cashier is picked,
            // ATV/ATC should answer "this cashier's average", not the whole
            // till's -- $totalTransactions is already narrowed the same way.
            [$atv, $atc] = self::atvAtc($listedPosTotal, $totalTransactions, $start, $end);

            // Hourly sales / transaction volume — POS-only, for the same reason
            // ATV/ATC are: sales_history has a DATE per row, never a time, so an
            // imported sale cannot be placed in an hour. This is honestly this
            // TERMINAL's own hourly pattern, not the whole four-year record's.
            $hourlyBreakdown = collect(range(0, 23))->map(function ($hour) use ($sales) {
                $inHour = $sales->filter(fn ($sale) => (int) $sale->created_at->format('G') === $hour);

                return (object) [
                    'hour' => $hour,
                    'label' => Carbon::createFromTime($hour)->format('g A'),
                    'transactions' => $inHour->count(),
                    'revenue' => round((float) $inHour->sum('total_amount'), 2),
                ];
            });
        }

        $historyTotal = round($totalSales - $posTotal, 2);

        return compact(
            'sales', 'start', 'end', 'totalSales', 'totalTransactions',
            'dailyBreakdown', 'months', 'month', 'totalUnits', 'activeDays',
            'dataStart', 'dataEnd', 'granularity', 'posTotal', 'historyTotal', 'posByBucket',
            'salesForPrint', 'atv', 'atc', 'hourlyBreakdown', 'listedPosTotal',
            'categories', 'productOptions', 'scopeCategory', 'scopeProduct', 'isScoped', 'scopeLabel', 'scopedItems', 'scopedItemsForPrint',
            'cashiers', 'scopeCashier'
        ) + ['period' => $quick];
    }

    public function inventory(Request $request)
    {
        $data = $this->buildInventoryReportData($request);

        $logMsg = 'Generated Inventory Report';
        if ($data['categoryId'] || $data['lowStockOnly'] || $data['expiredOnly']) {
            $logMsg .= ' (filtered)';
        }
        AuditTrail::log('Viewed', $logMsg);

        return view('reports.inventory', $data);
    }

    /** Excel/PDF export of the inventory report, same filters as the page. */
    public function exportInventory(Request $request)
    {
        $request->validate(['format' => 'required|in:xlsx,pdf']);

        $data = $this->buildInventoryReportData($request);
        $suffix = now()->toDateString();

        if ($request->get('format') === 'xlsx') {
            AuditTrail::log('Viewed', 'Exported Inventory Report as Excel');

            return Excel::download(new InventoryReportExport($data), "inventory-report-{$suffix}.xlsx");
        }

        AuditTrail::log('Viewed', 'Exported Inventory Report as PDF');

        // dompdf renders HTML-to-PDF, not a streamed table like PhpSpreadsheet --
        // the unfiltered catalogue is ~2,600 rows, and Chrome-print-to-PDF on the
        // page's own print copy already needed the row-styling rework documented
        // in reports/inventory.blade.php to stay under a few MB. Capped here the
        // same way POS_PRINT_CAP / SLOW_MOVING_LIST_CAP already cap other exports,
        // so a serverless request can't time out generating one PDF. The Excel
        // export above carries every row -- PhpSpreadsheet does not have this cost.
        $pdfData = $data;
        $pdfData['pdfTotalCount'] = $data['products']->count();
        $pdfData['products'] = $data['products']->take(self::INVENTORY_PDF_CAP);

        return Pdf::loadView('reports.pdf.inventory', $pdfData)->download("inventory-report-{$suffix}.pdf");
    }

    private function buildInventoryReportData(Request $request): array
    {
        $categoryId = $request->get('category_id');

        // ONE status filter, not two independent flags.
        //
        // These were two checkboxes, which meant both could be ticked -- and
        // that asks for the intersection of two sets this report deliberately
        // keeps disjoint. A product below its reorder level holding nothing but
        // expired units is excluded from low stock on purpose (see the long
        // note below, and Product::is_running_out): its problem is clearing,
        // not reordering, and the Expired filter beside it already says so. So
        // "low stock AND expired" asked for rows the two KPIs above the table
        // disagreed about, and on this catalogue it returned an empty table
        // while both KPIs read non-zero.
        //
        // `low_stock=1` / `expired=1` are still honoured so older bookmarks and
        // any hand-written URL keep working; `status` wins where both appear.
        $status = $request->get('status');

        if (! in_array($status, ['low_stock', 'expired'], true)) {
            $status = $request->boolean('low_stock') ? 'low_stock'
                : ($request->boolean('expired') ? 'expired' : null);
        }

        $lowStockOnly = $status === 'low_stock';
        $expiredOnly = $status === 'expired';

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

        return compact(
            'products', 'totalStockValue', 'lowStockCount', 'expiredCount',
            'stockValueByCategory', 'categories', 'categoryId', 'lowStockOnly', 'expiredOnly'
        );
    }

    public function analytics(Request $request)
    {
        $data = $this->buildAnalyticsReportData($request);

        AuditTrail::log('Viewed', "Generated Analytics Report ({$data['start']} to {$data['end']})");

        return view('reports.analytics', $data);
    }

    /** Excel/PDF export of the analytics report, same filters as the page. */
    public function exportAnalytics(Request $request)
    {
        $request->validate(['format' => 'required|in:xlsx,pdf']);

        $data = $this->buildAnalyticsReportData($request);
        $range = "{$data['start']}_to_{$data['end']}";

        if ($request->get('format') === 'xlsx') {
            AuditTrail::log('Viewed', "Exported Analytics Report as Excel ({$data['start']} to {$data['end']})");

            return Excel::download(new AnalyticsReportExport($data), "analytics-report-{$range}.xlsx");
        }

        AuditTrail::log('Viewed', "Exported Analytics Report as PDF ({$data['start']} to {$data['end']})");

        return Pdf::loadView('reports.pdf.analytics', $data)->download("analytics-report-{$range}.pdf");
    }

    private function buildAnalyticsReportData(Request $request): array
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
        // Raw query builder, so the soft-delete scope does NOT apply: archived
        // products and archived batches are filtered by hand. A product nobody
        // stocks any more is not "slow moving", it is gone from the catalogue.
        $slowAll = DB::table('products')
            ->whereNull('products.archived_at')
            ->leftJoin('product_batches', function ($join) {
                $join->on('product_batches.product_id', '=', 'products.id')
                    ->whereNull('product_batches.archived_at');
            })
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

        // Sales by category — merges sales_history with the till, same as
        // topProducts above, just grouped one level up.
        $salesByCategory = SalesHistory::salesByCategoryBetween($start, $end);

        // Sales by staff/cashier is POS-ONLY: sales_history is an imported
        // record of units sold, with no cashier column at all -- there was
        // nobody signed into anything before this terminal existed. So this
        // table only ever reflects what THIS till has rung up in the range,
        // same limitation the Hourly figures on the Sales Report carry, and
        // it is empty for any period entirely before the terminal went live.
        $salesByStaff = DB::table('sales')
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->whereDate('sales.created_at', '>=', $start)
            ->whereDate('sales.created_at', '<=', $end)
            ->where('sales.payment_voided', false)
            ->selectRaw('sales.user_id, MAX(users.name) AS name, COUNT(*) AS transactions, SUM(sales.total_amount) AS revenue')
            ->groupBy('sales.user_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => (object) [
                'user_id' => $r->user_id,
                'name' => $r->name,
                'transactions' => (int) $r->transactions,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        return compact(
            'topProducts', 'slowMoving', 'slowMovingCount', 'salesTrend', 'seasonalTrends',
            'months', 'month', 'start', 'end', 'dataStart', 'dataEnd', 'granularity',
            'salesByCategory', 'salesByStaff'
        );
    }
}
