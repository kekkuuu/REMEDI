<?php

namespace App\Http\Controllers;

use App\Models\InventoryReceipt;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Use the global request() helper instead of injecting it
        $user = request()->user();

        // ── Shell first, data second ──
        //
        // This route is the slowest in the app: ~5s with warm caches and 10-12s
        // cold, because it totals sales and stock across the whole catalogue.
        // Rendering it synchronously meant the browser sat on the LOGIN page
        // for that entire time, so signing in looked like a hang before any of
        // the app appeared.
        //
        // So the first hit returns only the shell -- no queries at all, which
        // is why this test sits above every one of them -- and the page fetches
        // its own body over AJAX. Move a query above this line and the shell
        // stops being instant, which is the only thing it is for.
        //
        // `full=1` is the escape hatch: <noscript> redirects to it, and the
        // loader's failure panel offers it. It renders the old way, in one
        // synchronous pass, so the dashboard is never unreachable without JS.
        $wantsBody = $request->wantsJson() || $request->ajax() || $request->boolean('full');

        if (! $wantsBody) {
            return view('dashboard.index', [
                'isAdmin' => (bool) ($user && $user->isAdmin()),

                // Set by AuthenticatedSessionController on the redirect out of
                // login, and gone by the next request. Only an arrival gets
                // the branded loading card; a revisit gets the skeleton alone.
                'justSignedIn' => (bool) session('remedi.just_signed_in'),
            ]);
        }

        // Shared stats (both admin and staff see these) — still ONE query for
        // all four numbers. Grouping by cashier rather than aggregating the
        // whole day lets the terminal total and the signed-in user's own share
        // come out of the same pass: the totals are the group sums, and "mine"
        // is the row for this user.
        //
        // The split exists because the dashboard used to show the terminal's
        // takings on every dashboard, including a staff member's — so a cashier
        // who had rung up nothing still saw the whole shop's transaction count
        // presented as their day. Sales are already per-user everywhere else
        // (SaleController scopes staff to their own rows), so the dashboard was
        // the odd one out.
        $todayByUser = Sale::whereDate('created_at', today())
            ->selectRaw('user_id, COALESCE(SUM(total_amount), 0) as total, COUNT(*) as cnt')
            ->groupBy('user_id')
            ->get();

        $todaySales = (float) $todayByUser->sum('total');
        $todayTransactions = (int) $todayByUser->sum('cnt');

        $mine = $todayByUser->firstWhere('user_id', $user->id);
        $myTodaySales = (float) ($mine->total ?? 0);
        $myTodayTransactions = (int) ($mine->cnt ?? 0);

        // Every stat below (low stock, expiring soon, expired, medicine
        // returns) used to run as four separate queries that each
        // re-fetched overlapping batch + product + category data from
        // scratch. We now load batches ONCE, with product+category
        // eager-loaded, and derive everything else from that single
        // collection in memory — no extra round trips.
        //
        // In-stock batches PLUS every returned one whatever its quantity —
        // the same widening InventoryController already does, and for the same
        // reason. A returned batch has been shipped back, so its quantity is 0
        // (markBatchReturned zeroes it), and loading `quantity > 0` alone made
        // the "Successfully Returned" figures on both return cards drop to zero
        // the moment a return actually completed. The card existed; its data
        // source was never widened to match, so it only ever worked while
        // returned batches happened to still carry stock.
        //
        // Everything derived below that means "on the shelf" therefore has to
        // say `quantity > 0` for itself — see $onShelf.
        $activeBatches = ProductBatch::with('product.category')
            ->where(fn ($w) => $w->where('quantity', '>', 0)->orWhereNotNull('returned_at'))
            ->get();

        // The stock-bearing subset. Used by every expiry/stock derivation, so
        // a zero-quantity returned batch can never be counted as sitting on the
        // shelf expiring.
        $onShelf = $activeBatches->where('quantity', '>', 0);

        $batchesByProduct = $onShelf->groupBy('product_id');

        // Lightweight product listing (no per-product batch query): each
        // product gets its slice of $activeBatches attached directly via
        // setRelation, so total_stock / is_low_stock / is_medicine etc.
        // read from memory instead of falling back to a query per product.
        $lowStockProducts = Product::all()
            ->each(fn ($p) => $p->setRelation('batches', $batchesByProduct->get($p->id, collect())))
            ->filter(fn ($p) => $p->is_running_out)
            ->values();

        // Chart-only slice: the 10 most critical items (stock furthest below
        // their own reorder line), so the chart stays readable even when
        // hundreds of products are flagged low-stock.
        $lowestStockChart = $lowStockProducts
            ->sortBy(fn ($p) => $p->total_stock - $p->reorder_level)
            ->take(10)
            ->values();

        $today = today();

        // 30 days, NOT 90.
        //
        // 90 is the lower bound of the supplier-return window, so using it
        // here meant a batch became "Expiring Soon" at the exact moment it
        // stopped being returnable — making the two look like one threshold.
        // The dashboards' expiring list is an action list ("pull these"), so
        // it uses the shorter horizon; the 90-day view still exists as the
        // Inventory page's "Expiring" filter.
        $expiringCutoff = today()->addDays(ProductBatch::EXPIRY_SOON_DAYS);

        // Both lists gate on is_expired, the same way is_expiring_soon,
        // expiryOverview below and the Inventory page's filters already do.
        //
        // They used to hand-roll the dates instead -- between($today, cutoff)
        // for expiring and lt($today) for expired -- and the two disagreed
        // about a batch whose expiry_date IS today. between() includes today,
        // lt() excludes it, so those batches landed in "Expiring Soon" and
        // nowhere else, while is_expired (and therefore the bell, the Inventory
        // "Expired" filter and every row badge) already called them expired.
        // The dashboard reported 77 expired against the bell's 79.
        //
        // That is not just an inconsistent tally. Expiring Soon is the "pull
        // these" action list, so stock the app considers unsellable today --
        // ProductBatch::is_expired treats the expiry date itself as expired,
        // deliberately: we do not sell on it -- was being presented as still
        // having shelf life.
        $expiringSoonBatches = $onShelf
            ->filter(fn ($b) => $b->expiry_date && ! $b->is_expired && $b->expiry_date->lte($expiringCutoff))
            ->sortBy('expiry_date')
            ->values();

        $expiredBatches = $onShelf
            ->filter(fn ($b) => $b->is_expired)
            ->values();

        // Medicine return-window tracking (see ProductBatch::getReturnStatusAttribute):
        // Successfully Returned / Need to Return (pending) / Fail to Return (missed window).
        $medicineBatches = $activeBatches->filter(fn ($b) => $b->product && $b->product->is_medicine);

        // One tallying pass rather than three separate filters: each of
        // is_returned / needs_return / failed_return resolves the same
        // return_status underneath, so filtering three times did the work
        // three times over.
        $statusTally = $medicineBatches->countBy(fn ($b) => $b->return_status ?? 'not_due');

        $returnStats = [
            'returned' => $statusTally->get('Successfully Returned', 0),
            'need_to_return' => $statusTally->get('Need to Return', 0),
            'fail_to_return' => $statusTally->get('Fail to Return', 0),
        ];

        // Every batch that can actually be sent back, medicine and non-pharma
        // alike — the same definition AlertService's `need_to_return` kind and
        // the Inventory filter use, and the one that gates the "Mark Returned"
        // button (ProductBatch::is_returnable).
        //
        // The Alerts panel and its KPI showed $returnStats['need_to_return']
        // instead, which is medicine only: 26 under a label reading "batches
        // can still go back to the supplier", while the bell said 78 and the
        // filter it linked to listed 78. The 52 non-pharma batches it omitted
        // are returnable and have a working button on their row.
        //
        // $returnStats stays as it is — the Medicine Returns card is a
        // deliberate per-category breakdown and wants the narrow figure.
        $returnableCount = $onShelf->filter(fn ($b) => $b->is_returnable)->count();

        // Specific batches currently inside the return window, soonest-expiring
        // first, so admins know exactly which ones to pull — same idea as the
        // Expiring Soon list.
        $needToReturnBatches = $medicineBatches
            ->filter(fn ($b) => $b->needs_return)
            ->sortBy('expiry_date')
            ->values();

        // Non-pharma return tracking — a SEPARATE visualization from the
        // pharma 90-120 day supplier window above. Every category other
        // than Medicine/Pharmaceutical has no formal supplier return
        // window, so it's judged purely on default expiry date: already
        // expired (past due, should already have been pulled), or inside
        // $b->product->non_pharma_return_window_days of expiring (needs to
        // be pulled soon) — 30 days for Baby Care / Vitamins & Supplements,
        // 10 for everything else. See Product::getNeedsReturnAttribute().
        $nonPharmaBatches = $activeBatches->filter(fn ($b) => $b->product && ! $b->product->is_medicine && $b->expiry_date);

        // Three buckets, mutually exclusive, returned first -- the same shape
        // and the same precedence as the medicine tally above.
        //
        // "Successfully Returned" used to be missing here entirely: it existed
        // only on the medicine card, which filters to is_medicine, so marking a
        // NON-medicine batch returned (a Baby Care line, say) showed up in the
        // bell and in the Inventory "Returned" filter while both dashboard
        // cards reported nothing. The same batch was also still being counted
        // as "Expired" by this card, so one ring double-counted it.
        $nonPharmaReturnStats = [
            'returned' => $nonPharmaBatches->filter(fn ($b) => $b->returned_at)->count(),
            'expired' => $nonPharmaBatches->filter(fn ($b) => ! $b->returned_at && $b->is_expired)->count(),
            'need_to_return' => $nonPharmaBatches->filter(fn ($b) => ! $b->returned_at && ! $b->is_expired
                && $b->expiry_date->diffInDays(now(), true) <= $b->product->non_pharma_return_window_days)->count(),
        ];

        // "N batches inside the return window" under the ring — so it must
        // exclude the ones already sent back, or the footer contradicts the
        // Successfully Returned count directly above it.
        $nonPharmaNeedToReturnBatches = $nonPharmaBatches
            ->filter(fn ($b) => ! $b->returned_at
                && ($b->is_expired
                    || $b->expiry_date->diffInDays(now(), true) <= $b->product->non_pharma_return_window_days))
            ->sortBy('expiry_date')
            ->values();

        // Categorized inventory breakdown — stock split out by product
        // category, for the "Inventory by Category" chart on both the
        // admin and staff dashboards. Derived from the $activeBatches
        // collection already loaded above (product+category eager-loaded),
        // so this costs zero extra queries.
        $categoryBreakdown = $onShelf
            ->filter(fn ($b) => $b->product && $b->product->category)
            ->groupBy(fn ($b) => $b->product->category->name)
            ->map(fn ($batches, $categoryName) => [
                'name' => $categoryName,
                // Carried so the legend rows can link to Inventory filtered to
                // this category. Grouping is by name (that is what the chart
                // labels), and the id is not derivable from it — read off the
                // first batch's already-eager-loaded category, so still no
                // extra query.
                'id' => $batches->first()->product->category->id,
                'stock' => (int) $batches->sum('quantity'),
                'products' => $batches->pluck('product_id')->unique()->count(),
            ])
            ->sortByDesc('stock')
            ->values();

        // ── Panels added for the redesigned dashboard ──

        // Sales deltas. Only computed where a real comparison exists: the POS
        // records every checkout with a timestamp, so today-vs-yesterday and
        // this-week-vs-last-week are honest. The stock counters below (low
        // stock, expiring, expired, returns) deliberately get NO delta —
        // nothing snapshots them daily, so "vs yesterday" there would be an
        // invented number.
        $yesterdayTotal = (float) Sale::whereDate('created_at', today()->subDay())->sum('total_amount');
        $last7 = (float) Sale::where('created_at', '>=', today()->subDays(6)->startOfDay())->sum('total_amount');
        $prev7 = (float) Sale::whereBetween('created_at', [
            today()->subDays(13)->startOfDay(),
            today()->subDays(7)->endOfDay(),
        ])->sum('total_amount');

        $pctChange = static function (float $now, float $before): ?float {
            if ($before <= 0.0) {
                return null;   // no baseline — a percentage would be meaningless
            }

            return round((($now - $before) / $before) * 100, 1);
        };

        $hour = (int) now()->format('G');

        $data = [
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            },
            'salesTodayDelta' => $pctChange($todaySales, $yesterdayTotal),
            'last7Sales' => $last7,
            'last7Delta' => $pctChange($last7, $prev7),

            // Recent POS checkouts for the transactions panel. 15, not 6: the
            // panel now scrolls inside a capped box (see .dash-lower-main
            // .list-scroll), so more rows cost no page height and the panel
            // stops being a six-row teaser.
            'recentSales' => Sale::withCount('items')->latest()->take(15)->get(),

            // Sales Summary: quarterly revenue for the latest year that has
            // data, from sales_history (the POS table holds only this
            // terminal's handful of checkouts).
            'quarterSummary' => SalesHistory::quarterlyRevenue(),
        ];

        // Expiry Overview: every in-stock batch that has an expiry date, split
        // into the three horizons the app already works in. Derived from
        // $activeBatches, so no extra query.
        $datedBatches = $onShelf->filter(fn ($b) => $b->expiry_date);
        $soonCutoff = today()->addDays(ProductBatch::EXPIRY_SOON_DAYS);
        $midCutoff = today()->addDays(60);

        $data['expiryOverview'] = [
            'expired' => $datedBatches->filter(fn ($b) => $b->is_expired)->count(),
            'soon' => $datedBatches->filter(fn ($b) => ! $b->is_expired && $b->expiry_date <= $soonCutoff)->count(),
            'mid' => $datedBatches->filter(fn ($b) => ! $b->is_expired
                && $b->expiry_date > $soonCutoff && $b->expiry_date <= $midCutoff)->count(),
            'good' => $datedBatches->filter(fn ($b) => ! $b->is_expired && $b->expiry_date > $midCutoff)->count(),
        ];

        $data += compact('returnableCount', 'todaySales', 'todayTransactions', 'myTodaySales', 'myTodayTransactions', 'lowStockProducts', 'lowestStockChart', 'expiringSoonBatches', 'expiredBatches', 'returnStats', 'needToReturnBatches', 'nonPharmaReturnStats', 'nonPharmaNeedToReturnBatches', 'categoryBreakdown');

        // The full sales/inventory dashboard (monthly sales chart, high/low
        // demand, lowest stock vs. reorder level, expiring soon) is shared
        // by admin AND staff — both roles work the floor and need the same
        // at-a-glance picture. Only strictly admin-only figures (user counts,
        // etc.) stay behind the isAdmin() check below.
        $data['totalProducts'] = Product::count();

        // High/Low Demand Product — read from sales_history (the imported
        // sales records), NOT from sale_items. The POS tables only hold the
        // handful of transactions rung up on this install, so ranking demand
        // off them showed a near-empty chart while years of real sales sat
        // unused in sales_history. Window is anchored to the newest row in
        // that table, not to today. Cached; see SalesHistory.
        $demand = SalesHistory::recentDemand(30);

        $data['topProducts'] = $demand['top'];
        $data['lowDemandProducts'] = $demand['low'];

        // Until the POS has enough real sales, fall back to historical
        // receiving volume (imported from the supplier transaction
        // history) as a demand proxy so these cards aren't empty.
        $data['demandFromHistory'] = false;

        if ($data['topProducts']->isEmpty() && $data['lowDemandProducts']->isEmpty()) {
            $data['demandFromHistory'] = true;

            $receiptTotals = InventoryReceipt::selectRaw('product_sku, SUM(qty) as total_qty')
                ->groupBy('product_sku')
                ->pluck('total_qty', 'product_sku');

            $productsBySku = Product::whereIn('sku', $receiptTotals->keys())->get()->keyBy('sku');

            $ranked = $receiptTotals
                ->map(fn ($qty, $sku) => isset($productsBySku[$sku])
                    ? (object) ['product' => $productsBySku[$sku], 'total_qty' => $qty]
                    : null)
                ->filter()
                ->values();

            $data['topProducts'] = $ranked->sortByDesc('total_qty')->take(5)->values();
            $data['lowDemandProducts'] = $ranked->sortBy('total_qty')->take(3)->values();
        }

        // Total sales per month, full history, from sales_history.
        //
        // This used to sum the `sales` table over a hardcoded 3-month window.
        // That table only holds live POS checkouts, so the chart showed a
        // single populated month plus two empty bars, while the imported
        // records (30+ months of them) were never plotted. Both the wrong
        // source and the too-narrow window are fixed here.
        $data['monthlySales'] = SalesHistory::monthlyRevenue();

        if ($user && $user->isAdmin()) { // Added a quick null-check for safety
            // Extra admin-only stats (not used by the shared dashboard
            // partial today, but kept available for admin-only views).
            $data['totalUsers'] = User::count();

            // Daily sales for the last 14 days (used elsewhere, kept for compatibility)
            $dailySalesRaw = Sale::selectRaw('DATE(created_at) as sale_date, SUM(total_amount) as total')
                ->where('created_at', '>=', now()->subDays(13)->startOfDay())
                ->groupBy('sale_date')
                ->pluck('total', 'sale_date');

            $data['dailySales'] = collect(range(13, 0))->mapWithKeys(function ($daysAgo) use ($dailySalesRaw) {
                $date = now()->subDays($daysAgo)->toDateString();

                return [$date => (float) ($dailySalesRaw[$date] ?? 0)];
            });

            // Derived from the 14-day series above instead of a separate
            // sum() query — the last 7 days of it is exactly weekSales.
            $data['weekSales'] = $data['dailySales']->slice(-7)->sum();

            // Seasonal trends (avg. revenue per calendar month, all-time),
            // from sales_history for the same reason as the charts above --
            // the POS table has too few months to show a season at all.
            // Derived in PHP from the already-cached monthly series, so it
            // adds no query of its own.
            $data['seasonalTrends'] = SalesHistory::seasonalTrends();

            return $this->dashboardResponse($request, 'admin', $data);
        }

        return $this->dashboardResponse($request, 'staff', $data);
    }

    /**
     * Answer with the dashboard body in whichever shape the caller asked for.
     *
     * Two shapes, same data and the same Blade partial behind both, so the
     * AJAX page and the ?full=1 fallback can never drift into showing
     * different dashboards:
     *
     *   - `?full=1`  -> the whole page, body rendered inline in the layout.
     *   - AJAX       -> {html} for the shell to inject, matching the
     *                   ['html' => ...] shape the list controllers already use.
     */
    private function dashboardResponse(Request $request, string $role, array $data)
    {
        if ($request->boolean('full')) {
            return view($role.'.dashboard', $data);
        }

        return response()->json([
            'html' => view($role.'._dashboard-body', $data)->render(),
        ]);
    }
}
