<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `unit_cost` and `dr_no` already exist (2026_08_02_023329) but had no form
     * writing them. `supplier` never existed at all -- the word only ever
     * appeared in code comments, never as data. Added together because a
     * stock-in record is naturally all three at once.
     */
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->string('supplier')->nullable()->after('dr_no');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn('supplier');
        });
    }
};
