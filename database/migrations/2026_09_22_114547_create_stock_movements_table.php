<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock card ledger: one append-only row per unit of stock movement
     * (stock-in, sale, return, manual adjustment, pull-out), so a discrepancy
     * like "500 units sold but never recorded" can be read off directly
     * instead of pieced together from batches + sale_items + the audit trail.
     *
     * Scoped to product_id (a real FK -- products are archived, never force-
     * deleted, so this never cascades in practice) and, where the movement
     * came from one, product_batch_id -- nullable because a future movement
     * type might not be batch-specific, and nulled rather than blocked on
     * delete since DedupeOpeningStockBatches force-deletes duplicate seed
     * rows and the ledger entry should survive that as history.
     *
     * balance_after is the BATCH's own remaining quantity immediately after
     * this row, not a product-wide total -- unambiguous to write at insert
     * time, and a product's current total is always cheaply derivable from
     * product_batches directly when needed.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // stock_in | sale | return | adjustment | pull_out
            $table->integer('quantity_change'); // signed: + in, - out
            $table->integer('balance_after'); // the batch's own quantity after this row
            $table->string('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['product_batch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
