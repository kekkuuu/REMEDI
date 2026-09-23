<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Archived, not deleted -- see Product::DELETED_AT.
     *
     * For accounts this also IS the sign-in block: the auth provider loads a
     * user through the ordinary query, which the soft-delete scope filters, so
     * an archived account cannot log in and a session it already holds stops
     * resolving to a user on its next request. Sales it rang up keep their
     * cashier through Sale::user()'s withTrashed().
     */
    public const DELETED_AT = 'archived_at';

    /**
     * Every reason User Management may record for archiving an account, and
     * the one place the label is written down -- the validation rule in
     * UserController::destroy() and the badge in admin/users/_rows.blade.php
     * both read this rather than each hand-typing the two strings.
     */
    public const ARCHIVE_REASONS = [
        'resigned' => 'Resigned',
        'fired' => 'Fired',
    ];

    /**
     * The password an admin resets a "forgot password" request to --
     * UserController::resetPassword() and the request form both read this,
     * so the value is written down once. A known, shared default is only
     * safe because it is temporary: resetting also sets must_change_password,
     * which EnsureUserSetsNewPassword enforces on the very next request.
     */
    public const DEFAULT_RESET_PASSWORD = 'staff123';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'department',
        'preferred_language',
        'password',
        'role',
        'is_active',
        'last_login_at',
        'archive_reason',
        'password_reset_requested_at',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password_reset_requested_at' => 'datetime',
        'must_change_password' => 'boolean',
        'password_otp_expires_at' => 'datetime',
    ];

    /** How long a texted reset code stays usable. */
    public const OTP_TTL_MINUTES = 10;

    /** Wrong guesses allowed before the code is burned and must be re-sent. */
    public const OTP_MAX_ATTEMPTS = 5;

    /**
     * Display id for the profile screen: ADM-001 / STF-004.
     *
     * Derived from the primary key rather than stored, so it can never drift
     * out of sync with the row it names.
     */
    public function getStaffCodeAttribute(): string
    {
        return ($this->isAdmin() ? 'ADM-' : 'STF-').str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /** "Resigned" / "Fired", or null for an account with no reason on record
     *  (archived before this existed, or never archived at all). */
    public function getArchiveReasonLabelAttribute(): ?string
    {
        return self::ARCHIVE_REASONS[$this->archive_reason] ?? null;
    }

    // Relationship: a user (staff/admin) can have many sales transactions
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Mint a 6-digit SMS reset code, store only its HASH, and return the
     * plain code for the one caller that has to put it in a message.
     *
     * `random_int` rather than `rand`/`mt_rand`: this is a credential, and
     * the others are predictable from prior output. Issuing a new code
     * REPLACES any code already outstanding and zeroes the attempt counter,
     * so a re-send is a clean slate and the old code stops working the
     * instant a new one is asked for -- otherwise two live codes would double
     * the guessing surface.
     */
    public function issuePasswordOtp(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'password_otp_hash' => Hash::make($code),
            'password_otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'password_otp_attempts' => 0,
        ])->save();

        return $code;
    }

    /** Is there a code outstanding that has not expired? */
    public function hasLivePasswordOtp(): bool
    {
        return $this->password_otp_hash
            && $this->password_otp_expires_at
            && $this->password_otp_expires_at->isFuture();
    }

    /**
     * Check a typed code, counting the failures.
     *
     * A wrong guess increments the counter and, at OTP_MAX_ATTEMPTS, clears
     * the code entirely -- 6 digits is a million combinations and nothing
     * else here is slow enough to make that a deterrent on its own. An
     * expired code is cleared rather than merely refused, so a stale hash
     * cannot sit on the row indefinitely.
     */
    public function checkPasswordOtp(string $code): bool
    {
        if (! $this->hasLivePasswordOtp()) {
            $this->clearPasswordOtp();

            return false;
        }

        if ($this->password_otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            $this->clearPasswordOtp();

            return false;
        }

        if (! Hash::check($code, $this->password_otp_hash)) {
            $this->increment('password_otp_attempts');

            if ($this->fresh()->password_otp_attempts >= self::OTP_MAX_ATTEMPTS) {
                $this->clearPasswordOtp();
            }

            return false;
        }

        return true;
    }

    /** Burn the code. Called on use, on expiry and on too many guesses. */
    public function clearPasswordOtp(): void
    {
        $this->forceFill([
            'password_otp_hash' => null,
            'password_otp_expires_at' => null,
            'password_otp_attempts' => 0,
        ])->save();
    }

    // Helper methods for role checking
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }
}
