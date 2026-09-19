<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        // In-stock batches, PLUS every batch already returned to the supplier
        // whatever its quantity.
        //
        // The stock condition alone is what the accessors want — total_stock,
        // nearest_expiry and the status badges all ignore depleted batches
        // anyway. But a returned batch has normally been shipped back, so its
        // quantity is 0, and loading only `quantity > 0` meant
        // Product::has_returned_batches could never see it: the "Returned"
        // filter came back empty and the row lost its Returned badge — the
        // record of the return disappeared at exactly the moment the return
        // completed. The extra rows are few (returns are rare) and carry no
        // stock, so nothing they join into changes.
        $query = Product::with(['category', 'batches' => fn ($q) => $q->where(
            fn ($w) => $w->where('quantity', '>', 0)->orWhereNotNull('returned_at')
        )]);

        if ($request->filled('search')) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            $like = $this->likeTerm($request->search);
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        }

        // Category filter — pushed down to the database (not filtered in
        // PHP afterwards like the status filters below have to be, since
        // this one maps directly to a column). This is what powers the
        // category sub-buttons under "Inventory" in the sidebar.
        $categoryId = $request->filled('category_id') ? (int) $request->get('category_id') : null;

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $filter = $request->get('filter', 'all');

        // An expiry window -- a month or a from/to pair -- that narrows the
        // list to products holding stock that expires inside it. Offered on the
        // three tabs where "when" is the question (All, Expiring Soon, Expired);
        // the other tabs are about stock levels and returns, where a date range
        // would mean nothing, so they ignore it.
        [$expiryFrom, $expiryTo, $expiryMonth] = in_array($filter, self::EXPIRY_RANGE_FILTERS, true)
            ? $this->expiryRange($request)
            : [null, null, null];
        $hasExpiryRange = $expiryFrom !== null || $expiryTo !== null;

        // Is this batch's expiry date inside the window? Open-ended on either
        // side (only a from, or only a to) is a legitimate window.
        $inExpiryRange = function ($b) use ($expiryFrom, $expiryTo) {
            if (! $b->expiry_date) {
                return false;
            }

            $d = $b->expiry_date->toDateString();

            return ($expiryFrom === null || $d >= $expiryFrom) && ($expiryTo === null || $d <= $expiryTo);
        };

        // Expiry horizon for the `expiring` filter. Only the two the app
        // actually means are accepted — the dashboards' 30-day action list and
        // this page's 90-day planning view — so an arbitrary ?days= cannot
        // invent a third definition of "expiring soon".
        $expiringDays = (int) $request->get('days') === ProductBatch::EXPIRY_SOON_DAYS
            ? ProductBatch::EXPIRY_SOON_DAYS
            : ProductBatch::EXPIRY_WATCH_DAYS;

        // The expiry-driven filters below have to run in PHP (they're computed
        // accessors, not columns), but they don't have to run over the whole
        // catalogue. Every product any of them can match must have an in-stock
        // batch expiring within 120 days -- that's the outer edge of the
        // medicine return window, and both the 90-day "expiring" horizon and
        // the non-pharma rule (10 days flat, 30 for Baby Care / Vitamins &
        // Supplements) sit inside it. Expired batches qualify too, hence no
        // lower bound.
        //
        // So narrow to that superset in SQL first and let the accessors decide
        // from there. Measured on this catalogue: the "Need to Return" tab went
        // from scanning 2,637 products (476ms) to a few hundred, with the same
        // 77 results.
        //
        // With an expiry window the superset is the window itself instead: a
        // batch expiring in March next year is a legitimate answer to "what
        // expires in March", and lies outside the 120-day cut above. Expired
        // batches are covered too -- a window in the past is simply a window.
        if ($hasExpiryRange) {
            $query->whereHas('batches', fn ($q) => $q
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->when($expiryFrom, fn ($w) => $w->whereDate('expiry_date', '>=', $expiryFrom))
                ->when($expiryTo, fn ($w) => $w->whereDate('expiry_date', '<=', $expiryTo)));
        } elseif (in_array($filter, ['expiring', 'expired', 'need_to_return', 'fail_to_return'], true)) {
            $query->whereHas('batches', fn ($q) => $q
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', today()->addDays(120)));
        } elseif ($filter === 'out_of_stock') {
            // Nothing on the shelf at all: no batch holding a unit. Pushed into
            // SQL so the tab does not hydrate the whole catalogue to find them.
            $query->whereDoesntHave('batches', fn ($q) => $q->where('quantity', '>', 0));
        }

        $products = $query->orderBy('name')->get();

        // Point each batch back at the product it was loaded under. Without
        // this, ProductBatch::$return_days and $is_returnable both reach for
        // $this->product to decide which return window applies, and lazy-load
        // one query per batch while rendering the rows.
        $products->each(fn ($p) => $p->batches->each(fn ($b) => $b->setRelation('product', $p)));

        // The batch predicates the expiry tabs filter AND sort by, written once.
        // With a window they answer "expires inside it"; without one, the
        // original meaning (the 90/30-day horizon, or simply expired).
        $expiringBatch = fn ($b) => $b->quantity > 0 && ! $b->is_expired
            && ($hasExpiryRange ? $inExpiryRange($b) : $b->days_to_expiry <= $expiringDays);
        $expiredBatch = fn ($b) => $b->quantity > 0 && $b->is_expired
            && (! $hasExpiryRange || $inExpiryRange($b));

        if ($filter === 'low_stock') {
            // is_running_out, not is_low_stock: a shelf holding nothing but
            // expired units is an Expired problem, and the tab beside this
            // one already lists it. See Product::is_running_out.
            $products = $products->filter(fn ($p) => $p->is_running_out);
        } elseif ($filter === 'out_of_stock') {
            // total_stock, the physical shelf -- the same "nothing here" the
            // report's Out of Stock badge and the bell's "Out of stock" card
            // use. A shelf of only expired units is not out of stock, it is an
            // Expired problem, and that tab lists it.
            $products = $products->filter(fn ($p) => $p->total_stock <= 0);
        } elseif ($filter === 'expiring') {
            // The horizon is a parameter, because two different ones are in
            // legitimate use and they were silently disagreeing.
            //
            // is_expiring_soon is 90 days -- the Inventory tab's planning view,
            // deliberately wider than the dashboards' 30-day "pull these" list
            // (see EXPIRY_SOON_DAYS). But the bell's alert LINKED here, so a row
            // reading "28 batches expire within 30 days" opened a list of 85
            // products across 9 pages. The count promised and the list delivered
            // were different questions.
            //
            // The alert now links with days=30 and lands on exactly its 28; the
            // tab itself still defaults to the 90-day view. An explicit expiry
            // window replaces the horizon altogether.
            $products = $products->filter(fn ($p) => $p->batches->contains($expiringBatch));
        } elseif ($filter === 'expired') {
            $products = $products->filter(fn ($p) => $p->batches->contains($expiredBatch));
        } elseif ($filter === 'need_to_return') {
            $products = $products->filter(fn ($p) => $p->needs_return);
        } elseif ($filter === 'fail_to_return') {
            $products = $products->filter(fn ($p) => $p->failed_return);
        } elseif ($filter === 'returned') {
            // Completed supplier returns. No stock condition — a returned
            // batch has normally been shipped back, so its quantity is 0.
            $products = $products->filter(fn ($p) => $p->has_returned_batches);
        } elseif ($hasExpiryRange) {
            // "All" with a window: products holding stock that expires in it,
            // whatever its status.
            $products = $products->filter(fn ($p) => $p->batches->contains(
                fn ($b) => $b->quantity > 0 && $inExpiryRange($b)
            ));
        }

        // Order each filtered view by the thing it is about, so the row that
        // needs acting on first is the one you see first. Sorting happens here
        // rather than in SQL because every one of these keys is a computed
        // accessor over the batches, not a column.
        if ($filter === 'low_stock') {
            // Emptiest shelf first: the products nearest to being unsellable
            // are the ones to reorder. Sorted on sellable_stock, which is what
            // is_low_stock itself compares against -- total_stock would put a
            // product with 300 expired units above one with 2 good ones.
            // Tie-broken on total_stock because that is the column the table
            // actually shows: without it two products with nothing sellable
            // could appear in any order, one reading 0 and the next 300, and
            // the visible Stock column would look unsorted.
            $products = $products->sortBy(fn ($p) => [$p->sellable_stock, $p->total_stock]);
        } elseif ($filter === 'expiring') {
            // Soonest expiry first. Keyed on the earliest still-sellable batch,
            // because that is the date the row is warning about; a product
            // whose nearest batch expires next week outranks one due in 80
            // days even if the second has more batches expiring overall.
            $products = $products->sortBy(fn ($p) => $p->batches->filter($expiringBatch)->min('days_to_expiry') ?? PHP_INT_MAX);
        } elseif ($filter === 'expired') {
            // Longest expired first -- that stock has been sitting there most
            // dangerously and is furthest past any return window.
            $products = $products->sortBy(fn ($p) => $p->batches->filter($expiredBatch)->min('days_to_expiry') ?? PHP_INT_MAX);
        } elseif ($hasExpiryRange && $filter === 'all') {
            $products = $products->sortBy(fn ($p) => $p->batches
                ->filter(fn ($b) => $b->quantity > 0 && $inExpiryRange($b))
                ->min('days_to_expiry') ?? PHP_INT_MAX);
        }

        $products = $products->values();

        $page = (int) $request->get('page', 1);
        $perPage = 10;

        $paginated = new LengthAwarePaginator(
            $products->forPage($page, $perPage)->values(),
            $products->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('inventory._rows', ['products' => $paginated])->render(),
                'pagination' => (string) $paginated->links(),
            ]);
        }

        $category = $categoryId ? Category::find($categoryId) : null;

        return view('inventory.index', [
            'products' => $paginated,
            'filter' => $filter,
            'expiringDays' => $expiringDays,
            'categoryId' => $categoryId,
            'categoryName' => $category?->name,
            // The window as actually applied -- the view echoes THIS, not the
            // request, so a reversed or unparseable pair shows what was queried.
            'expiryFrom' => $expiryFrom,
            'expiryTo' => $expiryTo,
            'expiryMonth' => $expiryMonth,
            'hasExpiryRange' => $hasExpiryRange,
        ]);
    }

    /** The tabs an expiry window applies to. */
    private const EXPIRY_RANGE_FILTERS = ['all', 'expiring', 'expired'];

    /**
     * Resolve `expiry_from` / `expiry_to` / `expiry_month` into ONE window.
     *
     * A from/to pair wins over a month, the same rule the Sales Report uses
     * (the form clears whichever control was not used, and this covers a
     * hand-edited URL carrying both). A reversed pair is put the right way
     * round rather than answering "nothing expires then". Unlike the sales
     * report this does NOT clamp to today -- the whole point is to look
     * FORWARD at what will expire.
     *
     * Unparseable input is dropped rather than raising: this feeds a live
     * AJAX list, where a 422 would surface as a broken table. The page echoes
     * the window that was actually applied, so a dropped value is visible as
     * an empty box, not a silently different filter.
     *
     * @return array{0:?string,1:?string,2:?string} [from, to, month] as Y-m-d / Y-m
     */
    private function expiryRange(Request $request): array
    {
        $parse = function ($value): ?string {
            if (blank($value) || ! is_string($value)) {
                return null;
            }

            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($request->get('expiry_from'));
        $to = $parse($request->get('expiry_to'));
        $month = null;

        if ($from === null && $to === null) {
            $raw = $request->get('expiry_month');

            if (is_string($raw) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
                $m = Carbon::createFromFormat('Y-m-d', $raw.'-01');
                $from = $m->copy()->startOfMonth()->toDateString();
                $to = $m->copy()->endOfMonth()->toDateString();
                $month = $raw;
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, $month];
    }
}
