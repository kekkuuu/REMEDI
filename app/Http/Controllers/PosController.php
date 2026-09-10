<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
public function index(Request $request)
{
    $query = Product::with('batches');

    if ($request->filled('search')) {
        // likeTerm() escapes the user's own % and _ — see Controller.
        $like = $this->likeTerm($request->search);
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

    return view('pos.index', compact('products'));
}

    /**
     * Look up a single product by its barcode.
     * Called via AJAX from the POS page when a barcode scanner
     * "types" a code into the barcode input and presses Enter.
     */
    public function lookupBySku(Request $request)
    {
        $code = trim($request->query('sku', ''));

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

        if (!$product) {
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
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:'.self::MAX_COUNT,
            // max: the column is decimal(10,2) and MySQL is strict, so an
            // unbounded amount was a 500 at the register. See MAX_MONEY.
            'amount_paid' => 'nullable|numeric|min:0|max:'.self::MAX_MONEY,
        ]);

        // Retry the whole checkout if two registers race to the same
        // transaction number. Sale::nextTransactionNo()'s row lock serialises
        // them once the day has a sale in it, but the first sale of the day
        // locks an empty range, which two transactions can both hold — so the
        // unique index stays the final arbiter and this catches its refusal.
        //
        // Retrying the entire transaction is the safe unit: it has fully rolled
        // back by the time the exception surfaces, so no stock was deducted and
        // re-running is not a double sale. Bounded, because a duplicate key
        // that is NOT the sequence racing would otherwise spin forever.
        $result = $this->withTransactionNoRetry(fn () => DB::transaction(function () use ($validated, $request) {
            $totalAmount = 0;
            $lineItems = [];

            // Total the request PER PRODUCT before checking anything.
            //
            // The check used to run per line against that line's quantity
            // alone, so a cart naming the same product twice slipped through:
            // 6 units in stock, lines of 5 and 5, each compared 5 <= 6 and
            // passed. The customer was billed for all 10, FEFO could only
            // deduct 6, and the loop below simply ran out of batches and
            // stopped — no error. Measured: charged ₱1,000, delivered ₱600,
            // and the receipt printed "6 × ₱100.00" above a "Total ₱1,000.00"
            // with change calculated on the inflated figure. A ₱400 overcharge
            // on a receipt that does not add up.
            $requestedByProduct = [];

            foreach ($validated['items'] as $item) {
                $id = (int) $item['product_id'];
                $requestedByProduct[$id] = ($requestedByProduct[$id] ?? 0) + (int) $item['quantity'];
            }

            foreach ($requestedByProduct as $productId => $totalRequested) {
                $product = Product::findOrFail($productId);

                // sellable_stock, not total_stock: expired and returned batches
                // are physically on the shelf (or gone to the supplier) but must
                // never be dispensed. The FEFO walk below draws from the same
                // definition, so the figure quoted here and the stock actually
                // deducted cannot disagree.
                $available = $product->sellable_stock;

                if ($available < $totalRequested) {
                    return [
                        'error' => "Not enough stock for {$product->name}. Available: {$available}, Requested: {$totalRequested}",
                    ];
                }
            }

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $requestedQty = (int) $item['quantity'];

                // Round each line to centavos as it is computed, not just at
                // the end. sale_items.subtotal is decimal(10,2) so MySQL rounds
                // on the way in regardless — rounding here keeps the running
                // total equal to the sum of the stored line subtotals, which is
                // the invariant a receipt has to satisfy.
                $subtotal = round((float) $product->selling_price * $requestedQty, 2);
                $totalAmount = round($totalAmount + $subtotal, 2);

                $lineItems[] = [
                    'product' => $product,
                    'quantity' => $requestedQty,
                    'price' => $product->selling_price,
                    'subtotal' => $subtotal,
                ];
            }

            // The customer's payment is always required -- there is no
            // supervisor bypass. (Sales recorded before that bypass was
            // removed still carry payment_voided = true; the column and the
            // receipt's VOIDED line are kept so that history stays readable.)
            $amountPaid = $validated['amount_paid'] ?? null;

            if ($amountPaid === null) {
                return [
                    'error' => "Customer's payment is required before checkout. Enter the amount received.",
                ];
            }

            $amountPaid = round((float) $amountPaid, 2);

            // Compare in whole centavos, never as raw floats.
            //
            // selling_price comes back from MySQL as a string, and "1.05" * 3
            // is 3.1500000000000004 in binary floating point. So a customer
            // tendering exactly ₱3.15 was refused — with the message
            // "Insufficient payment. Amount due: 3.15, Received: 3.15", two
            // identical numbers and no way for the cashier to work out what was
            // wrong. 1,613 price x quantity combinations in this catalogue land
            // on a total that cannot be paid exactly.
            //
            // Integer centavos rather than round()-then-compare: rounding both
            // sides fixes this case, but comparing integers is exact by
            // construction and cannot drift again as the arithmetic grows.
            if ((int) round($amountPaid * 100) < (int) round($totalAmount * 100)) {
                return [
                    'error' => 'Insufficient payment. Amount due: ' . number_format($totalAmount, 2) . ', Received: ' . number_format($amountPaid, 2),
                ];
            }

            $sale = Sale::create([
                // Per-day sequence, read under a row lock. See
                // Sale::nextTransactionNo() for why the old Sale::count()+1
                // could hand out a number the day had already used.
                'transaction_no' => Sale::nextTransactionNo(),
                'user_id' => $request->user()->id,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                // Rounded like the rest: the subtraction of two floats is where
                // a stray fraction of a centavo would otherwise land in the
                // drawer figure the cashier reads back to the customer.
                'change_due' => round($amountPaid - $totalAmount, 2),
                'payment_voided' => false,
            ]);

            foreach ($lineItems as $line) {
                $remainingQty = $line['quantity'];

                // FEFO over SELLABLE batches only. Ordering by expiry ascending
                // is right for rotation, but on its own it made the register
                // reach for the most-expired batch first -- see
                // ProductBatch::scopeSellable().
                //
                // lockForUpdate(): without it, two registers selling the last
                // units of the same batch both read the pre-sale quantity,
                // both compute a deductQty that fits, and both decrement --
                // the second UPDATE reads a fresh (already-decremented) row
                // under the hood, so the column can go negative with neither
                // request ever seeing $remainingQty > 0. Locking these rows
                // makes the second transaction block until the first commits,
                // so it reads the TRUE remaining quantity and the assertion
                // below can actually catch it. Same pattern as
                // Sale::nextTransactionNo() and ProductBatch::nextBatchNumber().
                $batches = $line['product']->batches()
                    ->sellable()
                    ->orderBy('expiry_date', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $deductQty = min($batch->quantity, $remainingQty);

                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $line['product']->id,
                        'product_batch_id' => $batch->id,
                        'quantity' => $deductQty,
                        'price' => $line['price'],
                        'subtotal' => $line['price'] * $deductQty,
                    ]);

                    $batch->decrement('quantity', $deductQty);
                    $remainingQty -= $deductQty;
                }

                // The batches ran out before the line was filled. The aggregate
                // check above should make this unreachable, but it is the
                // invariant that actually matters — never bill for stock that
                // was not deducted — so it is asserted here rather than
                // assumed. It also covers the case the pre-check cannot: stock
                // moving between the check and this loop, e.g. a second
                // register selling the same product concurrently.
                //
                // Returning an error rolls the whole DB::transaction back, so
                // the sale, its line items and every decrement are undone
                // together. Previously the loop just ended and the customer was
                // charged the full amount for a partial delivery.
                if ($remainingQty > 0) {
                    return [
                        'error' => "Stock for {$line['product']->name} changed during checkout. "
                            .'Available: '.($line['quantity'] - $remainingQty).', Requested: '.$line['quantity']
                            .'. Nothing was charged — please retry.',
                    ];
                }
            }

            AuditTrail::log('Created', "Processed sale {$sale->transaction_no} - Total: \u{20B1}" . number_format($totalAmount, 2));

            // Reports merge live POS takings into their trends, so a new sale
            // makes those cached aggregates stale immediately.
            \App\Models\SalesHistory::bumpCacheVersion();

            // Checkout deducts stock, which can push a product below its
            // reorder level. Drop the bell's cached payload so the next poll
            // recomputes rather than showing the pre-sale count for up to
            // AlertService::TTL_SECONDS.
            \App\Services\AlertService::forget();

            return ['sale' => $sale];
        }));

        $wantsJson = $request->wantsJson() || $request->ajax();

        if (isset($result['error'])) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'error' => $result['error']], 422);
            }

            return back()->withErrors(['items' => $result['error']]);
        }

        $sale = $result['sale'];

        // The POS page checks out over fetch() so the cashier never leaves
        // the register: it takes the rendered receipt back as JSON and shows
        // it in a modal. The redirect below is the no-JavaScript fallback,
        // and still what a plain form post gets.
        if ($wantsJson) {
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

    /**
     * Run a checkout, retrying if it lost a race for the transaction number.
     *
     * Only retries a duplicate-key violation naming `transaction_no` — any
     * other integrity error is a real problem and must surface, not be papered
     * over by three silent re-runs. MySQL reports duplicate keys as SQLSTATE
     * 23000 / errno 1062 and names the offending index in the message, which is
     * how the two are told apart.
     *
     * On the last attempt the exception is rethrown rather than swallowed: a
     * checkout that cannot be numbered must fail loudly, because the
     * alternative is a cashier believing a sale was recorded when it was not.
     */
    private function withTransactionNoRetry(\Closure $checkout, int $attempts = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $checkout();
            } catch (QueryException $e) {
                $isSequenceClash = $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'transaction_no');

                if (! $isSequenceClash || $attempt >= $attempts) {
                    throw $e;
                }
            }
        }
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
