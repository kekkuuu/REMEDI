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
        if (in_array($filter, ['expiring', 'expired', 'need_to_return', 'fail_to_return'], true)) {
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
        ]);
    }
}
