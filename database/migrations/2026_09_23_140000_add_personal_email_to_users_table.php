<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second, PERSONAL address per account, alongside the @remedi.com one.
 *
 * `users.email` is the work address an admin issues and the account signs in
 * with -- it is deliberately locked to the company domain on the Add User form
 * and cannot be edited by staff. That makes it useless for a password reset:
 * the whole point of "forgot your password" is that you cannot get into the
 * system, and the work address only exists inside it.
 *
 * This is where the admin's reset CODE is sent (see
 * PasswordResetRequestController), which is why it is nullable but meaningful:
 * an admin with none saved falls back to asking another admin, exactly as an
 * admin with no phone did before.
 *
 * NOT unique: two people may legitimately share a household address, and a
 * uniqueness clash here would block creating an account rather than protect
 * anything. `users.email` is the identity column and keeps its unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('personal_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('personal_email');
        });
    }
};
