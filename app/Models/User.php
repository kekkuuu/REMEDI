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
