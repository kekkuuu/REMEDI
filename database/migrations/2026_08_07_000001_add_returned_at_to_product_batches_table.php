<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            // When set, this batch has actually been sent back to the
            // supplier — a "Successful Return". Left null while a batch is
            // still pending ("Need to Return") or has missed the window
            // ("Fail to Return").
            $table->timestamp('returned_at')->nullable()->after('expiry_date');
            $table->foreignId('returned_by')->nullable()->after('returned_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn('returned_at');
        });
    }
};
