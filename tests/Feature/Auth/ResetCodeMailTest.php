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

    public function test_the_code_stays_out_of_the_subject(): void
    {
        $mail = new PasswordResetCodeMail(new User(['name' => 'Someone', 'email' => 'x@remedi.com']), '482913', 10);

        $mail->assertHasSubject('Your REMEDI password reset code');
    }
}
