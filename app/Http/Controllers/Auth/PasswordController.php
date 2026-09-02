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
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        AuditTrail::log('Updated', 'Changed own account password');

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Password updated. Use it the next time you sign in.',
            ]);
        }

        return back()->with('status', 'password-updated');
    }
}
