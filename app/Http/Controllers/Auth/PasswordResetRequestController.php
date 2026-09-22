<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Forgot your password?" for an app with no working mail delivery
 * (MAIL_MAILER points at a local mailpit catcher -- see CLAUDE.md).
 * Breeze's own token-by-email flow (PasswordResetLinkController /
 * NewPasswordController, still registered but unreachable from any view)
 * can't deliver anything here, so this replaces it on the same two routes
 * with an in-app request an ADMIN resolves instead of an email: a signed-
 * out account flags itself as locked out, and User Management shows the
 * admin exactly who's waiting (see UserController::resetPassword() and
 * admin/users/_rows.blade.php).
 */
class PasswordResetRequestController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        // Trim+lowercase before validating, same as every other email write
        // path in the app (RegisteredUserController, UserController::update)
        // -- the `lowercase` rule below REJECTS a capitalised address rather
        // than folding it.
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            // whereNull('archived_at'): an archived account can't sign in
            // regardless (see User::DELETED_AT), so there's nothing here
            // for a reset to unlock -- same "validation that names a row
            // must ask for an active one" rule as checkout and category
            // validation elsewhere.
            'email' => ['required', 'string', 'lowercase', 'email', Rule::exists('users', 'email')->whereNull('archived_at')],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        // Idempotent: a second click while a request is already pending
        // doesn't reset the clock or write a second audit row (and a second
        // toast) for the same wait. The admin still sees exactly one row to
        // act on.
        if (! $user->password_reset_requested_at) {
            $user->password_reset_requested_at = now();
            $user->save();

            // No authenticated actor -- this is the whole point of the flow
            // -- so AuditTrail::log() falls back to "System" for username,
            // same as any scheduled command. The bell/toast still name the
            // account by reading $user->name out of the details string.
            AuditTrail::log('Requested', "Password reset requested: {$user->name}");
        }

        // One message regardless of whether the request was already
        // pending, so a second click reads as "still working on it" rather
        // than an error.
        return back()->with('status', 'If that account exists, an admin has been notified and will reset your password.');
    }
}
