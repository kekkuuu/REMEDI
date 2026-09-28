<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A password changed or reset reaches the admins as an account event -- a bell
 * row and a toast (2026-09-28, at the user's request). The own-change entry
 * used to read "Changed own account password", which matched nothing in
 * AlertService's account predicate and was filed as a generic "Record updated".
 */
class PasswordChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function feed(): array
    {
        Cache::flush();

        return collect(app(AlertService::class)->activity())->keyBy('title')->all();
    }

    public function test_changing_your_own_password_notifies_the_admins(): void
    {
        $staff = User::factory()->create(['name' => 'Cashier Cruz', 'password' => Hash::make('old-pass-123')]);

        $this->actingAs($staff)->put('/password', [
            'current_password' => 'old-pass-123',
            'password' => 'new-pass-456',
            'password_confirmation' => 'new-pass-456',
        ])->assertSessionHasNoErrors();

        $row = $this->feed()['Password changed'] ?? null;
        $this->assertNotNull($row, 'no "Password changed" row in the feed');
        $this->assertSame(AlertService::ACCOUNT_KIND, $row['kind']);
        $this->assertStringContainsString('Cashier Cruz', $row['body']);
        $this->assertSame('/users', $row['href']);
    }

    public function test_an_admin_setting_a_password_notifies_and_answers_a_pending_request(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'Cashier Cruz', 'password_reset_requested_at' => now()]);

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => 'staff',
            'password' => 'brand-new-789',
            'password_confirmation' => 'brand-new-789',
        ])->assertSessionHasNoErrors();

        $this->assertNull($staff->fresh()->password_reset_requested_at);
        $this->assertTrue(Hash::check('brand-new-789', $staff->fresh()->password));

        $row = $this->feed()['Password changed'] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(AlertService::ACCOUNT_KIND, $row['kind']);
        $this->assertStringContainsString('by an admin', $row['body']);
    }

    public function test_an_edit_without_a_password_says_nothing_about_passwords(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'name' => 'Renamed Person',
            'email' => $staff->email,
            'role' => 'staff',
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('Password changed', $this->feed());
    }

    public function test_the_user_list_no_longer_offers_a_reset_button_or_badge(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['password_reset_requested_at' => now()]);

        $this->actingAs($admin)->get('/users')
            ->assertOk()
            ->assertDontSee('/users/'.$staff->id.'/reset-password', false)
            ->assertDontSee('Reset requested')
            ->assertDontSee('Password Resets');
    }
}
