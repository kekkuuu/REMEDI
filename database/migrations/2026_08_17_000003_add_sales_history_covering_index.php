<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sales Forecasting page ranks top products with
 *
 *     SELECT product_sku, SUM(quantity_sold) ... GROUP BY product_sku
 *
 * over ~100k sales_history rows. product_sku was already indexed, but
 * quantity_sold was not, so MySQL still had to visit every row to read the
 * value it was summing. Adding quantity_sold to the index makes it covering
 * -- the aggregate is answered from the index alone. Measured on the live
 * table: 404ms -> 106ms.
 *
 * A (sale_date, quantity_sold) index was trialled for the month-grouped
 * aggregates too and deliberately NOT kept: those group by
 * DATE_FORMAT(sale_date, ...), a computed expression no index can satisfy,
 * and it measured no faster (375ms -> 366ms, inside noise).
 *
 * Note: sales_history_product_sku_index is now redundant (it duplicates the
 * prefix of the existing product_sku+sale_date unique index). Left in place
 * -- this table is bulk-loaded by the seeder, never written row-by-row, so
 * the extra write cost is irrelevant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->index(['product_sku', 'quantity_sold'], 'sales_history_sku_qty_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->dropIndex('sales_history_sku_qty_index');
        });
    }
};
