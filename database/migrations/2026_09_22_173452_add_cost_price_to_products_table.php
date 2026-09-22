<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable and optional, like ProductBatch's own unit_cost: a cost
            // isn't always in hand when a product is first keyed in, and the
            // seeded catalogue has none at all. Same decimal(10,2) shape as
            // selling_price, bound by the same Controller::MAX_MONEY.
            // Feeds DashboardController::computeTodayProfit() -- a line with
            // no cost set is excluded from that figure rather than assumed
            // to be pure profit.
            $table->decimal('cost_price', 10, 2)->nullable()->after('selling_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
