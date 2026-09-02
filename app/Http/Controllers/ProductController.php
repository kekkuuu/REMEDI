<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SalesHistory;
use App\Services\AlertService;
use App\Services\SalesForecastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Every table that keys on `products.sku` as a plain string.
     *
     * None of these has a foreign key to `products.sku`, so the database will
     * not stop a rename from orphaning them — this list is the only thing that
     * does. Anything added here must be re-pointed by update() and cleaned up
     * by destroy(); if you introduce another `product_sku` column anywhere,
     * add it here rather than to the loop.
     */
    private const SKU_KEYED_TABLES = [
        'sales_history',
        'demand_forecasts',
        'sales_forecasts',
        'inventory_receipts',
        'forecast_accuracy',
    ];

    public function index(Request $request)
    {
        $query = Product::with('category', 'batches');

        if ($request->filled('search')) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            $like = $this->likeTerm($request->search);
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('name')->paginate(10)->withQueryString();
        $categories = Category::orderBy('name')->get();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('products._rows', compact('products'))->render(),
                'pagination' => (string) $products->links(),
            ]);
        }

        return view('products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();

        return view('products.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'category_id' => 'required|exists:categories,id',
            // Rule::in over the canonical list, not free text -- see
            // Product::UNITS for why the catalogue had a unit called "20".
            'unit' => ['required', Rule::in(Product::unitOptions())],
            // Bounded by the columns behind them -- see Controller::MAX_MONEY.
            'selling_price' => 'required|numeric|min:0|max:'.self::MAX_MONEY,
            'reorder_level' => 'required|integer|min:0|max:'.self::MAX_COUNT,
        ]);

        $product = Product::create($validated);
        AuditTrail::log('Created', "Added product: {$product->name} (SKU: {$product->sku})");

        AlertService::forget();

        return $this->actionOk($request, "Product \"{$product->name}\" added successfully.", redirect()->route('products.index'));
    }

    public function edit(Product $product)
    {
        $categories = Category::orderBy('name')->get();

        // Smart default for the "Add New Batch" form's expiry date: read
        // this product's typical shelf life off its most recently
        // received batch (days between received_date and expiry_date)
        // and project that forward from today, rather than reusing a
        // stale historical date. Staff can still edit it before
        // submitting — this just saves re-typing for the common case
        // where a product's shelf life is consistent batch to batch.
        $suggestedExpiryDate = null;
        $latestBatch = $product->batches()
            ->whereNotNull('received_date')
            ->whereNotNull('expiry_date')
            ->orderByDesc('received_date')
            ->first();

        if ($latestBatch) {
            $shelfLifeDays = $latestBatch->received_date->diffInDays($latestBatch->expiry_date);
            if ($shelfLifeDays > 0) {
                $suggestedExpiryDate = now()->addDays($shelfLifeDays);
            }
        }

        return view('products.edit', compact('product', 'categories', 'suggestedExpiryDate'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku,'.$product->id,
            'barcode' => 'nullable|string|max:100|unique:products,barcode,'.$product->id,
            'category_id' => 'required|exists:categories,id',
            // unitOptions($product->unit) rather than the bare list: one legacy
            // row has a unit of "20", and rejecting it here would make that
            // product uneditable until someone noticed why.
            'unit' => ['required', Rule::in(Product::unitOptions($product->unit))],
            'selling_price' => 'required|numeric|min:0|max:'.self::MAX_MONEY,
            'reorder_level' => 'required|integer|min:0|max:'.self::MAX_COUNT,
        ]);

        $oldSku = $product->sku;
        $skuChanged = $validated['sku'] !== $oldSku;

        // A SKU rename has to carry the product's history with it.
        //
        // `sales_history`, `demand_forecasts`, `sales_forecasts` and
        // `inventory_receipts` all key on `product_sku` as a STRING, with no
        // foreign key to products.sku — so renaming a product silently
        // detached everything joined to the old value. Measured on
        // HERACLENE 1MG TAB X100: 1,459 history rows / 42,276 units, and
        // **₱909,358.82 of lifetime revenue vanished from every report**, with
        // all 6 of its forecast rows orphaned. No error, no warning; the totals
        // simply got smaller.
        //
        // `inventory_receipts` was the last one missed, and it hid the longest
        // because it fails quietly rather than visibly: DashboardController
        // reads it only as a fallback ranking (when both forecast-derived
        // lists come back empty) and resolves SKUs with
        // `Product::whereIn('sku', ...)`, so an orphaned row is dropped from
        // the join instead of raising anything. The product just stops
        // appearing in its own purchase history.
        //
        // Renaming and re-pointing together, in one transaction, is what the
        // user means by "fix the SKU" — the alternative (refusing the edit)
        // strands a genuinely mis-keyed product forever.
        $moved = DB::transaction(function () use ($product, $validated, $oldSku, $skuChanged) {
            $product->update($validated);

            if (! $skuChanged) {
                return [];
            }

            $counts = [];

            foreach (self::SKU_KEYED_TABLES as $table) {
                $counts[$table] = DB::table($table)
                    ->where('product_sku', $oldSku)
                    ->update(['product_sku' => $validated['sku']]);
            }

            return $counts;
        });

        $note = $skuChanged
            ? " — SKU {$oldSku} to {$validated['sku']}, moved ".implode(', ', array_map(
                fn ($t, $n) => "{$n} {$t}",
                array_keys($moved),
                $moved
            ))
            : '';

        AuditTrail::log('Updated', "Updated product: {$product->name}{$note}");

        AlertService::forget();

        // Revenue joins on sku, so a rename changes every cached aggregate even
        // when the price did not. The Product::saved hook only watches
        // selling_price, so say so explicitly here.
        if ($skuChanged) {
            SalesHistory::forgetCaches();
            Cache::forget(SalesForecastService::CACHE_KEY);
        }

        return $this->actionOk($request, "Product \"{$product->name}\" updated successfully.", redirect()->route('products.index'));
    }

    public function destroy(Request $request, Product $product)
    {
        $name = $product->name;

        // A product that has ever been sold cannot be deleted, and must not be.
        // `sale_items.product_id` and `.product_batch_id` are both ON DELETE
        // RESTRICT, deliberately: a receipt, the sales list and every report
        // read back through those rows, so removing the product would leave
        // historical sales pointing at nothing.
        //
        // Without this check the RESTRICT still stopped the delete — as an
        // uncaught QueryException, i.e. a 500 with the raw SQL in it. Same
        // shape as CategoryController::destroy, which has always refused to
        // delete a category that still has products.
        $soldCount = $product->saleItems()->count();

        if ($soldCount > 0) {
            return $this->actionFailed(
                $request,
                "\"{$name}\" has been sold {$soldCount} ".str('time')->plural($soldCount)
                .' and cannot be deleted — past sales and receipts still refer to it. '
                .'Set its stock to zero instead if you no longer stock it.',
                'product'
            );
        }

        // The same rule for the imported sales record, which is where almost
        // all of the exposure actually is.
        //
        // `sales_history.product_sku` is a plain string with NO foreign key, so
        // the RESTRICT above does not protect it: the guard covered 29 products
        // while 2,555 carry history and were freely deletable. Deleting one
        // silently drops its rows out of every revenue join — measured on
        // SYMBICORT 160/4.5MCG RAPIHALER, **₱7,741,795.77** would have vanished
        // from every report, more than a tenth of lifetime revenue, with no
        // error and nothing in the audit trail to explain the drop.
        $historyRows = DB::table('sales_history')->where('product_sku', $product->sku)->count();

        if ($historyRows > 0) {
            return $this->actionFailed(
                $request,
                "\"{$name}\" has ".number_format($historyRows).' rows of sales history and cannot be '
                .'deleted — every revenue report reads through them, and removing the product would '
                .'silently reduce past totals. Set its stock to zero instead if you no longer stock it.',
                'product'
            );
        }

        // `inventory_receipts` is the fourth sku-keyed table, and it is
        // deliberately NOT a reason to refuse the delete.
        //
        // Receipts are purchase history — what arrived, not what was sold — so
        // unlike `sales_history` they carry no revenue and no report reads a
        // peso figure through them. Guarding on them would block essentially
        // every deletion (nearly all stock arrives via a receipt) to protect
        // rows that DashboardController only consults as a fallback ranking.
        //
        // Leaving them behind is wrong too: the ranking resolves SKUs with
        // `Product::whereIn('sku', ...)`, so orphans are silently dropped from
        // the join and simply accumulate as dead rows keyed to a product that
        // no longer exists. Clear them with the product, inside a transaction
        // so a failed delete cannot take the receipts with it, and say how many
        // in the audit entry — a deletion that quietly discards records should
        // still leave a count behind.
        $receiptRows = DB::table('inventory_receipts')->where('product_sku', $product->sku)->count();

        // Log AFTER the delete succeeds, never before.
        //
        // This used to run first, so a delete the database then refused still
        // wrote "Deleted product: X" to the audit trail while the product sat
        // there untouched. For a pharmacy that log is a compliance artifact;
        // an entry asserting something that did not happen is worse than the
        // crash it accompanied.
        DB::transaction(function () use ($product) {
            DB::table('inventory_receipts')->where('product_sku', $product->sku)->delete();
            $product->delete();
        });

        $note = $receiptRows > 0
            ? ' — also removed '.number_format($receiptRows).' '
                .str('inventory receipt')->plural($receiptRows)
            : '';

        AuditTrail::log('Deleted', "Deleted product: {$name}{$note}");

        AlertService::forget();

        return $this->actionOk($request, "Product \"{$name}\" and its batches were deleted.", redirect()->route('products.index'));
    }

    // ===== Batch Management =====

    public function addBatch(Request $request, Product $product)
    {
        $validated = $request->validate([
            'batch_number' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1|max:'.self::MAX_COUNT,
            // after:received_date as well as after:today. Without it a batch
            // could be saved expiring BEFORE it was received, which is not just
            // untidy: edit() derives the next batch's suggested expiry from
            // received_date -> expiry_date, so one inverted row silently kills
            // the suggestion for every batch added after it.
            'expiry_date' => 'required|date|after:today|after:received_date',
            // before_or_equal:today -- stock cannot have been received on a day
            // that has not happened. The date input caps itself at today too,
            // but a picker is a suggestion and this is the rule: same lesson as
            // markBatchReturned and pos.receipt, where a gated control sat in
            // front of an ungated endpoint. A future received_date is not
            // harmless either -- expiry validates after:received_date, and
            // edit() derives the next batch's suggested shelf life from the gap
            // between the two, so one date from next year quietly poisons both.
            'received_date' => 'required|date|before_or_equal:today',
        ]);

        $validated['product_id'] = $product->id;

        // The quantity a batch arrived with, as opposed to what is left of it
        // after FEFO checkouts have eaten into `quantity`. The seeder, the
        // receiving-report importer and migration 2026_08_17_000004 all set
        // this; only this action did not, so every batch added through the UI
        // (here and the Inventory quick-restock, which posts to this same
        // route) lost the original figure.
        $validated['qty_received'] = $validated['quantity'];

        ProductBatch::create($validated);

        AuditTrail::log('Created', "Added batch '{$validated['batch_number']}' ({$validated['quantity']} units) for {$product->name}");

        AlertService::forget();

        return $this->actionOk($request, "Batch added to {$product->name}.", back());
    }

    public function updateBatch(Request $request, ProductBatch $batch)
    {
        $oldExpiry = $batch->expiry_date?->toDateString();
        $oldQuantity = (int) $batch->quantity;
        $wasExpired = $batch->is_expired;

        $rules = [
            'quantity' => 'required|integer|min:0|max:'.self::MAX_COUNT,
            // required, not nullable. A batch with no expiry date can never be
            // judged expired (is_expired returns false for null), so clearing
            // it would make the batch permanently sellable — a way to launder
            // expired stock past every guard in scopeSellable(). No batch in
            // the catalogue has a null expiry, so requiring it breaks nothing.
            'expiry_date' => 'required|date',
        ];

        // The date rule applies ONLY when the expiry is actually being changed.
        //
        // 73 seeded batches already have expiry <= received_date, and the edit
        // form re-posts the stored expiry unchanged — so enforcing this
        // unconditionally would lock every one of them out of editing,
        // including zeroing the quantity to write them off, which is exactly
        // what you want to do with them.
        if ($request->input('expiry_date') !== $oldExpiry && $batch->received_date) {
            $rules['expiry_date'] .= '|after:'.$batch->received_date->toDateString();
        }

        $validated = $request->validate($rules);

        $batch->update($validated);

        // Record WHAT changed, not just that something did.
        //
        // This said only "Updated batch 'X'", which meant moving an expiry date
        // left no trace. That mattered because the expiry is the one field that
        // can resurrect unsellable stock: editing an expired batch's date into
        // the future makes it sellable again and the till will dispense it.
        // Verified before this change — two requests turned an expired
        // medicine batch into a completed sale.
        //
        // Correcting a genuine typo at receipt time is legitimate, so the fix
        // is not to forbid the edit but to make it visible. An expiry moved on
        // a batch that WAS expired is called out explicitly, because that is
        // the shape of un-expiring stock and it should never pass unnoticed.
        $changes = [];

        if ((int) $validated['quantity'] !== $oldQuantity) {
            $changes[] = "quantity {$oldQuantity} to {$validated['quantity']}";
        }

        if ($validated['expiry_date'] !== $oldExpiry) {
            $changes[] = "expiry {$oldExpiry} to {$validated['expiry_date']}"
                .($wasExpired ? ' — BATCH WAS EXPIRED' : '');
        }

        AuditTrail::log('Updated', "Updated batch '{$batch->batch_number}' for {$batch->product->name}"
            .($changes ? ': '.implode(', ', $changes) : ' (no field changed)'));

        AlertService::forget();

        return $this->actionOk($request, "Batch {$batch->batch_number} updated.", back());
    }

    public function destroyBatch(Request $request, ProductBatch $batch)
    {
        $name = $batch->product->name;
        $batchNumber = $batch->batch_number;

        // Same rule as destroy(): `sale_items.product_batch_id` is RESTRICT, so
        // a batch that has been sold from is part of the sales record. 29 of
        // the batches on this install are referenced that way, and deleting any
        // of them used to 500 with the raw SQL in the response body.
        $soldCount = $batch->saleItems()->count();

        if ($soldCount > 0) {
            return $this->actionFailed(
                $request,
                "Batch {$batchNumber} has been sold from and cannot be deleted — "
                .'past sales still refer to it. Set its quantity to 0 to take it out of stock, '
                .'or mark it returned if it went back to the supplier.',
                'batch'
            );
        }

        // Logged only once the delete has actually happened; see destroy().
        $batch->delete();

        AuditTrail::log('Deleted', "Removed batch '{$batchNumber}' from {$name}");

        AlertService::forget();

        return $this->actionOk($request, "Batch {$batchNumber} removed from {$name}.", back());
    }

    // Mark a batch as physically returned to the supplier. Only meaningful
    // once it's inside (or has missed) the return window — see
    // ProductBatch::getReturnStatusAttribute().
    public function markBatchReturned(Request $request, ProductBatch $batch)
    {
        /* Both Return buttons are already gated on is_returnable -- the
         * inventory row picks the earliest returnable batch, the product edit
         * page wraps the form in @if($batch->is_returnable). This endpoint was
         * not, and it is destructive: it sets quantity to 0.
         *
         * So a POST to this route zeroed ANY batch, whether or not it was
         * eligible. Measured on this catalogue: 2,611 batches hold stock and
         * only 80 of them can actually go back to the supplier -- the other
         * 2,531 carry 491,379 units worth about PHP 7.69M, and every one could
         * be written off by a request the UI would never issue. A stale form, a
         * double submit or a hand-made request was all it took, and there is no
         * undo.
         *
         * Same lesson as pos.receipt and suggest.sales: the button being gated
         * is not the same thing as the endpoint being gated.
         */
        if ($batch->returned_at) {
            return $this->actionFailed(
                $request,
                "Batch {$batch->batch_number} was already returned on "
                .$batch->returned_at->format('M j, Y').'.',
                'batch'
            );
        }

        if (! $batch->is_returnable) {
            return $this->actionFailed(
                $request,
                "Batch {$batch->batch_number} is not inside its supplier return window, so it "
                .'cannot be marked as returned. Marking it would write off '
                .number_format($batch->quantity).' '.($batch->product->unit ?? 'units')
                .' of sellable stock.',
                'batch'
            );
        }

        $returnedQty = $batch->quantity;

        $batch->update([
            'returned_at' => now(),
            'returned_by' => $request->user()->id,
            // The units have gone back to the supplier, so they are no longer
            // on the shelf. This was left untouched, and the rest of the app
            // already assumed otherwise -- InventoryController widened its
            // eager load to `quantity > 0 OR returned_at IS NOT NULL` precisely
            // because "a returned batch has normally been shipped back, so its
            // quantity is 0". Nothing actually made that true, so a returned
            // batch kept counting toward total_stock and stayed in the FEFO
            // queue: BABY DOVE BAR 75G sat at "Successfully Returned" with 3
            // units the till would still have sold.
            //
            // qty_received preserves how many arrived, so zeroing this loses
            // nothing -- and the batch keeps its row, its badges and its place
            // in the Returned filter.
            'quantity' => 0,
        ]);

        AuditTrail::log('Updated', "Marked batch '{$batch->batch_number}' of {$batch->product->name} as returned to supplier ({$returnedQty} units removed from stock)");

        AlertService::forget();

        return $this->actionOk(
            $request,
            "Batch {$batch->batch_number} of {$batch->product->name} marked as returned to the supplier.",
            back()
        );
    }
}
