<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The ADMIN half of "Forgot your password?": an admin has no admin to ask,
 * so they verify a 6-digit code texted to the number on their own account
 * and set the password themselves. Staff keep the request-an-admin flow,
 * which Feature\Auth\PasswordResetRequestTest already covers.
 *
 * What is asserted here is mostly the REFUSALS, because this is the shortest
 * path in the app between an email address and an admin account: a wrong
 * code, an expired one, one guessed too many times, and every attempt to
 * skip a step by walking straight to a later URL.
 */
class AdminOtpPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture mail instead of sending it -- and note this is the ONLY way
        // a test can learn the code, because it is stored hashed and the plain
        // value lives only inside the request that sent it.
        Mail::fake();

        // The send limiter is keyed on user id, and RefreshDatabase reissues
        // the same ids to every test -- without this, one test's sends count
        // against the next one's.
        foreach (range(1, 4) as $id) {
            RateLimiter::clear('password-otp:'.$id);
        }
    }

    private function adminWithEmail(): User
    {
        return User::factory()->admin()->create(['personal_email' => 'someone@gmail.com']);
    }

    /** Ask for a code, and read it off the mail that was sent. */
    private function requestCodeFor(User $admin): string
    {
        $this->post('/forgot-password', ['email' => $admin->email]);

        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'a reset-code email should have been sent');

        return $code;
    }

    public function test_a_staff_request_still_notifies_an_admin_and_issues_no_code(): void
    {
        $staff = User::factory()->create(['personal_email' => 'someone@gmail.com']);

        $this->post('/forgot-password', ['email' => $staff->email]);

        $staff->refresh();
        $this->assertNotNull($staff->password_reset_requested_at, 'staff still flag for an admin');
        $this->assertNull($staff->password_otp_hash, 'a cashier never gets a code');
    }

    public function test_an_admin_with_a_number_is_sent_a_code(): void
    {
        $admin = $this->adminWithEmail();

        $this->post('/forgot-password', ['email' => $admin->email])
            ->assertRedirect(route('password.otp'));

        $admin->refresh();
        $this->assertNotNull($admin->password_otp_hash);
        $this->assertTrue($admin->password_otp_expires_at->isFuture());
        // The SMS path replaces the admin queue rather than joining it.
        $this->assertNull($admin->password_reset_requested_at);
    }

    public function test_an_admin_with_no_number_falls_back_to_asking_another_admin(): void
    {
        $admin = User::factory()->admin()->create(['personal_email' => null]);

        $this->post('/forgot-password', ['email' => $admin->email]);

        $admin->refresh();
        $this->assertNull($admin->password_otp_hash, 'nothing to text, so no code is minted');
        $this->assertNotNull($admin->password_reset_requested_at, 'falls back rather than dead-ending');
    }

    public function test_the_correct_code_lets_the_admin_set_a_new_password(): void
    {
        $admin = $this->adminWithEmail();
        $code = $this->requestCodeFor($admin);

        $this->post('/forgot-password/code', ['code' => $code])
            ->assertRedirect(route('password.otp.reset'));

        $this->post('/forgot-password/new-password', [
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('login'));

        $admin->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $admin->password));
        // They chose it themselves, so there is nothing to force a change of.
        $this->assertFalse($admin->must_change_password);
        $this->assertNull($admin->password_otp_hash, 'the code is burned once used');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $admin = $this->adminWithEmail();
        $code = $this->requestCodeFor($admin);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post('/forgot-password/code', ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, (int) $admin->fresh()->password_otp_attempts);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $admin = $this->adminWithEmail();
        $code = $this->requestCodeFor($admin);

        $this->travel(User::OTP_TTL_MINUTES + 1)->minutes();

        $this->post('/forgot-password/code', ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($admin->fresh()->password_otp_hash, 'an expired code is cleared, not left lying about');
    }

    public function test_the_code_is_burned_after_too_many_wrong_guesses(): void
    {
        $admin = $this->adminWithEmail();
        $code = $this->requestCodeFor($admin);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < User::OTP_MAX_ATTEMPTS; $i++) {
            $this->post('/forgot-password/code', ['code' => $wrong]);
        }

        $this->assertNull($admin->fresh()->password_otp_hash);

        // And the RIGHT code no longer works either -- that is the point.
        $this->post('/forgot-password/code', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_the_password_form_cannot_be_reached_without_verifying(): void
    {
        $admin = $this->adminWithEmail();
        $this->requestCodeFor($admin);

        // A code was sent, but never entered.
        $this->get('/forgot-password/new-password')
            ->assertRedirect(route('password.request'));

        $this->post('/forgot-password/new-password', [
            'password' => 'straight-to-the-end',
            'password_confirmation' => 'straight-to-the-end',
        ])->assertRedirect(route('password.request'));

        $this->assertFalse(Hash::check('straight-to-the-end', $admin->fresh()->password));
    }

    public function test_the_code_form_cannot_be_reached_with_no_request_pending(): void
    {
        $this->get('/forgot-password/code')->assertRedirect(route('password.request'));
        $this->post('/forgot-password/code', ['code' => '123456'])->assertRedirect(route('password.request'));
    }

    public function test_resending_issues_a_new_code_and_kills_the_old_one(): void
    {
        $admin = $this->adminWithEmail();
        $first = $this->requestCodeFor($admin);

        $this->post('/forgot-password/code/resend')
            ->assertRedirect(route('password.otp'));

        // Mail::fake() accumulates, so the newest send is the last one.
        $codes = [];
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$codes) {
            $codes[] = $mail->code;

            return true;
        });
        $second = end($codes);

        // The old code must stop working the moment a new one is issued, or
        // two live codes double the guessing surface.
        $this->post('/forgot-password/code', ['code' => $first])
            ->assertSessionHasErrors('code');

        $this->post('/forgot-password/code', ['code' => $second])
            ->assertRedirect(route('password.otp.reset'));
    }

    public function test_resending_needs_no_email_retyped_but_does_need_a_pending_request(): void
    {
        // Nothing in the session: the button cannot be used to text an
        // account the visitor has not already named on the first screen.
        $this->post('/forgot-password/code/resend')
            ->assertRedirect(route('password.request'));

        Mail::assertNothingSent();
    }

    public function test_resending_shares_the_send_limit_with_the_email_form(): void
    {
        $admin = $this->adminWithEmail();
        $this->requestCodeFor($admin);

        // Four more by button: five sends total, which is the cap.
        for ($i = 0; $i < 4; $i++) {
            $this->post('/forgot-password/code/resend');
        }

        // The button must not buy sends the email form would have refused.
        $this->post('/forgot-password/code/resend')
            ->assertSessionHasErrors('code');
    }

    public function test_sending_codes_is_rate_limited(): void
    {
        $admin = $this->adminWithEmail();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $admin->email]);
        }

        // Every text costs money once a gateway is wired in, so the sixth in
        // a row is refused rather than sent.
        $this->post('/forgot-password', ['email' => $admin->email])
            ->assertSessionHasErrors('email');
    }
}
