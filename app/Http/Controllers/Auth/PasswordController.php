<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * Answers in two shapes, like the rest of the app's state-changing
     * actions: JSON for the profile page's AJAX submit, the original redirect
     * for a plain post. A failed validation already returns JSON on its own
     * for an AJAX request — Laravel does that from the exception — so only the
     * success path needs branching here.
     */
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            // 'bail' + 'string': see ProfileController::destroy -- an array
            // reached password_verify() and threw.
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // must_change_password cleared here, not just set false by default --
        // this is the ONLY path off an admin-reset password (see
        // EnsureUserSetsNewPassword), so leaving it out would nag forever
        // after the account holder had already done the one thing asked of
        // them.
        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        // "Password changed: {name}" -- worded for AlertService's account
        // predicate so admins get a pop-up (2026-09-28, at the user's
        // request). It read "Changed own account password", which matched
        // nothing and was filed as a generic "Record updated".
        AuditTrail::log('Updated', "Password changed: {$request->user()->name}");

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Password updated. Use it the next time you sign in.',
            ]);
        }

        return back()->with('status', 'password-updated');
    }
}
