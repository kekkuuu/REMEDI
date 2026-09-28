<?php

namespace App\Support;

/**
 * Whether this session re-typed its login password recently enough that a
 * `password.confirm` route will let it straight through.
 *
 * The one definition of that test for the VIEWS: links to gated routes
 * (Safeguard, Backup Database) carry `data-password-gate="required|confirmed"`
 * from it, and the layout's pop-up opens only for `required`. It mirrors the
 * RequirePassword middleware's own check -- the session stamp against
 * `auth.password_timeout` -- and the middleware stays the real guard; a stale
 * answer here only costs one extra round trip through the confirm page.
 */
class PasswordGate
{
    public static function confirmed(): bool
    {
        $at = (int) session('auth.password_confirmed_at', 0);

        return $at > 0 && (time() - $at) < (int) config('auth.password_timeout', 10800);
    }

    /** The attribute value the layout's pop-up script reads. */
    public static function state(): string
    {
        return self::confirmed() ? 'confirmed' : 'required';
    }
}
