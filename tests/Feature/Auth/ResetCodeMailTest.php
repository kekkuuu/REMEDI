<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Tests\TestCase;

/**
 * The reset-code email carries a plain-text part as well as HTML (2026-09-28):
 * HTML-only mail with a one-time code is a classic spam signal, and the code
 * was landing in Gmail's spam folder.
 */
class ResetCodeMailTest extends TestCase
{
    public function test_the_code_is_in_both_the_html_and_the_plain_text_part(): void
    {
        $mail = new PasswordResetCodeMail(new User(['name' => 'A & B Test', 'email' => 'ab@remedi.com']), '482913', 10);

        $mail->assertSeeInHtml('482913');
        $mail->assertSeeInHtml('<!DOCTYPE html>', false);
        $mail->assertSeeInText('482913');
        $mail->assertSeeInText('10 minutes');
        // Plain text is not HTML-escaped.
        $mail->assertSeeInText('A & B Test', false);
        $mail->assertDontSeeInText('&amp;', false);
    }

    public function test_it_sends_as_remedi_with_a_reply_to_and_no_split_words(): void
    {
        config(['mail.from.address' => 'sender@example.com', 'mail.from.name' => 'Example']);
        $mail = new PasswordResetCodeMail(new User(['name' => 'Someone', 'email' => 'x@remedi.com']), '482913', 10);

        $mail->assertFrom('sender@example.com', 'REMEDI');
        $mail->assertHasReplyTo('sender@example.com');
        // A word split across tags ("RE<span>ME</span>DI") is a spam signal.
        $mail->assertSeeInHtml('>REMEDI</p>', false);
        $mail->assertDontSeeInHtml('RE<span', false);
    }

    public function test_the_code_stays_out_of_the_subject(): void
    {
        $mail = new PasswordResetCodeMail(new User(['name' => 'Someone', 'email' => 'x@remedi.com']), '482913', 10);

        $mail->assertHasSubject('Your REMEDI password reset code');
    }
}
