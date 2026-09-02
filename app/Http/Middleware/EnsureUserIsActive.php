<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user whose account has been deactivated.
 *
 * This has to run on EVERY authenticated route, which is why it is its own
 * middleware rather than living inside EnsureUserHasRole.
 *
 * The check used to sit in EnsureUserHasRole, and that middleware is only
 * attached to the `role:admin` group. Everything staff actually use — the
 * dashboard, the whole POS including `POST /pos/checkout`, inventory, sales
 * history, notifications and profile — sits in the plain `auth` group, so the
 * check never ran for them. LoginRequest blocks an inactive user from signing
 * in, but an ALREADY SIGNED IN staff member kept working indefinitely after
 * being deactivated: verified by deactivating a live session and then ringing
 * up a real sale through it, receipt and all.
 *
 * That inverts what the control is for. "Deactivate" is what an owner reaches
 * for when someone is dismissed or an account is compromised, and it has to
 * take effect on the next request — not whenever the session happens to lapse.
 *
 * Ordering: registered alongside `auth` and BEFORE `role`, so a deactivated
 * admin is logged out rather than shown a 403 about permissions they no longer
 * have. It reads the user the guard already resolved, so it must sit after
 * `auth`.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Same wording LoginRequest uses when it refuses a sign-in. Both
            // paths describe one condition, so they read from one key rather
            // than two hardcoded strings that drift apart.
            $message = trans('auth.deactivated');

            // The POS checks out over fetch, and the bell polls /alerts every
            // 30s. Handing those a 302 to the login page means the JSON parse
            // fails and the register looks merely broken rather than signed
            // out. 401 is what the bell's poll loop already stops on.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'error' => $message], 401);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
