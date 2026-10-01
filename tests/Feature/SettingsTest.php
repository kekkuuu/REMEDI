<?php

namespace Tests\Feature;

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
                'passcode' => '222222',
                'passcode_confirmation' => '222222',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::checkVoidPasscode('111111'));
        $this->assertTrue(Setting::checkVoidPasscode('222222'));
    }

    /** A made-up but well-formed static QR Ph payload -- never a real account. */
    private function fakeQrPh(string $name = 'REMEDI TEST'): string
    {
        $tlv = fn (string $id, string $v) => $id.str_pad((string) strlen($v), 2, '0', STR_PAD_LEFT).$v;
        $body = $tlv('00', '01').$tlv('01', '11')
            .$tlv('27', $tlv('00', 'com.example.p2p').$tlv('01', '000000000000'))
            .$tlv('52', '6016').$tlv('53', '608').$tlv('58', 'PH')
            .$tlv('59', $name).$tlv('60', 'MANILA').'6304';

        return $body.Setting::qrPhCrc($body);
    }

    /** The shop's GCash QR (2026-10-01): saved on Safeguard, drawn at the till. */
    public function test_an_admin_saves_the_gcash_qr_and_the_till_draws_it(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->fakeQrPh();
        $this->assertTrue(Setting::isQrPhPayload($payload));

        $this->actingAs($admin)->withSession($this->confirmed())
            ->putJson('/safeguard/gcash-qr', ['gcash_qr' => $payload])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame($payload, Setting::gcashQrPayload());

        // The audit trail never carries the payload.
        $details = \App\Models\AuditTrail::latest('id')->value('details');
        $this->assertSame('Set the POS GCash QR', $details);

        $this->actingAs($admin)->withSession($this->confirmed())->get('/safeguard')
            ->assertOk()->assertSee('A GCash QR is saved')->assertSee('REMEDI TEST');

        $this->actingAs(User::factory()->create())->get('/pos')
            ->assertOk()->assertSee('data-qr-payload="'.$payload.'"', false)
            ->assertSee('Scan with GCash or any bank app');

        // Removed: the till falls back to the plain REMEDI code.
        $this->actingAs($admin)->withSession($this->confirmed())
            ->putJson('/safeguard/gcash-qr', ['gcash_qr' => ''])->assertOk();
        $this->assertNull(Setting::gcashQrPayload());
        $this->actingAs(User::factory()->create())->get('/pos')
            ->assertSee('data-qr-payload=""', false);
    }

    public function test_only_a_real_qr_ph_code_is_saved_and_only_by_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $good = $this->fakeQrPh();
        $badCrc = substr($good, 0, -4).($good[-1] === '0' ? 'FFF1' : '0000');

        foreach (['REMEDI', '09454553998', $badCrc, '000201'.str_repeat('9', 30), substr($good, 0, -10)] as $bad) {
            $this->actingAs($admin)->withSession($this->confirmed())
                ->putJson('/safeguard/gcash-qr', ['gcash_qr' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('gcash_qr');
        }
        $this->actingAs($admin)->withSession($this->confirmed())
            ->putJson('/safeguard/gcash-qr', ['gcash_qr' => [$good]])->assertStatus(422);
        $this->assertNull(Setting::gcashQrPayload());

        $this->actingAs(User::factory()->create())->withSession($this->confirmed())
            ->putJson('/safeguard/gcash-qr', ['gcash_qr' => $good])->assertForbidden();

        $this->flushSession();
        $this->actingAs($admin)->put('/safeguard/gcash-qr', ['gcash_qr' => $good])
            ->assertRedirect(route('password.confirm'));
        $this->assertNull(Setting::gcashQrPayload());
    }
}
