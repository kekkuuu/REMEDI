<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set when a signed-out staff member uses "Forgot your password?"
            // and cleared the moment an admin resets it -- one pending request
            // per account, which is all a small pharmacy's staff list needs.
            $table->timestamp('password_reset_requested_at')->nullable()->after('remember_token');
            // Set alongside an admin reset (the default password is a KNOWN,
            // shared value -- staff123 -- so leaving it in force indefinitely
            // would make the reset barely more secure than no password at
            // all). Checked by EnsureUserSetsNewPassword on every authenticated
            // route except /profile itself, where the password gets changed.
            $table->boolean('must_change_password')->default(false)->after('password_reset_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_reset_requested_at', 'must_change_password']);
        });
    }
};
