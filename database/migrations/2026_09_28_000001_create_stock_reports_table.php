<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member telling an admin that a product is low on stock or holding
 * expired stock, and the admin's answer (App\Models\StockReport).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                       // StockReport::TYPES
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('pending'); // StockReport::STATUSES
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['product_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reports');
    }
};
