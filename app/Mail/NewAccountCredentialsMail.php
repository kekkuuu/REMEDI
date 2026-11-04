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
 * A new account's sign-in details, sent to its PERSONAL address: the @remedi.com
 * address is only a login name and has no mailbox behind it.
 *
 * Not queued: the admin is waiting to hear whether it went, and Add User refuses
 * to create an account it could not tell the holder about.
 *
 * The password is passed in because only a hash is ever stored. It is a temporary
 * one: the holder must replace it at first sign-in (must_change_password).
 */
class NewAccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        $sender = (string) config('mail.from.address');

        return new Envelope(
            from: new Address($sender, 'REMEDI'),
            replyTo: [new Address($sender, 'REMEDI')],
            // The password is NOT in the subject: subject lines surface on a lock
            // screen and in notification previews.
            subject: 'Your REMEDI account is ready',
        );
    }

    public function content(): Content
    {
        // HTML and plain text, for the reason PasswordResetCodeMail gives.
        return new Content(
            view: 'emails.new-account-credentials',
            text: 'emails.new-account-credentials-text',
        );
    }
}
