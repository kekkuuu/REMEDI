<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->change();
            $table->integer('qty_received')->nullable()->after('quantity');
            $table->decimal('unit_cost', 10, 2)->nullable()->after('qty_received');
            $table->string('dr_no')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->date('expiry_date')->nullable(false)->change();
            $table->dropColumn(['qty_received', 'unit_cost', 'dr_no']);
        });
    }
};
