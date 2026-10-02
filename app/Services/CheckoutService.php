<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesHistory;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Recording a sale: stock check, FEFO deduction, per-day transaction number,
 * idempotency -- the body of what PosController::checkout() always did,
 * moved here UNCHANGED (2026-10-02) so a PayMongo QR payment that completes
 * on its own (QrPaymentController, the webhook) records its sale through the
 * very same code rather than a second copy of it.
 *
 * Takes already-validated input (see PosController::checkout() for the
 * rules) and the id of the cashier the sale is credited to. Answers
 * ['sale' => Sale] or ['error' => string].
 */
class CheckoutService
{
    /**
     * What a cart costs right now, and whether the shelf can fill it -- the
     * same per-product stock check and per-line rounding run() applies, asked
     * BEFORE a QR code is made (QrPaymentController), so the customer is never
     * shown a code for a sale that cannot happen. run() checks again when the
     * payment lands: stock can move in between.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return array{total?: float, error?: string}
     */
    public function quote(array $items): array
    {
        $requestedByProduct = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $requestedByProduct[$id] = ($requestedByProduct[$id] ?? 0) + (int) $item['quantity'];
        }

        $total = 0.0;
        foreach ($requestedByProduct as $productId => $quantity) {
            $product = Product::with('batches')->findOrFail($productId);

            if ($product->sellable_stock < $quantity) {
                return ['error' => "Not enough stock for {$product->name}. Available: {$product->sellable_stock}, Requested: {$quantity}"];
            }
        }

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $total = round($total + round((float) $product->selling_price * (int) $item['quantity'], 2), 2);
        }

        return ['total' => $total];
    }

    /**
     * @param  array{items: array, amount_paid?: mixed, payment_method?: ?string, idempotency_key?: ?string}  $validated
     * @return array{sale?: Sale, error?: string}
     */
    public function run(array $validated, int $userId): array
    {
        $idempotencyKey = $validated['idempotency_key'] ?? null;

        // This exact attempt already went through — either the response to it
        // never reached the cashier (the timeout case) or this request lost a
        // race started by its own retry. Answer with what actually happened
        // instead of a second sale. Checkout cannot be retried blindly the
        // way a GET can: unlike a search box re-running a query is never free
        // here, it is a second stock deduction and a second charge.
        if ($idempotencyKey) {
            $existing = Sale::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return ['sale' => $existing];
            }
        }

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
        try {
            $result = $this->withTransactionNoRetry(fn () => DB::transaction(function () use ($validated, $userId, $idempotencyKey) {
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
                        'error' => 'Insufficient payment. Amount due: '.number_format($totalAmount, 2).', Received: '.number_format($amountPaid, 2),
                    ];
                }

                // GCash / Other QR are paid to the exact total -- there is no
                // change on an e-wallet transfer. The till locks its field to
                // the total; this is the endpoint's own check, since a gated
                // field is not a gated endpoint.
                if (in_array($validated['payment_method'] ?? 'cash', Sale::EXACT_PAYMENT_METHODS, true)
                    && (int) round($amountPaid * 100) !== (int) round($totalAmount * 100)) {
                    return [
                        'error' => 'A QR payment must be the exact amount: '.number_format($totalAmount, 2).'. Received: '.number_format($amountPaid, 2),
                    ];
                }

                $sale = Sale::create([
                    // Per-day sequence, read under a row lock. See
                    // Sale::nextTransactionNo() for why the old Sale::count()+1
                    // could hand out a number the day had already used.
                    'transaction_no' => Sale::nextTransactionNo(),
                    'user_id' => $userId,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    // Rounded like the rest: the subtraction of two floats is where
                    // a stray fraction of a centavo would otherwise land in the
                    // drawer figure the cashier reads back to the customer.
                    'change_due' => round($amountPaid - $totalAmount, 2),
                    'payment_voided' => false,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'idempotency_key' => $idempotencyKey,
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

                        StockMovement::record($batch, StockMovement::TYPE_SALE, -$deductQty, null, $sale->id);

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

                AuditTrail::log('Created', "Processed sale {$sale->transaction_no} - Total: \u{20B1}".number_format($totalAmount, 2));

                // Reports merge live POS takings into their trends, so a new sale
                // makes those cached aggregates stale immediately.
                SalesHistory::bumpCacheVersion();

                // Checkout deducts stock, which can push a product below its
                // reorder level. Drop the bell's cached payload so the next poll
                // recomputes rather than showing the pre-sale count for up to
                // AlertService::TTL_SECONDS.
                AlertService::forget();

                return ['sale' => $sale];
            }));
        } catch (QueryException $e) {
            // The other side of the race the pre-check above cannot close on
            // its own: two requests carrying the same key can both pass "does
            // this exist yet?" before either commits. InnoDB holds the second
            // INSERT until the first transaction resolves, so by the time
            // this fires the winner is guaranteed to have committed already —
            // look it up and answer with that, not a second sale. Same shape
            // as withTransactionNoRetry()'s own duplicate-key check just
            // above, for the OTHER unique column checkout can collide on.
            $isIdempotencyClash = $idempotencyKey
                && $e->getCode() === '23000'
                && str_contains($e->getMessage(), 'idempotency_key');

            if (! $isIdempotencyClash) {
                throw $e;
            }

            return ['sale' => Sale::where('idempotency_key', $idempotencyKey)->firstOrFail()];
        }

        return $result;
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
}
