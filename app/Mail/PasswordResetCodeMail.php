<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin password-reset code, sent to the account's PERSONAL address.
 *
 * Not queued, and deliberately so: the person is sitting on the "enter the
 * code" screen waiting for it, and this app runs QUEUE_CONNECTION=sync
 * anyway, so queueing would only add a way for the mail to silently never go.
 *
 * The code is passed in rather than read off the user, because only a hash is
 * ever stored (User::issuePasswordOtp) -- the plain value exists solely inside
 * the request that mints it.
 */
class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        $sender = (string) config('mail.from.address');

        return new Envelope(
            // A fixed display name rather than MAIL_FROM_NAME, whose fallback
            // is "Example" -- a host missing that variable would send as that.
            // Reply-To is the sender itself: a reset mail nobody can answer
            // is one more thing filters score.
            from: new Address($sender, 'REMEDI'),
            replyTo: [new Address($sender, 'REMEDI')],
            // The code is NOT in the subject: subject lines surface on a lock
            // screen and in notification previews, where a shoulder is enough
            // to read it without unlocking the phone.
            subject: 'Your REMEDI password reset code',
        );
    }

    public function content(): Content
    {
        // HTML AND plain text. HTML-only mail carrying a one-time code is a
        // classic spam signal, and this one was landing in Gmail's spam
        // folder (2026-09-28).
        return new Content(
            view: 'emails.password-reset-code',
            text: 'emails.password-reset-code-text',
        );
    }
}
