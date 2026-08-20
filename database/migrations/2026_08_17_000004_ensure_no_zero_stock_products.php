<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring every zero-stock product up to Product::openingStockFloor().
 *
 * The supplier master export carries Stock = 0 for anything that was out on
 * the day it was produced. Seeding that verbatim left 86 of 2,637 products
 * with no sellable stock: they could not be rung up at the POS, and they
 * dragged down the inventory report's totals and category breakdown.
 *
 * Only products whose TOTAL stock is zero are touched. A zero-quantity batch
 * on a product that still has stock in another batch is a normal depleted
 * lot and is deliberately left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Products with no stock in any batch.
        $empty = DB::table('products')
            ->leftJoin('product_batches', 'product_batches.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.reorder_level, COALESCE(SUM(product_batches.quantity), 0) AS stock')
            ->groupBy('products.id', 'products.reorder_level')
            ->havingRaw('stock <= 0')
            ->get();

        foreach ($empty as $row) {
            $target = Product::openingStockFloor((int) $row->reorder_level);

            // Prefer topping up the product's existing opening batch so we
            // don't accumulate a second lot every time this is re-run.
            $batch = DB::table('product_batches')
                ->where('product_id', $row->id)
                ->orderByRaw('expiry_date IS NULL, expiry_date DESC')
                ->first();

            if ($batch) {
                DB::table('product_batches')
                    ->where('id', $batch->id)
                    ->update([
                        'quantity' => $target,
                        'qty_received' => DB::raw("GREATEST(COALESCE(qty_received, 0), {$target})"),
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('product_batches')->insert([
                'product_id' => $row->id,
                'batch_number' => 'RESTOCK-' . $row->id,
                'quantity' => $target,
                'qty_received' => $target,
                'unit_cost' => 0,
                'received_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible: the original zero quantities carried no information
        // worth restoring, and we cannot tell a restocked batch from one that
        // was legitimately replenished afterwards.
    }
};
