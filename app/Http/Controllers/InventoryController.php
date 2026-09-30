<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
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

        if (($search = $this->searchParam($request)) !== null) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            $like = $this->likeTerm($search);
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

        // A string or 'all': `?filter[]=x` is an array, which the view then
        // echoes into its filter-tab links and dies on (a 500).
        $filter = is_string($request->get('filter')) ? $request->get('filter') : 'all';

        // Expiry window (2026-09-30, inventory monitoring): only products with
        // stock on the shelf whose expiry date falls inside it. The month
        // picker on the page just fills both dates. Validated strictly -- the
        // date inputs emit Y-m-d, and a value that silently failed to parse
        // would show the whole list under a filter that looks applied -- and
        // a reversed pair is put in order, the same rule the sales list keeps.
        $request->validate([
            'expiry_from' => 'nullable|date_format:Y-m-d',
            'expiry_to' => 'nullable|date_format:Y-m-d',
        ]);
        $expiryFrom = $request->filled('expiry_from') ? (string) $request->input('expiry_from') : null;
        $expiryTo = $request->filled('expiry_to') ? (string) $request->input('expiry_to') : null;
        if ($expiryFrom && $expiryTo && $expiryFrom > $expiryTo) {
            [$expiryFrom, $expiryTo] = [$expiryTo, $expiryFrom];
        }
        $hasExpiryWindow = $expiryFrom !== null || $expiryTo !== null;

        // A batch with units on the shelf whose expiry date sits in the window.
        $inExpiryWindow = function ($b) use ($expiryFrom, $expiryTo): bool {
            if ($b->quantity <= 0 || ! $b->expiry_date) {
                return false;
            }
            $date = $b->expiry_date->toDateString();

            return ($expiryFrom === null || $date >= $expiryFrom) && ($expiryTo === null || $date <= $expiryTo);
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
        if ($hasExpiryWindow) {
            // The window is its own superset, and may reach past 120 days.
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
        }

        $products = $query->orderBy('name')->get();

        // Point each batch back at the product it was loaded under. Without
        // this, ProductBatch::$return_days and $is_returnable both reach for
        // $this->product to decide which return window applies, and lazy-load
        // one query per batch while rendering the rows.
        $products->each(fn ($p) => $p->batches->each(fn ($b) => $b->setRelation('product', $p)));

        if ($filter === 'low_stock') {
            // is_running_out, not is_low_stock: a shelf holding nothing but
            // expired units is an Expired problem, and the tab beside this
            // one already lists it. See Product::is_running_out.
            $products = $products->filter(fn ($p) => $p->is_running_out);
        } elseif ($filter === 'out_of_stock') {
            // Nothing on the shelf at all. A subset of Low Stock, which still
            // counts zero as low everywhere (see Product::is_running_out);
            // this tab is the "refill these first" cut of it.
            $products = $products->filter(fn ($p) => $p->total_stock <= 0);
        } elseif ($filter === 'expiring' && $hasExpiryWindow) {
            // Chosen dates replace the default horizon: not yet expired, and
            // expiring inside the window.
            $products = $products->filter(fn ($p) => $p->batches->contains(
                fn ($b) => $inExpiryWindow($b) && ! $b->is_expired
            ));
        } elseif ($filter === 'expired' && $hasExpiryWindow) {
            // Stock that expired inside the window, e.g. "what expired in August".
            $products = $products->filter(fn ($p) => $p->batches->contains(
                fn ($b) => $inExpiryWindow($b) && $b->is_expired
            ));
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
            // tab itself still defaults to the 90-day view.
            $products = $products->filter(fn ($p) => $p->batches->contains(
                fn ($b) => $b->quantity > 0 && ! $b->is_expired && $b->days_to_expiry <= $expiringDays
            ));
        } elseif ($filter === 'expired') {
            $products = $products->filter(fn ($p) => $p->batches->contains(fn ($b) => $b->quantity > 0 && $b->is_expired));
        } elseif ($filter === 'need_to_return') {
            $products = $products->filter(fn ($p) => $p->needs_return);
        } elseif ($filter === 'fail_to_return') {
            $products = $products->filter(fn ($p) => $p->failed_return);
        } elseif ($filter === 'returned') {
            // Completed supplier returns. No stock condition — a returned
            // batch has normally been shipped back, so its quantity is 0.
            $products = $products->filter(fn ($p) => $p->has_returned_batches);
        }

        // Every other tab, with a window: the tab's own rule AND stock expiring
        // in the window (the SQL above already narrowed to this superset).
        if ($hasExpiryWindow && ! in_array($filter, ['expiring', 'expired'], true)) {
            $products = $products->filter(fn ($p) => $p->batches->contains($inExpiryWindow));
        }

        // Order each filtered view by the thing it is about, so the row that
        // needs acting on first is the one you see first. Sorting happens here
        // rather than in SQL because every one of these keys is a computed
        // accessor over the batches, not a column.
        if ($hasExpiryWindow && $filter !== 'low_stock') {
            // Earliest expiry inside the window first -- the date the person
            // asked about, so the list reads as a calendar of that window.
            $products = $products->sortBy(fn ($p) => $p->batches->filter($inExpiryWindow)
                ->min(fn ($b) => $b->expiry_date->toDateString()) ?? '9999-12-31');
        } elseif ($filter === 'low_stock') {
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
            $products = $products->sortBy(function ($p) use ($expiringDays) {
                return $p->batches
                    ->filter(fn ($b) => $b->quantity > 0 && ! $b->is_expired && $b->days_to_expiry <= $expiringDays)
                    ->min('days_to_expiry') ?? PHP_INT_MAX;
            });
        } elseif ($filter === 'expired') {
            // Longest expired first -- that stock has been sitting there most
            // dangerously and is furthest past any return window.
            $products = $products->sortBy(function ($p) {
                return $p->batches
                    ->filter(fn ($b) => $b->quantity > 0 && $b->is_expired)
                    ->min('days_to_expiry') ?? PHP_INT_MAX;
            });
        }

        $products = $products->values();

        $perPage = 10;
        // Clamped to a page that can exist. (int) of a 20-digit ?page= is
        // PHP_INT_MAX, and forPage()'s ($page - 1) * $perPage then overflows to
        // a float that array_slice() rejects -- a 500. Past the end is simply
        // the last page, which is also friendlier than an empty table.
        $lastPage = max(1, (int) ceil($products->count() / $perPage));
        $page = is_numeric($request->get('page')) ? (int) $request->get('page') : 1;
        $page = min(max(1, $page), $lastPage);

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
            'expiryFrom' => $expiryFrom,
            'expiryTo' => $expiryTo,
            'categoryId' => $categoryId,
            'categoryName' => $category?->name,
        ]);
    }
}
