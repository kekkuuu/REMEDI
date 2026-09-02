<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        \Illuminate\Support\Facades\Cache::forget('topbar_activity');
    }
}
