<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile fields the My Profile screen shows.
 *
 * These were rendered as static placeholders while the columns did not exist,
 * which meant the page displayed a phone number and department that belonged
 * to nobody. Real columns instead, editable by the account holder, so what the
 * page shows is what the account actually holds.
 *
 * last_login_at is written by AuthenticatedSessionController; audit_trails
 * gains the IP so Account Activity can show where an action came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('department')->nullable()->after('phone');
            $table->string('preferred_language', 40)->default('English')->after('department');
            $table->timestamp('last_login_at')->nullable()->after('preferred_language');
        });

        Schema::table('audit_trails', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'department', 'preferred_language', 'last_login_at']);
        });

        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
