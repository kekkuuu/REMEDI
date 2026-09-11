<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
public function store(Request $request): RedirectResponse|JsonResponse
{
    // Trimmed and lower-cased before validation, and that is not cosmetic:
    // the `lowercase` rule below REJECTS a capitalised address rather than
    // folding it, so "Emman@remedi.com" was refused -- and because a
    // js-confirm form reports failures through the confirm dialog, what the
    // admin actually saw was "That action could not be completed." with no
    // mention of the capital E. Nobody should be told off for typing a name
    // the way they write it. A stray trailing space came back the same way.
    $request->merge([
        'email' => strtolower(trim((string) $request->input('email'))),
    ]);

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
        // The form's Role select was being submitted and then dropped, so every
        // account created here came out as the column default (staff) however
        // the admin filled the form in. `in:admin,staff` matches the enum and
        // the rule UserController::update uses -- role is fillable, so a
        // tampered post would otherwise mass-assign anything.
        'role' => ['required', 'in:admin,staff'],
        'password' => ['required', 'confirmed', Rules\Password::defaults()],
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'role' => $validated['role'],
        'password' => Hash::make($validated['password']),
    ]);

    event(new Registered($user));

    // This route is the admin "Add User" form, not public signup, so account
    // creation is an administrative action and belongs in the trail beside the
    // update/toggle/delete entries UserController already writes. The role is
    // included because which accounts were granted admin is the part worth
    // being able to audit later.
    AuditTrail::log('Created', "Added user account: {$user->name} ({$user->role})");

    // No Auth::login() — admin stays logged in
    // No redirect to dashboard — go back to user list

    return $this->actionOk($request, "User \"{$user->name}\" added successfully.", redirect()->route('users.index'));
}
}
