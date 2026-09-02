<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditTrail;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        // Stamp the sign-in so My Profile can show a real "Last login" rather
        // than a placeholder. forceFill + saveQuietly: this is bookkeeping, not
        // a profile change, so it should not fire model events or touch
        // updated_at semantics that other code reads.
        auth()->user()->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditTrail::log('Login', auth()->user()->name.' logged in');

        // Tell the next page -- normally the dashboard -- that this is an
        // arrival, not a revisit.
        //
        // The dashboard's branded loading screen is for the moment you sign
        // in, when there is nothing on screen yet and the wait needs
        // explaining. Someone who is already working in the app and clicks
        // Dashboard does not need to be told what the app is; they get the
        // skeleton and nothing else. Flash data is exactly one request long,
        // which is exactly how long this should last -- a refresh a second
        // later is a revisit and correctly gets the quiet version.
        session()->flash('remedi.just_signed_in', true);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (auth()->check()) {
            AuditTrail::log('Logout', auth()->user()->name.' logged out');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
