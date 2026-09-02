<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holdout accuracy for each product's demand forecast.
 *
 * Written by `forecast:generate`, which refits the SAME cascade on the series
 * minus its last few months and scores the result against the months it held
 * back. Scoring the model actually in use is the point: a metric from some
 * other model would describe a forecast nobody is looking at.
 *
 * One row per product, not per month — these are summary statistics over the
 * holdout window, so they do not belong in `demand_forecasts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_accuracy', function (Blueprint $table) {
            $table->id();

            // Keyed on the SKU string like every other forecast table, with no
            // FK, for the same reason: see CLAUDE.md "product_sku is a foreign
            // key that isn't". A SKU rename must re-point this too.
            $table->string('product_sku')->index();

            // Mean Absolute Error and Root Mean Squared Error, both in units.
            // RMSE punishes large misses harder than MAE, so RMSE >> MAE means
            // the error is concentrated in a few bad months rather than spread.
            $table->decimal('mae', 12, 4);
            $table->decimal('rmse', 12, 4);

            // MAPE is NULLABLE on purpose. It divides by the actual, and a
            // month that sold nothing makes the term undefined — which is not
            // an edge case in this catalogue, where most products sell in only
            // a few months of the year. Null here means "no non-zero month in
            // the holdout to measure against", not "zero error".
            $table->decimal('mape', 9, 4)->nullable();

            // sMAPE stays defined when the actual is 0 (it divides by the sum
            // of both terms), so it is the honest fallback wherever MAPE is
            // null. Capped at 200% by its own definition.
            $table->decimal('smape', 9, 4)->nullable();

            // How the numbers were produced, so a reader can judge them.
            $table->unsignedTinyInteger('holdout_months');
            $table->unsignedSmallInteger('points_scored');
            $table->unsignedSmallInteger('points_scored_mape')->default(0);

            // The model the cascade picked for THIS product on the holdout fit.
            $table->string('method')->nullable();

            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->unique('product_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_accuracy');
    }
};
