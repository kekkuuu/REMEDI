<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('sales_history', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku')->index();
            $table->date('sale_date');
            $table->unsignedInteger('quantity_sold');
            $table->timestamps();

            $table->unique(['product_sku', 'sale_date']); // prevent duplicate imports
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_history');
    }
};
