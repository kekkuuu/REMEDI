<?php

namespace App\Http\Controllers;

use App\Mail\SafeguardCodeMail;
use App\Models\AuditTrail;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Store-wide settings an admin sets through the UI. The one thing here so
 * far is the POS void passcode -- see SaleController::void() for how staff
 * spend it, and Setting for how it's stored.
 */
class SettingsController extends Controller
{
    /** Wrong CURRENT passcode / backup password guesses allowed per admin. */
    public const CURRENT_MAX_ATTEMPTS = 5;

    public const CURRENT_DECAY_SECONDS = 900;

    /** A "forgot the current one" code: how long it lives, how many may be sent. */
    public const FORGOT_CODE_TTL_MINUTES = 10;

    public const FORGOT_SEND_LIMIT = 3;

    public const FORGOT_SEND_DECAY_SECONDS = 600;

    /** What each "forgot" code stands in for, as the email and messages name it. */
    public const FORGOT_TARGETS = [
        'passcode' => 'POS void passcode',
        'backup' => 'backup password',
    ];

    public function edit()
    {
        return view('admin.settings.edit', [
            'voidPasscodeSet' => Setting::voidPasscodeIsSet(),
            'backupPasswordSet' => Setting::backupPasswordIsSet(),
            'backupPasswordSetAt' => Setting::backupPasswordSetAt(),
            'backupPasswordMin' => BackupController::MIN_PASSWORD_LENGTH,
            'forgotTtl' => self::FORGOT_CODE_TTL_MINUTES,
        ]);
    }

    /**
     * Set (or replace) the manager passcode staff enter to void a sale.
     * `confirmed` mirrors the field this app already asks a new password
     * twice for -- a 6-digit code mistyped once is now valid until an admin
     * notices, since nothing else would catch it.
     */
    public function updateVoidPasscode(Request $request)
    {
        $replacing = Setting::voidPasscodeIsSet();

        $validated = $request->validate([
            // Replacing asks for the CURRENT code first (2026-10-25, at the
            // user's request): the login-password gate proves an admin, this
            // proves they know the code they are replacing -- or hold the
            // code "Forgot it?" emailed them.
            'current_passcode' => $replacing ? ['bail', 'required', 'string'] : ['nullable'],
            'passcode' => ['required', 'digits:6', 'confirmed'],
        ], [
            'current_passcode.required' => 'Enter the current passcode.',
        ]);

        $viaEmail = false;
        if ($replacing && ($refusal = $this->checkCurrent($request, 'passcode', 'current_passcode',
            fn ($v) => Setting::checkVoidPasscode($v), 'The current passcode is incorrect.', $viaEmail))) {
            return $refusal;
        }

        Setting::setVoidPasscode($validated['passcode']);

        AuditTrail::log('Updated', 'Set the POS void passcode'.($viaEmail ? ' (with an emailed code)' : ''));

        return $this->actionOk($request, 'Void passcode updated.', redirect()->route('safeguard.edit'));
    }

    /**
     * Set or replace the password every full backup asks for and is locked
     * with -- see Setting::BACKUP_PASSWORD_KEY and BackupController::download().
     * Changing it does not touch backups already downloaded: each zip keeps
     * the password it was made with. Never written to the audit trail.
     */
    public function updateBackupPassword(Request $request)
    {
        $replacing = Setting::backupPasswordIsSet();

        $validated = $request->validate([
            'current_backup_password' => $replacing ? ['bail', 'required', 'string', 'max:128'] : ['nullable'],
            'backup_password' => ['bail', 'required', 'string', 'min:'.BackupController::MIN_PASSWORD_LENGTH, 'max:128', 'confirmed'],
        ], [
            'current_backup_password.required' => 'Enter the current backup password.',
            'backup_password.required' => 'Enter the backup password.',
            'backup_password.min' => 'Use at least :min characters for the backup password.',
            'backup_password.confirmed' => 'The two backup passwords do not match.',
        ]);

        $viaEmail = false;
        if ($replacing && ($refusal = $this->checkCurrent($request, 'backup', 'current_backup_password',
            fn ($v) => Setting::checkBackupPassword($v), 'The current backup password is incorrect.', $viaEmail))) {
            return $refusal;
        }

        Setting::setBackupPassword($validated['backup_password']);

        AuditTrail::log('Updated', ($replacing ? 'Changed the backup password' : 'Set the backup password')
            .($viaEmail ? ' (with an emailed code)' : ''));

        return $this->actionOk($request, 'Backup password saved. Every full backup now asks for it and is locked with it.', redirect()->route('safeguard.edit'));
    }

    /**
     * "Forgot the current one?" (2026-10-25, at the user's request): emails a
     * 6-digit code to the admin's personal address, accepted ONCE in the
     * matching "Current" box in place of the forgotten passcode or backup
     * password. Only its hash is kept, in this session; it expires after
     * FORGOT_CODE_TTL_MINUTES, a new one replaces the old, sending is limited,
     * and wrong guesses share checkCurrent()'s limit. The code never reaches
     * the audit trail -- only that one was sent.
     */
    public function sendForgotCode(Request $request, string $which)
    {
        abort_unless(isset(self::FORGOT_TARGETS[$which]), 404);
        $what = self::FORGOT_TARGETS[$which];
        $field = $which === 'passcode' ? 'current_passcode' : 'current_backup_password';
        $user = $request->user();

        $isSet = $which === 'passcode' ? Setting::voidPasscodeIsSet() : Setting::backupPasswordIsSet();
        if (! $isSet) {
            return $this->actionFailed($request, "No {$what} is set yet, so there is nothing to replace; set one directly.", $field);
        }

        if (trim((string) $user->personal_email) === '') {
            return $this->actionFailed($request, 'Your account has no personal email to send a code to. Add one in My Profile first.', $field);
        }

        $sendKey = 'safeguard-forgot-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($sendKey, self::FORGOT_SEND_LIMIT)) {
            return $this->actionFailed($request,
                'Too many codes requested. Try again in '.ceil(RateLimiter::availableIn($sendKey) / 60).' minute(s).', $field);
        }
        RateLimiter::hit($sendKey, self::FORGOT_SEND_DECAY_SECONDS);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($user->personal_email)->send(new SafeguardCodeMail($user, $code, $what, self::FORGOT_CODE_TTL_MINUTES));
        } catch (\Throwable $e) {
            // Never log the code itself, only that delivery failed.
            Log::error('[safeguard-code] Could not email the code: '.$e->getMessage());

            return $this->actionFailed($request, 'The code could not be emailed right now. Try again in a few minutes.', $field);
        }

        $request->session()->put("safeguard_code.{$which}", [
            'hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::FORGOT_CODE_TTL_MINUTES)->getTimestamp(),
        ]);

        AuditTrail::log('Requested', "Safeguard code sent: {$user->name} ({$what})");

        return $this->actionOk($request,
            "A 6-digit code was sent to your personal email. Enter it in the Current box within ".self::FORGOT_CODE_TTL_MINUTES.' minutes.',
            redirect()->route('safeguard.edit'));
    }

    /**
     * Check a CURRENT passcode / backup password -- or the code "Forgot it?"
     * emailed for it -- with wrong guesses limited per admin (shared by both
     * forms): six digits is a million combinations and nothing else here is
     * slow. Null when it is right; an emailed code is spent on use.
     */
    private function checkCurrent(Request $request, string $which, string $field, \Closure $isRight, string $wrong, bool &$viaEmail)
    {
        $key = 'safeguard-current:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, self::CURRENT_MAX_ATTEMPTS)) {
            return $this->actionFailed($request,
                'Too many wrong attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).', $field);
        }

        $value = (string) $request->input($field);

        if (! $isRight($value)) {
            $emailed = $request->session()->get("safeguard_code.{$which}");
            $viaEmail = is_array($emailed)
                && ($emailed['expires_at'] ?? 0) >= now()->getTimestamp()
                && Hash::check($value, $emailed['hash'] ?? '');

            if (! $viaEmail) {
                RateLimiter::hit($key, self::CURRENT_DECAY_SECONDS);

                return $this->actionFailed($request, $wrong, $field);
            }

            $request->session()->forget("safeguard_code.{$which}");
        }

        RateLimiter::clear($key);

        return null;
    }
}
