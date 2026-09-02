<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Usage on routes:
     *   Route::get('/admin', ...)->middleware('role:admin');
     *   Route::get('/staff', ...)->middleware('role:admin,staff');  // multiple roles
     *
     * Role only. The deactivated-account check that used to live here has moved
     * to EnsureUserIsActive (`active`), because this middleware is attached to
     * the role-gated routes alone — so the check never ran for the staff-facing
     * half of the app, POS checkout included. Keeping a second copy here would
     * only recreate the two-implementations problem; `active` now rides with
     * `auth` on the whole group and runs first.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Unauthorized. Please log in.');
        }

        if (!in_array($user->role, $roles, true)) {
            $roleList = implode(' or ', array_map('ucfirst', $roles));
            abort(403, "Unauthorized. This page is for {$roleList} accounts only.");
        }

        return $next($request);
    }
}
