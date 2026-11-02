<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL indexes every foreign key column by itself; PostgreSQL does not. Without
 * these, each lookup of a product's batches or a sale's items reads the whole
 * table: the till's scan index took 648 ms against MySQL's 74 ms. MySQL already
 * has them, so this does nothing there.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'audit_trails' => ['user_id'],
        'product_batches' => ['product_id', 'returned_by'],
        'products' => ['category_id'],
        'qr_payments' => ['sale_id', 'user_id'],
        'sale_items' => ['product_batch_id', 'product_id', 'sale_id'],
        'sales' => ['user_id', 'voided_by'],
        'stock_movements' => ['performed_by', 'sale_id'],
        'stock_reports' => ['reported_by', 'reviewed_by'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX IF NOT EXISTS \"{$table}_{$column}_index\" ON \"{$table}\" (\"{$column}\")");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("DROP INDEX IF EXISTS \"{$table}_{$column}_index\"");
            }
        }
    }
};
