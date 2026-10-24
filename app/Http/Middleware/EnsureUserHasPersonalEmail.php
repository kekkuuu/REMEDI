<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an account with no personal email to My Profile, where a pop-up asks
 * for it, until one is saved (2026-10-23, at the user's request).
 *
 * An admin may create an account without one (Add User keeps it optional --
 * the admin may not have it at the counter), so the account holder supplies
 * it themselves the first time they sign in. It is where their own password
 * reset code is emailed ("Forgot your password?"), so an account without one
 * cannot reset itself.
 *
 * Same shape as EnsureUserSetsNewPassword, and it runs after it: an account
 * still on a temporary password sets that first (its profile is read-only
 * until then). A hard block -- AJAX/JSON answers 423, not let through -- but
 * the two profile routes stay open, since that is where the address is saved.
 * The page the person was headed for is remembered, so saving it takes them
 * there.
 */
class EnsureUserHasPersonalEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->must_change_password && trim((string) $user->personal_email) === ''
            && ! $request->routeIs('profile.edit', 'profile.update')) {
            $message = 'Add your personal email in My Profile before continuing.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => $message], 423);
            }

            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('profile.edit');
        }

        return $next($request);
    }
}
