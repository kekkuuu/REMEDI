<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The words typed when a sale is voided for "Other" (2026-09-28, at the
     * user's request). void_reason stays the fixed key from Sale::VOID_REASONS
     * so every existing reader keeps working; this holds only the free text,
     * and only for "other". Additive and nullable: every earlier void simply
     * has none.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('void_note', 255)->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('void_note');
        });
    }
};
