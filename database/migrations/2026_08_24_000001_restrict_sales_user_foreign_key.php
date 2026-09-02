<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sales.user_id` was ON DELETE CASCADE. Deleting a cashier therefore deleted
 * every sale they had ever rung up — and `sale_items.sale_id` cascades in turn,
 * so the line items went with them.
 *
 * Silently. `UserController::destroy` reported "User deleted successfully" and
 * the transactions were simply gone: no audit entry, no warning, nothing to
 * reconcile against. Measured on this install before the change, deleting the
 * staff cashier removed 2 sales, 4 sale_items and ₱249.62 of revenue.
 *
 * Worse than the loss itself, the stock those sales deducted is NOT given back.
 * FEFO already took 5 units off the shelf for them, so the inventory and the
 * sales record end up permanently disagreeing with no record of why.
 *
 * This install shows the damage already done: two accounts have been deleted
 * ("Ambrosia", "Staff One") and 25 ids are missing from a 1..63 range holding
 * 38 rows. It is also what made `Sale::count() + 1` regress and start re-issuing
 * transaction numbers — see Sale::nextTransactionNo().
 *
 * RESTRICT is the honest rule: a sale is a financial record and must outlive the
 * employee. UserController::destroy now checks first and points the admin at
 * Deactivate, which is the intended way to retire an account and which keeps the
 * history intact. This constraint is the backstop for every other path — tinker,
 * a future bulk tool, a seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
