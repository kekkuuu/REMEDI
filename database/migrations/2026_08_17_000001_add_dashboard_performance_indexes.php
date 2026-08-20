<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Speeds up the pages that got noticeably heavier once inventory and
     * sales history grew: the Dashboard (loads every in-stock batch on
     * every load, filtered by quantity/expiry_date), Inventory monitoring,
     * and the Sales/Analytics reports (filtered by created_at). None of
     * these columns were indexed before, so MySQL had to scan the full
     * table for each of them.
     */
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->index('quantity');
            $table->index('expiry_date');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropIndex(['quantity']);
            $table->dropIndex(['expiry_date']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
