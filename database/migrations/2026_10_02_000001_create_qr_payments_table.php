<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PayMongo QR Ph code the till shows (2026-10-02). It holds the
 * cart the code was made for, so the sale can be recorded the moment the
 * payment lands -- by the till's poll or by PayMongo's webhook, whichever
 * gets there first -- and exactly once (sale_id, idempotency key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_payments', function (Blueprint $table) {
            $table->id();
            $table->string('intent_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->json('items');
            $table->string('payment_method', 20);
            $table->foreignId('user_id')->constrained('users');
            // pending -> completed, or expired, or attention (paid but the
            // sale could not be recorded -- stock moved, a price changed).
            $table->string('status', 20)->default('pending');
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_payments');
    }
};
