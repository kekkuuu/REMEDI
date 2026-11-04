<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\NewAccountCredentialsMail;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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
     * @throws ValidationException
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
            'email' => $this->normalisedEmail($request),
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
            // REQUIRED here as of 2026-09-23, at the user's request, unlike
            // ProfileUpdateRequest's own phone field which stays nullable --
            // an admin creating an account has the person in front of them (or
            // on the phone) and can ask, whereas making it required on the
            // profile form would lock an existing account out of saving any
            // change until it supplied one. Same max as the column (40).
            'phone' => ['required', 'string', 'max:40'],
            // A PERSONAL inbox: where the sign-in details are sent, since the
            // @remedi.com address is only a login name with no mailbox behind it.
            // REQUIRED here since 2026-11-04, at the user's request, because the
            // account cannot be handed over without it. Not `unique` -- two people
            // may share a household address, and `users.email` is the identity
            // column.
            'personal_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        // The system chooses the password, and only its hash is kept. Letters and
        // digits only: nothing a mail client or a phone keyboard mangles.
        $temporaryPassword = Str::password(16, true, true, false);

        // The mail goes out inside the transaction: if it cannot be sent there is
        // no account, rather than a login nobody was ever told about.
        try {
            $user = DB::transaction(function () use ($validated, $temporaryPassword) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'role' => $validated['role'],
                    'phone' => $validated['phone'],
                    'personal_email' => $validated['personal_email'],
                    'password' => Hash::make($temporaryPassword),
                ]);

                // A temporary password, sent by email: the account holder chooses
                // their own at first sign-in.
                $user->forceFill(['must_change_password' => true])->save();

                Mail::to($validated['personal_email'])->send(
                    new NewAccountCredentialsMail($user, $temporaryPassword, route('login'))
                );

                return $user;
            });
        } catch (Throwable $e) {
            report($e);

            return $this->actionFailed(
                $request,
                'The sign-in details could not be emailed, so the account was not created. Check the personal email and try again.',
                'personal_email'
            );
        }

        event(new Registered($user));

        // This route is the admin "Add User" form, not public signup, so account
        // creation is an administrative action and belongs in the trail beside the
        // update/toggle/delete entries UserController already writes. The role is
        // included because which accounts were granted admin is the part worth
        // being able to audit later.
        AuditTrail::log('Created', "Added user account: {$user->name} ({$user->role})");

        // No Auth::login() — admin stays logged in
        // No redirect to dashboard — go back to user list

        return $this->actionOk($request, "User \"{$user->name}\" added. Their sign-in details were emailed to {$user->personal_email}, and they will set their own password the first time they sign in.", redirect()->route('users.index'));
    }
}
