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

        if (!$user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated.',
            ]);
        }

        return $next($request);
    }
}
