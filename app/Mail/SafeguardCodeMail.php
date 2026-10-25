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
 * A one-time code that stands in for a FORGOTTEN current void passcode or
 * backup password on Safeguard (2026-10-25, at the user's request), sent to
 * the admin's personal address. Same shape and delivery rules as
 * PasswordResetCodeMail: not queued, HTML and plain text, the code never in
 * the subject, and only a hash kept (SettingsController::sendForgotCode).
 */
class SafeguardCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public string $what,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        $sender = (string) config('mail.from.address');

        return new Envelope(
            from: new Address($sender, 'REMEDI'),
            replyTo: [new Address($sender, 'REMEDI')],
            subject: 'Your REMEDI Safeguard code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.safeguard-code',
            text: 'emails.safeguard-code-text',
        );
    }
}
