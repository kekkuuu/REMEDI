<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkout had no timeout and no duplicate-submit guard: a hung response
     * on a flaky connection left the cashier with no cancel/retry, and a
     * reload or a second tap re-posted the same cart as a genuinely new
     * request -- a second Sale, a second FEFO deduction, a second charge.
     *
     * The POS page now generates one key per checkout attempt and resends it
     * unchanged on every retry of that same attempt (see PosController::
     * checkout()). A UNIQUE index is what makes that safe under real
     * concurrency, not just in the happy path: two requests racing with the
     * same key can both pass an application-level "does this exist yet?"
     * check before either commits, and only the database's own constraint
     * catches that. Nullable, because a request with no key (the no-JS
     * fallback form) must still be allowed to check out.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('payment_voided');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
