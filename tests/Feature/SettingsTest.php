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

    public function test_staff_cannot_reach_the_settings_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertForbidden();
    }

    public function test_an_admin_can_set_the_void_passcode(): void
    {
        $this->assertFalse(Setting::voidPasscodeIsSet());

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/void-passcode', [
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

        $this->actingAs($admin)
            ->put('/settings/void-passcode', ['passcode' => '123', 'passcode_confirmation' => '123'])
            ->assertSessionHasErrors('passcode');

        $this->actingAs($admin)
            ->put('/settings/void-passcode', ['passcode' => 'abcdef', 'passcode_confirmation' => 'abcdef'])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse(Setting::voidPasscodeIsSet());
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/void-passcode', [
                'passcode' => '445566',
                'passcode_confirmation' => '778899',
            ])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse(Setting::voidPasscodeIsSet());
    }

    public function test_setting_a_new_passcode_replaces_the_old_one(): void
    {
        Setting::setVoidPasscode('111111');

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/void-passcode', [
                'passcode' => '222222',
                'passcode_confirmation' => '222222',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::checkVoidPasscode('111111'));
        $this->assertTrue(Setting::checkVoidPasscode('222222'));
    }
}
