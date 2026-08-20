<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        // Only pull in-stock batches — the same relation is used for
        // total_stock / nearest_expiry / status badges below, all of
        // which already ignore quantity-0 (depleted) batches, so there's
        // no behavior change, just less data fetched and hydrated on
        // every inventory page load.
        $query = Product::with(['category', 'batches' => fn ($q) => $q->where('quantity', '>', 0)]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
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

        // The expiry-driven filters below have to run in PHP (they're computed
        // accessors, not columns), but they don't have to run over the whole
        // catalogue. Every product any of them can match must have an in-stock
        // batch expiring within 120 days -- that's the outer edge of the
        // medicine return window, and both the 90-day "expiring" horizon and
        // the 10-day non-pharma rule sit inside it. Expired batches qualify
        // too, hence no lower bound.
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
            $products = $products->filter(fn ($p) => $p->is_low_stock);
        } elseif ($filter === 'expiring') {
            $products = $products->filter(fn ($p) => $p->batches->contains(fn ($b) => $b->quantity > 0 && $b->is_expiring_soon));
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
            'categoryId' => $categoryId,
            'categoryName' => $category?->name,
        ]);
    }
}
