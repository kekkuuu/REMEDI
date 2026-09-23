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
 * every surface, not just full page loads.
 *
 * `/profile*` is exempt only for SAFE (GET/HEAD) requests -- enough to render
 * the page the password form lives on, and nothing more. The exemption used
 * to cover the whole path, which let a locked account WRITE to itself:
 * `PATCH /profile` still went through, so somebody holding the shared default
 * password could edit that account's name and phone without ever setting a
 * new one. The phone is the part that makes it more than untidy -- it is
 * where an admin's SMS reset code is sent (PasswordResetRequestController),
 * so pointing it at another handset turns a temporary password into a
 * permanent way in. Changing the password itself is unaffected: that posts to
 * PUT /password, which lives in routes/auth.php's own group and never passed
 * through here.
 */
class EnsureUserSetsNewPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Reading the profile page is allowed; writing to it is not.
        $isProfileRead = $request->is('profile*') && $request->isMethodSafe();

        if ($user && $user->must_change_password && ! $isProfileRead) {
            $message = 'You must set a new password before continuing.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => $message], 423);
            }

            return redirect()->route('profile.edit')->with('status', 'must-change-password');
        }

        return $next($request);
    }
}
