<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,

            // Real rows from the audit trail for this account. AuditTrail::log()
            // resolves the acting user itself, so filtering on user_id gives
            // exactly what this person did -- no fabricated "Successful Login"
            // entries, which the app does not record.
            'recentActivity' => AuditTrail::where('user_id', $user->id)
                ->latest()
                ->take(6)
                ->get(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request)
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // Branching here rather than through actionOk(): the non-AJAX path has
        // its own banner keyed on session('status'), and actionOk would also
        // set a 'success' flash, so the page would show the same message twice.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Profile updated.']);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's own account.
     *
     * The Delete Account card was removed from profile/edit.blade.php by
     * request, but THIS ROUTE IS STILL LIVE — and it was the stock Breeze
     * method, with no guards at all. That made it a second door onto account
     * deletion which honoured none of the rules User Management enforces:
     *
     *  - It could remove the last admin. `/users` and `/register` are both
     *    behind `role:admin`, so an admin deleting themselves with no other
     *    active admin left leaves the system permanently unadministrable —
     *    nobody can create a replacement through the UI. Verified: a throwaway
     *    admin deleted itself here and the admin count went 2 -> 1.
     *  - It ignored the sales guard. `sales.user_id` is RESTRICT (migration
     *    2026_08_24_000001), so a cashier with transactions got an uncaught FK
     *    violation — a 500, AFTER Auth::logout() had already run, leaving them
     *    signed out staring at an error page with the account still there.
     *    Before that migration this same path silently destroyed their sales.
     *  - It wrote nothing to the audit trail. Every other account mutation logs.
     *
     * Guards mirror UserController::destroy deliberately: the rules must not
     * depend on which door you came through. Keeping them here is also what
     * makes the note in profile/edit.blade.php true — restoring the card really
     * is re-adding the card, not rebuilding the safety around it.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $salesCount = $user->sales()->count();

        if ($salesCount > 0) {
            return back()->withErrors([
                'password' => 'Your account has '.number_format($salesCount).' '
                    .str('sale')->plural($salesCount).' recorded and cannot be deleted — those '
                    .'transactions are part of the sales record. Ask an administrator to '
                    .'deactivate the account instead.',
            ], 'userDeletion');
        }

        // Count OTHER admins who could still sign in. A deactivated admin is no
        // use here: they cannot log in, so leaving one behind is the same as
        // leaving none.
        $otherActiveAdmins = User::where('role', 'admin')
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($user->isAdmin() && $otherActiveAdmins === 0) {
            return back()->withErrors([
                'password' => 'This is the only active administrator account. Deleting it would '
                    .'leave no one able to manage users, products or reports. Promote another '
                    .'account to Admin first.',
            ], 'userDeletion');
        }

        $name = $user->name;

        // Log and delete together. The audit row has to be written while the
        // user is still authenticated, or AuditTrail::log() resolves nobody and
        // records "System"; but it must not survive a delete that fails. A
        // transaction gives both — and audit_trails.user_id is ON DELETE SET
        // NULL, so the row keeps its denormalised username afterwards.
        DB::transaction(function () use ($user, $name) {
            AuditTrail::log('Deleted', "Deleted own user account: {$name}");

            // Log out BEFORE deleting, and inside the transaction.
            //
            // SessionGuard::logout() calls cycleRememberToken() whenever the
            // account has a non-empty remember_token, and that ends in
            // $user->save(). Run it AFTER the delete -- which is where it used
            // to sit, and where stock Breeze puts it -- and `exists` is already
            // false, so Eloquent treats the save as an INSERT and writes the
            // row straight back with its original id and a fresh token.
            //
            // The account therefore came back from the dead while the audit
            // trail recorded "Deleted own user account: X", the session ended,
            // and the user was redirected to / as though it had worked. An
            // audit entry asserting something that did not happen is the exact
            // failure this controller already guards against elsewhere.
            //
            // Ordering it before the delete makes that same save an UPDATE on a
            // row that still exists, which the delete then removes.
            //
            // Only reachable with a remember_token, so neither seeded account
            // triggers it today and the login form has no "remember me" box --
            // but LoginRequest already honours $this->boolean('remember'), so
            // it is one form field away from being live.
            Auth::logout();

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
