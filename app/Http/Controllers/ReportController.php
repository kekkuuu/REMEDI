<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** How many rows the analytics top/bottom lists show. */
    private const TOP_N = 10;

    /** Units sold below which a product counts as slow-moving. */
    private const SLOW_MOVING_THRESHOLD = 5;

    public function index()
    {
        return view('reports.index');
    }

    public function sales(Request $request)
    {
        // Reports run off `sales_history` -- the imported sales record, which
        // on this install spans 2024-2026. The `sales` table only holds live
        // POS checkouts (a handful of rows, one month), so reporting off it
        // showed an almost-empty report while years of data sat unused.
        [$dataStart, $dataEnd] = SalesHistory::dateBounds();
        $months = SalesHistory::availableMonths();

        // A month picker is the primary control; the date inputs stay for
        // arbitrary ranges. Picking a month overrides the dates.
        $month = $request->get('month');

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
            : (int) \Illuminate\Support\Facades\DB::table('sales_history')
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

        AuditTrail::log('Viewed', "Generated Sales Report ({$start} to {$end})");

        return view('reports.sales', compact(
            'sales', 'start', 'end', 'totalSales', 'totalTransactions',
            'dailyBreakdown', 'months', 'month', 'totalUnits', 'activeDays',
            'dataStart', 'dataEnd', 'granularity'
        ));
    }

    public function inventory(Request $request)
    {
        $categoryId = $request->get('category_id');
        $lowStockOnly = $request->boolean('low_stock');

        $query = Product::with('category', 'batches')->orderBy('name');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get();

        if ($lowStockOnly) {
            $products = $products->filter(fn ($p) => $p->is_low_stock)->values();
        }

        $totalStockValue = $products->sum(fn ($p) => $p->total_stock * $p->selling_price);
        $lowStockCount = $products->filter(fn ($p) => $p->is_low_stock)->count();
        $expiredCount = $products->filter(fn ($p) => $p->batches->contains(fn ($b) => $b->quantity > 0 && $b->is_expired))->count();

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

        $categories = \App\Models\Category::orderBy('name')->get();

        $logMsg = 'Generated Inventory Report';
        if ($categoryId || $lowStockOnly) {
            $logMsg .= ' (filtered)';
        }
        AuditTrail::log('Viewed', $logMsg);

        return view('reports.inventory', compact(
            'products', 'totalStockValue', 'lowStockCount', 'expiredCount',
            'stockValueByCategory', 'categories', 'categoryId', 'lowStockOnly'
        ));
    }

    public function analytics(Request $request)
    {
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

        $topProducts = SalesHistory::topProductsBetween($start, $end, self::TOP_N);

        // Slow movers: catalogued products that sold fewer than 5 units in
        // the window (including those that sold none at all).
        $soldQuantities = SalesHistory::unitsSoldBetween($start, $end);

        // Genuinely the slowest movers, not the first ten alphabetically:
        // rank by units sold ascending (0 first), then by remaining stock
        // descending so the biggest dead-stock problems surface first.
        // Stock summed in SQL rather than by hydrating every product with its
        // batches — that pulled 2,637 models plus relations to read one number
        // each and cost ~600ms of PHP on its own.
        $slowAll = \Illuminate\Support\Facades\DB::table('products')
            ->leftJoin('product_batches', 'product_batches.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.name, products.sku, COALESCE(SUM(product_batches.quantity), 0) AS total_stock')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->get()
            ->filter(fn ($p) => (float) ($soldQuantities[$p->sku] ?? 0) < self::SLOW_MOVING_THRESHOLD)
            ->map(function ($p) use ($soldQuantities) {
                $p->units_sold = (float) ($soldQuantities[$p->sku] ?? 0);
                $p->total_stock = (int) $p->total_stock;

                return $p;
            });

        $slowMovingCount = $slowAll->count();

        // The full ranked list — the view scrolls it. Only the CHART is
        // capped, since 100+ bars is unreadable.
        $slowMoving = $slowAll
            ->sortBy([['units_sold', 'asc'], ['total_stock', 'desc']])
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
