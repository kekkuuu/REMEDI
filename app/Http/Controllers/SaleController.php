<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with('user', 'items.product');

        // Staff can only see their own transactions; Admin sees all
        if ($request->user()->isStaff()) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('search')) {
            $query->where('transaction_no', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $sales = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        // Today's sales summary — aggregate directly rather than
        // fetching every row (with its eager-loaded user/items/product
        // relations) just to throw the models away after summing two
        // numbers.
        $todayQuery = (clone $query)->whereDate('created_at', today());
        $todayTotal = (clone $todayQuery)->sum('total_amount');
        $todayCount = (clone $todayQuery)->count();

        // Realtime search: same AJAX partial-swap pattern used by
        // Inventory, POS, and Forecasting. "Today's summary" above isn't
        // filter-dependent, so only the table + pagination need to come
        // back — no need to also resend those two cards on every keystroke.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('sales._rows', compact('sales'))->render(),
                'pagination' => (string) $sales->links(),
            ]);
        }

        return view('sales.index', compact('sales', 'todayTotal', 'todayCount'));
    }

    public function show(Sale $sale)
    {
        // Staff can only view their own transaction details
        if (auth()->user()->isStaff() && $sale->user_id !== auth()->id()) {
            abort(403);
        }

        $sale->load('items.product', 'user');
        return view('sales.show', compact('sale'));
    }
}
