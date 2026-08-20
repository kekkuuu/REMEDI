<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the DATE-RANGE aggregates on sales_history.
 *
 * The table had indexes on product_sku and (product_sku, sale_date) but nothing
 * leading with sale_date, so any `whereBetween('sale_date', …)` aggregate had no
 * way in: MySQL scanned the whole product_sku index instead — 337k entries at a
 * 1022-byte key, because product_sku is VARCHAR(255) utf8mb4 — to find a window
 * holding 7,850 rows. SalesHistory::recentDemand measured 45.6s that way.
 *
 * (sale_date, product_sku, quantity_sold) is covering for that shape: the range
 * seeks on the leading column, then GROUP BY product_sku / SUM(quantity_sold)
 * are both satisfied from the index without touching the row.
 *
 * A NOTE IN REMEDI.md PREVIOUSLY REJECTED a sale_date index, on the grounds that
 * these aggregates "group by DATE_FORMAT(sale_date, ...), which no index can
 * satisfy". That is true of monthlyRevenue and trendBetween, which do group by a
 * formatted month — but it is not true of recentDemand, unitsSoldBetween or
 * topProductsBetween, which filter on a plain date range and group by SKU. The
 * earlier measurement generalised from the wrong query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->index(['sale_date', 'product_sku', 'quantity_sold'], 'sales_history_date_sku_qty_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_history', function (Blueprint $table) {
            $table->dropIndex('sales_history_date_sku_qty_index');
        });
    }
};
