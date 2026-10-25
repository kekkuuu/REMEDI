<?php

namespace Tests\Feature;

use App\Mail\SafeguardCodeMail;
use App\Models\AuditTrail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Forgot the current one?" on Safeguard (2026-10-25, at the user's request):
 * a one-time code emailed to the admin's personal address stands in for a
 * forgotten current void passcode or backup password, once.
 */
class SafeguardForgotCodeTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /** Ask for a code and read it back out of the faked mail. */
    private function requestCode(User $admin, string $which): string
    {
        Mail::fake();
        $this->actingAs($admin)->withSession($this->confirmed())
            ->post("/safeguard/forgot/{$which}")
            ->assertSessionHasNoErrors();

        $code = null;
        Mail::assertSent(SafeguardCodeMail::class, function (SafeguardCodeMail $mail) use ($admin, &$code) {
            $code = $mail->code;

            return $mail->hasTo($admin->personal_email);
        });

        return $code;
    }

    public function test_an_emailed_code_replaces_a_forgotten_passcode_once(): void
    {
        Setting::setVoidPasscode('111111');
        $admin = User::factory()->admin()->create();
        $code = $this->requestCode($admin, 'passcode');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue(AuditTrail::where('details', "Safeguard code sent: {$admin->name} (POS void passcode)")->exists());
        $this->assertFalse(AuditTrail::where('details', 'like', "%{$code}%")->exists(), 'the code never reaches the trail');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', ['current_passcode' => $code, 'passcode' => '222222', 'passcode_confirmation' => '222222'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Setting::checkVoidPasscode('222222'));
        $this->assertTrue(AuditTrail::where('details', 'Set the POS void passcode (with an emailed code)')->exists());

        // Spent: the same code does not work a second time.
        $this->actingAs($admin)
            ->put('/safeguard/void-passcode', ['current_passcode' => $code, 'passcode' => '333333', 'passcode_confirmation' => '333333'])
            ->assertSessionHasErrors('current_passcode');
        $this->assertTrue(Setting::checkVoidPasscode('222222'));
    }

    public function test_an_emailed_code_replaces_a_forgotten_backup_password(): void
    {
        Setting::setBackupPassword('the-old-backup-password');
        $admin = User::factory()->admin()->create();
        $code = $this->requestCode($admin, 'backup');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', [
                'current_backup_password' => $code,
                'backup_password' => 'a-brand-new-password',
                'backup_password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Setting::checkBackupPassword('a-brand-new-password'));
    }

    public function test_a_code_only_works_for_the_thing_it_was_sent_for(): void
    {
        Setting::setVoidPasscode('111111');
        Setting::setBackupPassword('the-old-backup-password');
        $admin = User::factory()->admin()->create();
        $code = $this->requestCode($admin, 'passcode');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/backup-password', [
                'current_backup_password' => $code,
                'backup_password' => 'a-brand-new-password',
                'backup_password_confirmation' => 'a-brand-new-password',
            ])
            ->assertSessionHasErrors('current_backup_password');
    }

    public function test_an_expired_code_is_refused(): void
    {
        Setting::setVoidPasscode('111111');
        $admin = User::factory()->admin()->create();
        $code = $this->requestCode($admin, 'passcode');

        $this->travel(11)->minutes();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', ['current_passcode' => $code, 'passcode' => '222222', 'passcode_confirmation' => '222222'])
            ->assertSessionHasErrors('current_passcode');
        $this->assertTrue(Setting::checkVoidPasscode('111111'));
    }

    public function test_no_code_without_a_personal_email_or_with_nothing_set(): void
    {
        Mail::fake();
        Setting::setVoidPasscode('111111');

        // An account without one is sent to My Profile first (EnsureUserHasPersonalEmail).
        $this->actingAs(User::factory()->admin()->create(['personal_email' => null]))->withSession($this->confirmed())
            ->post('/safeguard/forgot/passcode')
            ->assertRedirect(route('profile.edit'));

        // No backup password set: nothing to replace.
        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->post('/safeguard/forgot/backup')
            ->assertSessionHasErrors('current_backup_password');

        Mail::assertNothingSent();
    }

    public function test_staff_cannot_request_a_code(): void
    {
        Mail::fake();
        Setting::setVoidPasscode('111111');

        $this->actingAs(User::factory()->create())->withSession($this->confirmed())
            ->post('/safeguard/forgot/passcode')
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
