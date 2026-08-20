<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
public function index(Request $request)
{
    $query = Product::with('batches');

    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%");
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
            'stock' => $product->total_stock,
            'suggested_expiry_date' => $suggestedExpiryDate,
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'amount_paid' => 'nullable|numeric|min:0',
        ]);

        $result = DB::transaction(function () use ($validated, $request) {
            $totalAmount = 0;
            $lineItems = [];

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $requestedQty = (int) $item['quantity'];

                if ($product->total_stock < $requestedQty) {
                    return [
                        'error' => "Not enough stock for {$product->name}. Available: {$product->total_stock}, Requested: {$requestedQty}",
                    ];
                }

                $subtotal = $product->selling_price * $requestedQty;
                $totalAmount += $subtotal;

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

            if ((float) $amountPaid < $totalAmount) {
                return [
                    'error' => 'Insufficient payment. Amount due: ' . number_format($totalAmount, 2) . ', Received: ' . number_format((float) $amountPaid, 2),
                ];
            }

            $amountPaid = (float) $amountPaid;

            $sale = Sale::create([
                'transaction_no' => 'TXN-' . now()->format('Ymd') . '-' . str_pad((Sale::count() + 1), 5, '0', STR_PAD_LEFT),
                'user_id' => $request->user()->id,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_due' => $amountPaid - $totalAmount,
                'payment_voided' => false,
            ]);

            foreach ($lineItems as $line) {
                $remainingQty = $line['quantity'];

                $batches = $line['product']->batches()
                    ->where('quantity', '>', 0)
                    ->orderBy('expiry_date', 'asc')
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
        });

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

    public function receipt(Sale $sale)
    {
        $sale->load('items.product', 'user');
        return view('pos.receipt', compact('sale'));
    }
}
