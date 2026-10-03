<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Forecasting page needs for WAPE and for MAPE by sales volume
 * (2026-10-02): per product, the units missed and the units actually sold in
 * the holdout, and its average monthly sales over the 12 months before it.
 * Nullable -- rows written before this have none, and the page hides both
 * figures until forecast:generate has run once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_accuracy', function (Blueprint $table) {
            $table->decimal('abs_error', 14, 4)->nullable()->after('points_scored_mape');
            $table->decimal('actual_units', 14, 4)->nullable()->after('abs_error');
            $table->decimal('avg_monthly_units', 14, 4)->nullable()->after('actual_units');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_accuracy', function (Blueprint $table) {
            $table->dropColumn(['abs_error', 'actual_units', 'avg_monthly_units']);
        });
    }
};
