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
     * does. Anything added here must be re-pointed by update(); if you
     * introduce another `product_sku` column anywhere, add it here rather than
     * to the loop. destroy() no longer touches these tables at all: it archives
     * the product, so the rows keep pointing at a row that still exists.
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
        // ?archived=1 lists the archived products, which is where Restore lives.
        // The default list never shows them: the soft-delete scope hides them.
        $archived = $request->boolean('archived');

        $query = $archived
            ? Product::onlyTrashed()->with('category')
            : Product::with('category', 'batches');

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
                'html' => view('products._rows', compact('products', 'archived'))->render(),
                'pagination' => (string) $products->links(),
            ]);
        }

        // Counted only for the toggle's badge, and only on the full page: the
        // live-search swap never redraws it.
        $archivedCount = Product::onlyTrashed()->count();

        return view('products.index', compact('products', 'categories', 'archived', 'archivedCount'));
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
            // An archived category is not offered by the pickers; refuse a
            // hand-posted id for one just the same.
            'category_id' => ['required', Rule::exists('categories', 'id')->whereNull('archived_at')],
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

        // What the Add New Batch form needs to show the number the save will
        // actually produce, without asking the server on every date keystroke.
        // The stored value is still decided by ProductBatch::nextBatchNumber().
        $batchSequences = ProductBatch::takenSequences($product);

        // Batches archived on their own (a product archived WITH its batches is
        // not reachable here at all -- route-model binding hides it).
        $archivedBatches = $product->batches()->onlyTrashed()->orderByDesc('archived_at')->get();

        return view('products.edit', compact('product', 'categories', 'suggestedExpiryDate', 'batchSequences', 'archivedBatches'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku,'.$product->id,
            'barcode' => 'nullable|string|max:100|unique:products,barcode,'.$product->id,
            'category_id' => ['required', Rule::exists('categories', 'id')->whereNull('archived_at')],
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

    /**
     * Archive a product -- the replacement for Delete.
     *
     * Nothing is removed. The product and its batches get an `archived_at`
     * stamp and drop out of the till, the inventory, the alerts and the
     * forecast lists, while everything that refers to them stays put: sale
     * lines and receipts still name the product, and `sales_history` (keyed on
     * `sku` with no foreign key) keeps counting toward every revenue report.
     *
     * That is why the old refusals are gone. Delete had to say no whenever a
     * sale line or a history row pointed at the product -- which was nearly
     * always -- and had to hand-clean five SKU-keyed tables when it did say
     * yes. Archiving needs neither: it cannot orphan anything, so there is
     * nothing to guard and nothing to clean.
     *
     * The SKU stays reserved by the archived row (the unique index still sees
     * it), which is also what stops a NEW product from inheriting a dead one's
     * history and forecast -- the reuse hazard the old delete comment worried
     * about. To bring a product back, restore it; do not re-create it.
     */
    public function destroy(Request $request, Product $product)
    {
        $name = $product->name;

        // The batches are stamped with the product's OWN timestamp so restore()
        // can hand back exactly the batches archived with it and leave alone
        // any that were archived on their own beforehand.
        DB::transaction(function () use ($product) {
            $product->delete();
            $product->batches()->update(['archived_at' => $product->archived_at]);
        });

        // After the archive succeeds, never before -- an entry claiming an
        // action that did not happen is worse than the failure it hides.
        AuditTrail::log('Archived', "Archived product: {$name} (SKU: {$product->sku})");

        AlertService::forget();

        return $this->actionOk($request, "Product \"{$name}\" archived. Its sales history is untouched.", redirect()->route('products.index'));
    }

    /** Bring an archived product back, together with the batches archived with it. */
    public function restore(Request $request, Product $product)
    {
        abort_unless($product->trashed(), 404);

        // The category may have been archived after this product was. It would
        // come back pointing at a category the pickers no longer offer.
        if ($product->category?->trashed()) {
            return $this->actionFailed(
                $request,
                "\"{$product->name}\" belongs to the archived category \"{$product->category->name}\". Restore the category first.",
                'product'
            );
        }

        DB::transaction(function () use ($product) {
            $stamp = $product->archived_at;

            $product->restore();

            ProductBatch::onlyTrashed()
                ->where('product_id', $product->id)
                ->where('archived_at', $stamp)
                ->restore();
        });

        AuditTrail::log('Restored', "Restored product: {$product->name} (SKU: {$product->sku})");

        AlertService::forget();

        return $this->actionOk($request, "Product \"{$product->name}\" restored.", redirect()->route('products.index', ['archived' => 1]));
    }

    // ===== Batch Management =====

    public function addBatch(Request $request, Product $product)
    {
        $validated = $request->validate([
            // Accepted so a stray field does not 422, and then DISCARDED --
            // the number is derived below, never taken from the request. Both
            // forms render it readonly, so there is nothing for a user to type;
            // what the browser puts in the box is a preview of the rule, and
            // the server is the only thing that decides.
            'batch_number' => 'nullable|string|max:100',
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

        // ALWAYS derived, never read from the request -- deriving here rather
        // than trusting the field the browser filled in is what stops a user
        // from TYPING a colliding number. Closing the race between two
        // concurrent requests is a separate step: nextBatchNumber() takes a
        // row lock, and that lock only holds until whatever transaction it
        // was called inside commits -- hence wrapping the derive-then-create
        // pair in one transaction here, rather than letting the lock release
        // the instant the SELECT finishes.
        //
        // After validation, so `received_date` is known to be a real date that
        // is not in the future -- generating from unvalidated input would stamp
        // a number with a day the same request is about to reject.
        DB::transaction(function () use (&$validated, $product) {
            $validated['batch_number'] = ProductBatch::nextBatchNumber($product, $validated['received_date']);

            // The quantity a batch arrived with, as opposed to what is left of
            // it after FEFO checkouts have eaten into `quantity`. The seeder,
            // the receiving-report importer and migration 2026_08_17_000004
            // all set this; only this action did not, so every batch added
            // through the UI (here and the Inventory quick-restock, which
            // posts to this same route) lost the original figure.
            $validated['qty_received'] = $validated['quantity'];

            ProductBatch::create($validated);
        });

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

        // Lock the row before writing: unlocked, two admins editing the same
        // batch at once (one correcting a receipt-count typo, another marking
        // spoilage) both read the pre-edit quantity, and whichever UPDATE
        // commits second silently clobbers the first's change with no
        // conflict signal to either user -- yet the audit entry below still
        // logs the loser's edit as if it took effect. Re-reading under
        // lockForUpdate() inside a transaction serialises the two writes
        // instead, the same pattern already used for the batch-number and
        // transaction-number races.
        DB::transaction(function () use ($batch, $validated) {
            ProductBatch::whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->update($validated);
        });

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

    /**
     * Archive a batch. It leaves the shelf: out of the stock totals, the
     * alerts and the inventory, but the row stays, so a past sale that drew on
     * it still resolves and the delivery is still on record.
     *
     * The old "has been sold from" refusal is gone. It existed because a real
     * delete would have hit `sale_items.product_batch_id` (RESTRICT) or wiped
     * history; archiving touches neither, so there is nothing left to refuse.
     */
    public function destroyBatch(Request $request, ProductBatch $batch)
    {
        $name = $batch->product->name;
        $batchNumber = $batch->batch_number;

        $batch->delete();

        // Logged only once the archive has actually happened; see destroy().
        AuditTrail::log('Archived', "Archived batch '{$batchNumber}' of {$name}");

        AlertService::forget();

        return $this->actionOk($request, "Batch {$batchNumber} archived.", back());
    }

    /** Put an archived batch back on the shelf. */
    public function restoreBatch(Request $request, ProductBatch $batch)
    {
        abort_unless($batch->trashed(), 404);

        // The parent's scope hides an archived product, so this is null exactly
        // when the product is archived -- and a batch cannot be on the shelf of
        // a product that is not.
        $product = $batch->product;

        if (! $product) {
            return $this->actionFailed(
                $request,
                "Batch {$batch->batch_number} belongs to an archived product. Restore the product first.",
                'batch'
            );
        }

        $batch->restore();

        AuditTrail::log('Restored', "Restored batch '{$batch->batch_number}' of {$product->name}");

        AlertService::forget();

        return $this->actionOk($request, "Batch {$batch->batch_number} restored.", back());
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

        // Re-check under a lock before writing. Unlocked, two concurrent
        // requests for the same batch (two tabs, a retried submit, a replayed
        // request) can both pass the returned_at/is_returnable checks above
        // before either commits -- the quantity still converges to 0 either
        // way, but the audit trail gets two entries both claiming to be the
        // write-off, and returned_by ends up as whichever request happened to
        // win the race rather than necessarily the admin who intended it.
        // Locking and re-testing inside the transaction makes the loser see
        // the winner's already-set returned_at and refuse cleanly instead.
        [$returnedQty, $refused] = DB::transaction(function () use ($batch, $request) {
            $locked = ProductBatch::whereKey($batch->getKey())->lockForUpdate()->first();

            if ($locked->returned_at || ! $locked->is_returnable) {
                return [null, true];
            }

            $qty = $locked->quantity;

            $locked->update([
                'returned_at' => now(),
                'returned_by' => $request->user()->id,
                // The units have gone back to the supplier, so they are no
                // longer on the shelf. This was left untouched, and the rest
                // of the app already assumed otherwise -- InventoryController
                // widened its eager load to `quantity > 0 OR returned_at IS
                // NOT NULL` precisely because "a returned batch has normally
                // been shipped back, so its quantity is 0". Nothing actually
                // made that true, so a returned batch kept counting toward
                // total_stock and stayed in the FEFO queue: BABY DOVE BAR 75G
                // sat at "Successfully Returned" with 3 units the till would
                // still have sold.
                //
                // qty_received preserves how many arrived, so zeroing this
                // loses nothing -- and the batch keeps its row, its badges
                // and its place in the Returned filter.
                'quantity' => 0,
            ]);

            return [$qty, false];
        });

        if ($refused) {
            return $this->actionFailed(
                $request,
                "Batch {$batch->batch_number} was already returned or is no longer eligible.",
                'batch'
            );
        }

        AuditTrail::log('Updated', "Marked batch '{$batch->batch_number}' of {$batch->product->name} as returned to supplier ({$returnedQty} units removed from stock)");

        AlertService::forget();

        return $this->actionOk(
            $request,
            "Batch {$batch->batch_number} of {$batch->product->name} marked as returned to the supplier.",
            back()
        );
    }
}
