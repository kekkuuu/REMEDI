<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Forgot your password?" for an app with no working mail delivery
 * (MAIL_MAILER points at a local mailpit catcher -- see CLAUDE.md).
 * Breeze's own token-by-email flow (PasswordResetLinkController /
 * NewPasswordController, still registered but unreachable from any view)
 * can't deliver anything here.
 *
 * It forks on ROLE, because the two roles have genuinely different problems:
 *
 * - STAFF ask an admin. There is always an admin above a cashier, so the
 *   account flags itself and User Management shows who is waiting (see
 *   UserController::resetPassword()). Unchanged, and still the default for
 *   anything that is not a reachable admin.
 *
 * - ADMINS have nobody above them, so waiting for "an admin" is circular --
 *   on a one-admin shop it is a lockout. They get a 6-digit code texted to
 *   the number on their own account, verify it, and set a new password
 *   themselves. Possession of the registered handset is what stands in for
 *   the authority a staff request borrows from an admin.
 *
 * An admin with NO phone number on file falls back to the staff path rather
 * than dead-ending: another admin can still reset them. That is a real gap on
 * a single-admin install with no number saved, and the honest fix is to save
 * a number, which is why the message says so.
 *
 * THREE THINGS HOLD THIS UP and none is optional. The code is stored hashed
 * (User::issuePasswordOtp); it expires (OTP_TTL_MINUTES) and is burned after
 * OTP_MAX_ATTEMPTS wrong guesses, since six digits is a million combinations
 * and nothing else here is slow enough to matter; and sending is rate limited
 * per email, because every text costs money once a real gateway is wired in
 * and an unlimited send endpoint is both a bill and a way to flood somebody's
 * phone.
 *
 * The verified identity rides in the SESSION between the three steps, never
 * in the URL or a form field -- a user id in a query string would let anyone
 * skip straight to "set a new password" for any account they can name.
 */
class PasswordResetRequestController extends Controller
{
    /** Session keys for the half-finished reset. */
    private const SESSION_EMAIL = 'password_otp.email';

    private const SESSION_VERIFIED = 'password_otp.verified';

    /** Sends allowed per email before it has to wait. */
    private const SEND_LIMIT = 5;

    private const SEND_DECAY_SECONDS = 600;

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

        $email = (string) $request->input('email');
        $user = User::where('email', $email)->first();

        // An admin with a PERSONAL address on file proves themselves by
        // emailed code; everyone else asks an admin. isAdmin() alone is not
        // the test -- an address is what makes the code path possible at all,
        // and it must be the personal one: `users.email` is the @remedi.com
        // account they cannot currently get into.
        if ($user->isAdmin() && trim((string) $user->personal_email) !== '') {
            return $this->sendOtp($request, $user);
        }

        if ($user->isAdmin()) {
            // Say which of the two things is missing. "An admin has been
            // notified" would be an odd thing to tell the admin, and would
            // hide the reason the email never arrived.
            $this->flagForAdmin($user);

            return back()->with('status', 'No personal email is saved on this admin account, so no code could be sent. Another admin can reset it for you from User Management.');
        }

        $this->flagForAdmin($user);

        return back()->with('status', 'If that account exists, an admin has been notified and will reset your password.');
    }

    /** The staff path, unchanged: flag the account and let the bell do the rest. */
    private function flagForAdmin(User $user): void
    {
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
    }

    /**
     * The admin path: mint a code, text it, and move to the verify screen.
     *
     * $isResend only changes how it SPEAKS, never what it does -- a resend is
     * the same act, and deliberately shares the one rate limiter so that
     * pressing the button on the code screen cannot buy sends the email form
     * would have refused. The error is keyed to a field the page in question
     * actually has: the first screen has an email box, the code screen does
     * not.
     */
    private function sendOtp(Request $request, User $user, bool $isResend = false): RedirectResponse
    {
        $key = 'password-otp:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::SEND_LIMIT)) {
            throw ValidationException::withMessages([
                $isResend ? 'code' : 'email' => 'Too many codes requested. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        RateLimiter::hit($key, self::SEND_DECAY_SECONDS);

        $code = $user->issuePasswordOtp();

        // Mail::send throws on a refused connection or bad credentials, and a
        // login screen must not 500 because the mail host is having a bad day
        // -- the caller needs a false it can explain, same contract the SMS
        // driver had.
        $sent = true;
        try {
            Mail::to($user->personal_email)->send(
                new PasswordResetCodeMail($user, $code, User::OTP_TTL_MINUTES)
            );
        } catch (\Throwable $e) {
            // Never log the code itself, only that delivery failed.
            Log::error('[password-otp] Could not email the reset code: '.$e->getMessage());
            $sent = false;
        }

        // The code itself is never written to the audit trail -- the trail is
        // readable by every admin, which would make it a way to take over
        // somebody else's account rather than a record of what happened.
        AuditTrail::log('Requested', "Password reset code sent: {$user->name}");

        $request->session()->put(self::SESSION_EMAIL, $user->email);
        $request->session()->forget(self::SESSION_VERIFIED);

        if (! $sent) {
            return back()->with('status', 'The code could not be emailed to the address on file. Check it, or ask another admin to reset it from User Management.');
        }

        return redirect()->route('password.otp')->with('status', $isResend
            // Say the old one died. Issuing a code replaces the outstanding
            // one (User::issuePasswordOtp), so somebody who resent while the
            // first email was still in flight would otherwise keep trying the
            // code that arrived first and wonder why it is refused.
            ? 'A new code has been sent. The previous code no longer works.'
            : 'A 6-digit code has been sent to the personal email on this account.');
    }

    /**
     * Resend to the account the session is already holding -- no retyping an
     * email address, which is what the old "send a new code" link cost.
     *
     * It re-reads the account rather than trusting the session for anything
     * but the address, so an account that lost its number, its admin role or
     * its active flag mid-flow cannot be texted by pressing a button on a
     * page that was rendered before any of that changed.
     */
    public function resendOtp(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user || ! $user->isAdmin() || trim((string) $user->personal_email) === '') {
            return redirect()->route('password.request');
        }

        return $this->sendOtp($request, $user, true);
    }

    /** Step 2: type the code. */
    public function showOtpForm(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('password.request');
        }

        return view('auth.forgot-password-otp', [
            // Enough to confirm the code went somewhere expected, without
            // printing the whole address to whoever typed the work email.
            'maskedEmail' => $this->maskEmail($user->personal_email),
            // `log` writes the message to storage/logs/laravel.log instead of
            // sending it, so the screen must say so rather than claim an email
            // reached an inbox that will never see one. Reads config, so the
            // notice removes itself the moment a real mailer is configured.
            'mailDelivers' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('password.request');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $user->checkPasswordOtp((string) $request->input('code'))) {
            // One message for wrong, expired and burned alike: which of the
            // three it was is information a guesser can use to tune the next
            // attempt, and the person who genuinely has the phone needs the
            // same next step either way.
            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has expired. Request a new one.',
            ]);
        }

        // Only now is the session allowed near the password form. Regenerate
        // first so a session id captured before verification cannot be
        // replayed as a verified one.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_EMAIL, $user->email);
        $request->session()->put(self::SESSION_VERIFIED, true);

        return redirect()->route('password.otp.reset');
    }

    /** Step 3: set the new password. */
    public function showResetForm(Request $request): View|RedirectResponse
    {
        if (! $this->verifiedUser($request)) {
            return redirect()->route('password.request');
        }

        return view('auth.forgot-password-reset');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $user = $this->verifiedUser($request);

        if (! $user) {
            return redirect()->route('password.request');
        }

        $request->validate([
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            // They chose this password themselves, so there is nothing to
            // force a change of -- unlike UserController::resetPassword(),
            // which hands out a known shared default and must set the flag.
            'must_change_password' => false,
            'password_reset_requested_at' => null,
        ])->save();

        $user->clearPasswordOtp();

        AuditTrail::log('Reset', "Password reset by SMS code: {$user->name}");

        $request->session()->forget([self::SESSION_EMAIL, self::SESSION_VERIFIED]);
        // A fresh id again: the reset is finished, and nothing that happens
        // next should be able to reuse the session that was allowed to do it.
        $request->session()->regenerate();

        return redirect()->route('login')->with('status', 'Your password has been changed. Sign in with your new password.');
    }

    /**
     * The account a half-finished reset belongs to, re-read from the database
     * every time. The session carries an email, never a user object or a role
     * -- so an account archived or deactivated mid-flow stops resolving here
     * and the flow ends, rather than running on a snapshot taken before.
     */
    private function pendingUser(Request $request): ?User
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        if (! $email) {
            return null;
        }

        return User::where('email', $email)->where('is_active', true)->first();
    }

    /** As above, but only once the code has actually been verified. */
    private function verifiedUser(Request $request): ?User
    {
        if (! $request->session()->get(self::SESSION_VERIFIED)) {
            return null;
        }

        return $this->pendingUser($request);
    }

    /**
     * "red.remediii@gmail.com" -> "r•••••••••i@gmail.com": enough for the
     * owner to recognise their own address, not enough for a stranger who
     * typed somebody's work email to learn where the code just went.
     *
     * The DOMAIN is left intact deliberately -- it is the part that tells you
     * which inbox to go and look in, and it gives away far less than the local
     * part does.
     */
    private function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        $at = strpos($email, '@');

        if ($at === false || $at < 1) {
            return 'the address on file';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (strlen($local) <= 2) {
            return str_repeat('•', strlen($local)).$domain;
        }

        return $local[0].str_repeat('•', strlen($local) - 2).$local[strlen($local) - 1].$domain;
    }
}
