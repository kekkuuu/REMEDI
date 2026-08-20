<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feeds resources/python/generate_forecasts.py (--source=mysql), which
     * expects exactly: received_at, product_sku, qty.
     */
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku')->index();
            $table->unsignedInteger('qty');
            $table->date('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipts');
    }
};
