<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * The one place a text message leaves this app.
 *
 * There is no SMS account behind it yet, and that is deliberate rather than
 * unfinished: a gateway costs money per message and needs credentials only
 * the shop can create. So the DRIVER is config (`SMS_DRIVER`), and the
 * default one writes the message to the log instead of sending it -- the
 * admin OTP flow is then completely exercisable (and testable) today, and
 * turning on real delivery later is a new branch in send() plus credentials,
 * with nothing else in the app changing.
 *
 * Same shape as the mail situation this app already documents: MAIL_MAILER
 * points at a catcher that exists only on a dev machine, which is exactly why
 * "Forgot your password?" is an in-app flow rather than an emailed link.
 *
 * Callers must treat a false return as "not delivered" and say so. Never
 * report a code as sent because the function was called.
 */
class SmsService
{
    /**
     * Captured messages, and whether to capture instead of send.
     *
     * The same seam Laravel's own Mail::fake() provides, and tests need it
     * for a specific reason: the OTP is stored HASHED and the plain code
     * exists only inside the one request that sends it, so a test otherwise
     * has no way to read the code back short of brute-forcing bcrypt. That
     * is not a hole in the hashing -- it is why the hashing is worth having.
     */
    private static array $captured = [];

    private static bool $faking = false;

    /** Capture messages rather than sending them. Tests only. */
    public static function fake(): void
    {
        self::$faking = true;
        self::$captured = [];
    }

    /** Every message captured since fake(), oldest first: ['to' => , 'message' => ]. */
    public static function captured(): array
    {
        return self::$captured;
    }

    /** The most recent message body, or '' if nothing was captured. */
    public static function lastMessage(): string
    {
        $last = end(self::$captured);

        return $last ? $last['message'] : '';
    }

    /**
     * Send one message. Returns false when it could not be handed off --
     * a missing number, an unknown driver, or a gateway that refused.
     */
    public static function send(?string $to, string $message): bool
    {
        $to = trim((string) $to);

        if ($to === '') {
            return false;
        }

        if (self::$faking) {
            self::$captured[] = ['to' => $to, 'message' => $message];

            return true;
        }

        return match (config('sms.driver', 'log')) {
            'log' => self::sendViaLog($to, $message),
            default => false,
        };
    }

    /**
     * The no-gateway driver: record it and report success, so every screen
     * downstream behaves exactly as it will once a real gateway is wired in.
     *
     * The message body is written in full ON PURPOSE -- that is the only way
     * to read the code without a phone, and it is what makes the flow
     * demonstrable. It also means `storage/logs/laravel.log` can hand
     * somebody an admin password-reset code, so this driver belongs on a
     * development machine, not on a shop floor with real accounts on it.
     */
    private static function sendViaLog(string $to, string $message): bool
    {
        Log::info('[SMS:log] To '.$to.' -- '.$message);

        return true;
    }

    /**
     * Whether a real gateway is configured, i.e. whether a message sent from
     * here actually reaches a phone. The OTP screens read this to tell the
     * person where to look for the code, rather than claiming a text was
     * sent to a handset that will never ring.
     */
    public static function delivers(): bool
    {
        return config('sms.driver', 'log') !== 'log';
    }
}
