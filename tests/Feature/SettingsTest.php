<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Store-wide settings, currently just the POS void passcode
 * (SettingsController, Setting model) -- see Feature\Pos\VoidTest for how
 * staff spend it.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    /** A session whose login password was re-confirmed just now. */
    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /**
     * Safeguard (renamed from Settings 2026-09-24) asks for the LOGIN
     * password before it opens, so an unattended signed-in admin screen is
     * not enough to change the code that authorises voids.
     */
    public function test_safeguard_asks_for_the_login_password_before_opening(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/safeguard')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_the_right_login_password_opens_safeguard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/safeguard');

        $this->post('/confirm-password', ['password' => 'password'])
            ->assertRedirect('/safeguard');

        $this->get('/safeguard')->assertOk()->assertSee('Safeguard');
    }

    public function test_a_wrong_login_password_keeps_safeguard_locked(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/confirm-password', ['password' => 'not-it'])
            ->assertSessionHasErrors('password');

        $this->get('/safeguard')->assertRedirect(route('password.confirm'));
    }

    public function test_the_passcode_endpoint_is_locked_too_not_just_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/safeguard/void-passcode', [
                'passcode' => '445566',
                'passcode_confirmation' => '445566',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertFalse(Setting::voidPasscodeIsSet());
    }

    /** The sidebar pop-up posts over fetch and navigates itself on success. */
    public function test_the_pop_up_gets_json_answers(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/confirm-password', ['password' => 'not-it'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/confirm-password', ['password' => 'password'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->get('/safeguard')->assertOk();
    }

    /**
     * Reaching Safeguard directly lands on the app shell with the SAME pop-up
     * opened over it -- not a separate sign-in screen.
     */
    public function test_a_direct_visit_opens_the_pop_up_over_the_app(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/confirm-password')
            ->assertOk()
            ->assertSee('id="passwordGateModal"', false)
            ->assertSee('data-auto-open="1"', false)
            ->assertSee('class="sidebar', false);
    }

    /**
     * The backup streams the users table (password and reset-code hashes,
     * personal emails), so it sits behind the same login-password check.
     */
    public function test_the_database_backup_asks_for_the_login_password(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/backup')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_staff_cannot_download_the_backup_at_all(): void
    {
        $this->actingAs(User::factory()->create())->withSession($this->confirmed())
            ->get('/admin/backup')
            ->assertForbidden();
    }

    public function test_the_old_settings_address_redirects_to_safeguard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings')
            ->assertRedirect('/safeguard');
    }

    public function test_staff_cannot_reach_the_settings_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/safeguard')
            ->assertForbidden();
    }

    public function test_an_admin_can_set_the_void_passcode(): void
    {
        $this->assertFalse(Setting::voidPasscodeIsSet());

        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', [
                'passcode' => '445566',
                'passcode_confirmation' => '445566',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Setting::voidPasscodeIsSet());
        $this->assertTrue(Setting::checkVoidPasscode('445566'));
        $this->assertFalse(Setting::checkVoidPasscode('000000'));
    }

    public function test_the_passcode_must_be_exactly_six_digits(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', ['passcode' => '123', 'passcode_confirmation' => '123'])
            ->assertSessionHasErrors('passcode');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', ['passcode' => 'abcdef', 'passcode_confirmation' => 'abcdef'])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse(Setting::voidPasscodeIsSet());
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', [
                'passcode' => '445566',
                'passcode_confirmation' => '778899',
            ])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse(Setting::voidPasscodeIsSet());
    }

    public function test_setting_a_new_passcode_replaces_the_old_one(): void
    {
        Setting::setVoidPasscode('111111');

        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', [
                'current_passcode' => '111111',
                'passcode' => '222222',
                'passcode_confirmation' => '222222',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::checkVoidPasscode('111111'));
        $this->assertTrue(Setting::checkVoidPasscode('222222'));
    }

    public function test_replacing_the_passcode_needs_the_current_one(): void
    {
        Setting::setVoidPasscode('111111');
        $admin = User::factory()->admin()->create();
        $new = ['passcode' => '222222', 'passcode_confirmation' => '222222'];

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', $new)
            ->assertSessionHasErrors(['current_passcode' => 'Enter the current passcode.']);

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', $new + ['current_passcode' => '999999'])
            ->assertSessionHasErrors(['current_passcode' => 'The current passcode is incorrect.']);

        $this->assertTrue(Setting::checkVoidPasscode('111111'), 'a refused change leaves the old code working');
        $this->assertFalse(Setting::checkVoidPasscode('222222'));

        // The Safeguard page asks for it only once a code exists.
        $this->actingAs($admin)->withSession($this->confirmed())
            ->get('/safeguard')->assertSee('Current passcode');
    }

    public function test_wrong_current_codes_are_limited(): void
    {
        Setting::setVoidPasscode('111111');
        $admin = User::factory()->admin()->create();
        $new = ['passcode' => '222222', 'passcode_confirmation' => '222222'];

        for ($i = 0; $i < SettingsController::CURRENT_MAX_ATTEMPTS; $i++) {
            $this->actingAs($admin)->withSession($this->confirmed())
                ->put('/safeguard/void-passcode', $new + ['current_passcode' => '99999'.$i]);
        }

        // Even the right code is refused while locked out.
        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/void-passcode', $new + ['current_passcode' => '111111'])
            ->assertSessionHasErrors('current_passcode');

        $this->assertTrue(Setting::checkVoidPasscode('111111'));
    }

    public function test_the_first_passcode_needs_no_current_one(): void
    {
        $this->actingAs(User::factory()->admin()->create())->withSession($this->confirmed())
            ->get('/safeguard')->assertDontSee('Current passcode');
    }

    /**
     * The uploaded GCash QR was removed on 2026-10-15 at the user's request:
     * Safeguard no longer offers it, its endpoint is gone, and the till draws
     * the plain REMEDI code even if an old row is still in the table.
     */
    public function test_the_gcash_qr_is_gone_from_safeguard_and_the_till(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession($this->confirmed())
            ->get('/safeguard')->assertOk()
            ->assertDontSee('GCash QR for the Till')
            ->assertSee('Backup Password');

        $this->actingAs($admin)->withSession($this->confirmed())
            ->put('/safeguard/gcash-qr', ['gcash_qr' => ''])
            ->assertNotFound();

        Setting::put('pos_gcash_qr_payload', '00020101021127');
        $this->actingAs($admin)->get('/pos')->assertOk()
            ->assertDontSee('00020101021127')
            ->assertDontSee('data-qr-payload', false);
    }
}
