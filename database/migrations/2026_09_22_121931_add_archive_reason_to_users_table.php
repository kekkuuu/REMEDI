<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why an account left the list -- set only when UserController::destroy()
     * archives one, cleared on restore(). Nullable: every row archived before
     * this migration, and every row that's never been archived, simply has no
     * reason to show, and the badge falls back to a bare "Archived" for those.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('archive_reason', 20)->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archive_reason');
        });
    }
};
