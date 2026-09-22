<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `payment_voided` already existed (2026_08_16_000002) but nothing could
     * ever set it true -- checkout always writes false, and the old
     * supervisor-passcode bypass that once could was deliberately removed
     * (see PosController's own history). These four columns are what a REAL
     * void needs to record: who, when, why, and what it reversed each item
     * back onto (see StockMovement::TYPE_VOID).
     *
     * payment_method defaults 'cash' so every row written before this
     * migration -- and every row from a client that doesn't send the new
     * field -- reads as what it actually was: this terminal took cash only
     * until now.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('cash')->after('payment_voided');
            $table->timestamp('voided_at')->nullable()->after('payment_method');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 40)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['payment_method', 'voided_at', 'void_reason']);
        });
    }
};
