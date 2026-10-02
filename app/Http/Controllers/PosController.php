<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('batches');

        if (($search = $this->searchParam($request)) !== null) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            $like = $this->likeTerm($search);
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        }

        $products = $query->orderBy('name')->paginate(12)->withQueryString();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('pos._grid', compact('products'))->render(),
                'pagination' => (string) $products->links(),
            ]);
        }

        // The shop's GCash / InstaPay QR, uploaded on the Safeguard page.
        $gcashQrPayload = \App\Models\Setting::gcashQrPayload();

        // Automatic QR payments when PayMongo is configured (QrPaymentController).
        $paymongoEnabled = \App\Services\PayMongo::enabled();

        return view('pos.index', compact('products', 'gcashQrPayload', 'paymongoEnabled'));
    }

    /**
     * Look up a single product by its barcode.
     * Called via AJAX from the POS page when a barcode scanner
     * "types" a code into the barcode input and presses Enter.
     */
    public function lookupBySku(Request $request)
    {
        // searchParam(): `?sku[]=x` is an array, and trim() on it is a TypeError.
        $code = trim((string) $this->searchParam($request, 'sku'));

        if ($code === '') {
            return response()->json(['found' => false], 404);
        }

        // Try matching against the dedicated barcode field first,
        // then fall back to SKU (in case a product has no barcode set
        // but its SKU happens to match what was scanned).
        $product = Product::with('batches')
            ->where('barcode', $code)
            ->orWhere('sku', $code)
            ->first();

        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        // Same "read the product's typical shelf life and project it
        // forward from today" default used on the Product edit page's
        // Add New Batch form (see ProductController::edit), so the
        // Inventory page's Quick Restock card — the other place new
        // stock gets entered — pre-fills a sensible expiry date too.
        $latestBatch = $product->batches
            ->filter(fn ($b) => $b->received_date && $b->expiry_date)
            ->sortByDesc('received_date')
            ->first();

        $suggestedExpiryDate = null;
        if ($latestBatch) {
            $shelfLifeDays = $latestBatch->received_date->diffInDays($latestBatch->expiry_date);
            if ($shelfLifeDays > 0) {
                $suggestedExpiryDate = now()->addDays($shelfLifeDays)->format('Y-m-d');
            }
        }

        return response()->json([
            'found' => true,
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->selling_price,
            // What the till may actually sell, matching the grid and checkout.
            // This returned total_stock, so scanning a product whose stock had
            // all expired answered "stock: 10" and the cashier only found out
            // at checkout.
            'stock' => $product->sellable_stock,
            'suggested_expiry_date' => $suggestedExpiryDate,
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            // Not just any product row: an archived one is not sellable, and the
            // soft-delete scope would otherwise turn it into a 404 mid-checkout.
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereNull('archived_at')],
            'items.*.quantity' => 'required|integer|min:1|max:'.self::MAX_COUNT,
            // max: the column is decimal(10,2) and MySQL is strict, so an
            // unbounded amount was a 500 at the register. See MAX_MONEY.
            'amount_paid' => 'nullable|numeric|min:0|max:'.self::MAX_MONEY,
            // Nullable, default 'cash' below: the no-JS fallback form posts
            // no payment_method field at all, and every sale before this
            // existed really was cash. Sale::PAYMENT_METHODS is the one list.
            'payment_method' => 'nullable|in:'.implode(',', array_keys(Sale::PAYMENT_METHODS)),
            // Generated once by the POS page when the payment modal opens and
            // resent UNCHANGED on every retry of that same attempt — a network
            // timeout, a double-tap on Checkout, a resubmission after
            // "insufficient payment". Nullable: the no-JavaScript fallback
            // form has no way to generate one, and a checkout with no key
            // simply gets no replay protection, same as before this existed.
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        // The sale itself: App\Services\CheckoutService, shared with QR payments
        // that complete on their own (QrPaymentController).
        $result = app(CheckoutService::class)->run($validated, $request->user()->id);

        if (isset($result['error'])) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => $result['error']], 422);
            }

            return back()->withErrors(['items' => $result['error']]);
        }

        return $this->checkoutSuccessResponse($request, $result['sale']);
    }

    /**
     * The one success shape checkout ever answers with, whether this request
     * just created the sale or is a retry being told what its earlier attempt
     * already did.
     */
    private function checkoutSuccessResponse(Request $request, Sale $sale)
    {
        // The POS page checks out over fetch() so the cashier never leaves
        // the register: it takes the rendered receipt back as JSON and shows
        // it in a modal. The redirect below is the no-JavaScript fallback,
        // and still what a plain form post gets.
        if ($request->wantsJson() || $request->ajax()) {
            $sale->load('items.product', 'user');

            return response()->json([
                'success' => true,
                'transaction_no' => $sale->transaction_no,
                'receipt_html' => view('pos._receipt', ['sale' => $sale])->render(),
                'receipt_url' => route('pos.receipt', $sale),
            ]);
        }

        return redirect()->route('pos.receipt', $sale)->with('success', 'Transaction completed successfully.');
    }

    public function receipt(Request $request, Sale $sale)
    {
        // This route had NO authorisation at all, while /sales/{sale} — the
        // same record, rendered with the same detail — returned 403 for a
        // staff account viewing someone else's. So the guard on the sales page
        // was bypassable simply by asking for the receipt permalink instead.
        //
        // A cashier still reaches their own receipt here: this is the redirect
        // target after checkout (the no-JavaScript path) and the reprint link.
        abort_unless($sale->isVisibleTo($request->user()), 403);

        $sale->load('items.product', 'user');

        return view('pos.receipt', compact('sale'));
    }
}
