<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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

    /**
     * Stop capturing. Tests\TestCase calls this before EVERY test.
     *
     * These two are static, and PHPUnit runs a whole suite in one process --
     * so without a reset, one class calling fake() silently leaves every
     * later test faking too. That cost a run of eight "failures" in
     * SmsServiceTest that passed perfectly on their own: send() was
     * short-circuiting into the capture branch, so the gateway was never
     * called and every refusal came back as a success.
     */
    public static function stopFaking(): void
    {
        self::$faking = false;
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
            'semaphore' => self::sendViaSemaphore(self::normalisePhNumber($to), $message),
            default => false,
        };
    }

    /**
     * Semaphore (semaphore.co), the usual Philippine gateway.
     *
     * NOTHING here logs the message body, unlike the log driver: that body
     * carries the admin's reset code, and a gateway is what gets configured
     * on a real shop floor where the log is not a private dev file. Failures
     * record the status and the gateway's own error, never the text.
     *
     * A 200 does NOT mean delivered -- Semaphore answers with an array of
     * message objects, each carrying its own status, and a message it refuses
     * (bad number, no credits) can still arrive inside a 200. Anything that
     * comes back "Failed" or "Refunded" is therefore treated as not sent, so
     * the caller tells the person to try another way rather than leaving them
     * watching a phone that will never ring.
     */
    private static function sendViaSemaphore(string $to, string $message): bool
    {
        $key = trim((string) config('sms.semaphore.key'));

        if ($key === '') {
            Log::error('[SMS:semaphore] SMS_DRIVER=semaphore but SEMAPHORE_API_KEY is empty -- nothing was sent.');

            return false;
        }

        $payload = [
            'apikey' => $key,
            'number' => $to,
            'message' => $message,
        ];

        /* Only sent when explicitly configured. Semaphore REJECTS a sender
           name that has not been registered and approved on the account, so
           defaulting this to the store name would fail every message on a
           fresh account -- the blank default lets Semaphore use its own. */
        $sender = trim((string) config('sms.semaphore.sender_name'));
        if ($sender !== '') {
            $payload['sendername'] = $sender;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post('https://api.semaphore.co/api/v4/messages', $payload);
        } catch (\Throwable $e) {
            // A gateway that is down or slow must not take the page with it:
            // the caller turns a false into "could not be sent, try another
            // way", which is a better answer than a 500 on a login screen.
            Log::error('[SMS:semaphore] Request failed: '.$e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::error('[SMS:semaphore] HTTP '.$response->status().' -- '.$response->body());

            return false;
        }

        foreach ((array) $response->json() as $entry) {
            $status = strtolower((string) ($entry['status'] ?? ''));

            if (in_array($status, ['failed', 'refunded'], true)) {
                Log::error('[SMS:semaphore] Gateway returned status "'.$status.'" for the message.');

                return false;
            }
        }

        return true;
    }

    /**
     * Put a Philippine mobile number in the shape Semaphore expects.
     *
     * Numbers are typed by people into an optional profile field, so they
     * arrive as "+63 912 345 6789", "0912-345-6789" or "9123456789" -- all
     * the same number, none of which a gateway should be asked to guess at.
     * Anything that is not recognisably a PH mobile is passed through as
     * digits and left to the gateway to accept or refuse.
     */
    private static function normalisePhNumber(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        // 639171234567 -> 09171234567
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        // 9171234567 -> 09171234567
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0'.$digits;
        }

        return $digits;
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
