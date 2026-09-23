<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS one-time codes for the ADMIN half of "Forgot your password?".
 *
 * Staff keep the in-app request-an-admin flow (there is always an admin above
 * a cashier to ask). An admin has nobody above them, so they verify a 6-digit
 * code texted to the number on their own account instead -- see
 * PasswordResetRequestController and App\Services\SmsService.
 *
 * The code is stored HASHED, exactly like the POS void passcode
 * (Setting::setVoidPasscode) and a password: these three columns are the only
 * thing standing between an email address and an admin account, so a readable
 * code in a database dump would be the whole credential. `attempts` is what
 * stops the 6 digits being guessed by brute force -- a million tries is
 * nothing without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_otp_hash')->nullable()->after('password_reset_requested_at');
            $table->timestamp('password_otp_expires_at')->nullable()->after('password_otp_hash');
            $table->unsignedTinyInteger('password_otp_attempts')->default(0)->after('password_otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_otp_hash', 'password_otp_expires_at', 'password_otp_attempts']);
        });
    }
};
