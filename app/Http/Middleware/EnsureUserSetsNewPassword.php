<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks an account still on its admin-reset default password (see
 * UserController::resetPassword(), User::DEFAULT_RESET_PASSWORD) out of
 * everything except the Profile page, until it sets its own.
 *
 * `staff123` is a known, shared value the moment it exists in this file and
 * in the admin's head -- leaving it valid indefinitely would make "the admin
 * reset it" barely different from having no password check at all. This is
 * the enforcement half of that; the reset itself only sets the flag.
 *
 * A hard block, same rigor as EnsureUserIsActive: AJAX/JSON requests (the
 * bell's poll, POS checkout) are refused with 423 Locked rather than let
 * through, because "you can't enter the system unless you change it" means
 * every surface, not just full page loads. `/profile*` is exempt -- that's
 * where the password form lives, and it must stay reachable by every
 * request shape (the AJAX Change Password submit included) or there is no
 * way off this screen at all.
 */
class EnsureUserSetsNewPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->is('profile*')) {
            $message = 'You must set a new password before continuing.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => $message], 423);
            }

            return redirect()->route('profile.edit')->with('status', 'must-change-password');
        }

        return $next($request);
    }
}
