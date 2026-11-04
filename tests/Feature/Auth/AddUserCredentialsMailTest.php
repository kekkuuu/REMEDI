<?php

namespace Tests\Feature\Auth;

use App\Mail\NewAccountCredentialsMail;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Add User generates the password and emails the sign-in details to the person's
 * PERSONAL address, because @remedi.com is only a login name with no mailbox.
 */
class AddUserCredentialsMailTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Cruz',
            'email' => 'jane@remedi.com',
            'role' => 'staff',
            'phone' => '09171234567',
            'personal_email' => 'jane.cruz@gmail.com',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_the_details_go_to_the_personal_address_and_the_password_works(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/register', $this->payload())->assertSessionHasNoErrors();

        $user = User::where('email', 'jane@remedi.com')->firstOrFail();

        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) use ($user) {
            return $mail->hasTo('jane.cruz@gmail.com')
                && ! $mail->hasTo('jane@remedi.com')
                && Hash::check($mail->temporaryPassword, $user->password)
                && strlen($mail->temporaryPassword) >= 12;
        });
    }

    public function test_the_mail_names_the_sign_in_address_and_the_password(): void
    {
        $user = User::factory()->make(['name' => 'Jane Cruz', 'email' => 'jane@remedi.com']);
        $mail = new NewAccountCredentialsMail($user, 'Temp0rary1234', 'https://example.test/login');

        $mail->assertSeeInHtml('jane@remedi.com');
        $mail->assertSeeInHtml('Temp0rary1234');
        $mail->assertSeeInText('jane@remedi.com');
        $mail->assertSeeInText('Temp0rary1234');
        $mail->assertSeeInText('https://example.test/login');
        $this->assertStringNotContainsString('Temp0rary1234', $mail->envelope()->subject);
    }

    public function test_the_holder_must_choose_their_own_password(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/register', $this->payload());

        $this->assertTrue((bool) User::where('email', 'jane@remedi.com')->value('must_change_password'));
    }

    public function test_every_account_gets_its_own_password(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/register', $this->payload());
        $this->actingAs($admin)->post('/register', $this->payload(['email' => 'mark@remedi.com', 'personal_email' => 'mark@gmail.com']));

        $sent = [];
        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) use (&$sent) {
            $sent[] = $mail->temporaryPassword;

            return true;
        });
        $this->assertCount(2, array_unique($sent));
    }

    public function test_a_posted_password_is_ignored(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/register', $this->payload([
            'password' => 'chosen-by-the-admin',
            'password_confirmation' => 'chosen-by-the-admin',
        ]));

        $this->assertFalse(Hash::check('chosen-by-the-admin', User::where('email', 'jane@remedi.com')->value('password')));
    }

    public function test_the_personal_address_is_required(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/register', $this->payload(['personal_email' => '']))
            ->assertSessionHasErrors('personal_email');

        $this->assertNull(User::where('email', 'jane@remedi.com')->first());
        Mail::assertNothingSent();
    }

    public function test_no_account_is_created_when_the_mail_cannot_be_sent(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

        $this->actingAs($this->admin())->postJson('/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull(User::where('email', 'jane@remedi.com')->first());
        $this->assertFalse(AuditTrail::where('details', 'like', 'Added user account%')->exists());
    }

    public function test_the_password_never_reaches_the_audit_trail(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())->post('/register', $this->payload());

        Mail::assertSent(NewAccountCredentialsMail::class, function (NewAccountCredentialsMail $mail) {
            $this->assertFalse(AuditTrail::where('details', 'like', '%'.$mail->temporaryPassword.'%')->exists());

            return true;
        });
    }

    public function test_the_form_asks_for_no_password_and_says_where_the_details_go(): void
    {
        $this->actingAs($this->admin())->get('/register')
            ->assertOk()
            // By id, not name: the layout's password-confirm pop-up has its own
            // name="password" on every page.
            ->assertDontSee('id="password"', false)
            ->assertDontSee('id="password_confirmation"', false)
            ->assertSee('emailed here');
    }
}
