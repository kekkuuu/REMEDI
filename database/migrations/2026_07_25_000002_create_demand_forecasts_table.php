<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku')->index();
            $table->date('forecast_date');
            $table->decimal('forecast_value', 12, 2);
            $table->decimal('lower_ci', 12, 2)->nullable();
            $table->decimal('upper_ci', 12, 2)->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['product_sku', 'forecast_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
