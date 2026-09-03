<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AuditTrail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'role',
        'action',
        'details',
        // Without this, create() silently drops the value mass-assignment
        // protection does not recognise -- the column filled with NULLs while
        // log() looked correct.
        'ip_address',
    ];

    /**
     * Every action this app writes, and the ONE list the filter renders from.
     *
     * The filter dropdown used to carry its own hand-typed list —
     * `['Login','Logout','Viewed','Create','Update','Delete']` — while log()
     * has always been called with the PAST TENSE: `Created`, `Updated`,
     * `Deleted`. `AuditTrailController::applyFilters()` matches the column
     * exactly, so three of the six options could never match a row:
     *
     *   ?action=Create  -> 0 of 109 rows
     *   ?action=Update  -> 0 of 35
     *   ?action=Delete  -> 0 of 12
     *
     * Nothing errored and the page rendered normally, so it read as "the app
     * does not record this" — which is how creating, deactivating and deleting
     * user accounts all appeared to go unlogged when every one of them was in
     * the table the whole time. Login/Logout/Viewed matched by luck: those
     * three are already the tense log() is called with.
     *
     * Rendering the control from this constant is what stops it drifting again.
     * Add an action here when you start writing it.
     */
    public const ACTIONS = ['Login', 'Logout', 'Viewed', 'Created', 'Updated', 'Deleted'];

    /**
     * The superseded spellings, so an old bookmark or a stale link still finds
     * its rows instead of quietly answering "nothing ever happened".
     */
    public const ACTION_ALIASES = ['Create' => 'Created', 'Update' => 'Updated', 'Delete' => 'Deleted'];

    /** Resolve a requested action to the spelling actually stored. */
    public static function canonicalAction(?string $action): ?string
    {
        if (blank($action)) {
            return null;
        }

        return self::ACTION_ALIASES[$action] ?? $action;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper to quickly log an action from anywhere in the app
    public static function log(string $action, string $details): void
    {
        $user = auth()->user();

        self::create([
            'user_id' => $user?->id,
            'username' => $user?->name ?? 'System',
            'role' => $user?->role ?? 'system',
            'action' => $action,
            'details' => $details,
            // Null for anything the scheduler or an artisan command logs --
            // there is no request behind those, so there is no address.
            'ip_address' => request()?->ip(),
        ]);

        // The bell's System/Updates tabs are built from these rows and cached
        // for AlertService::TTL_SECONDS. Every write here is, by definition, a
        // new notification, so retire that cache rather than showing a feed
        // that is missing the thing the user just did.
        Cache::forget('topbar_activity');
    }
}
