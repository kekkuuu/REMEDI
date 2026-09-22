<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
    ];

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
