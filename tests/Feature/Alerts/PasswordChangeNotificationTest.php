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
            'personal_email' => 'someone@gmail.com',
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => 'staff',
            'password' => 'brand-new-789',
            'password_confirmation' => 'brand-new-789',
        ])->assertSessionHasNoErrors();

        $this->assertNull($staff->fresh()->password_reset_requested_at);
        $this->assertTrue(Hash::check('brand-new-789', $staff->fresh()->password));
        // The admin knows it, so the account holder must set their own.
        $this->assertTrue($staff->fresh()->must_change_password);

        $row = $this->feed()['Password changed'] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(AlertService::ACCOUNT_KIND, $row['kind']);
        $this->assertStringContainsString('by an admin', $row['body']);
    }

    public function test_an_admin_setting_their_own_password_here_is_not_forced_to_change_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/users/'.$admin->id, [
            'personal_email' => 'someone@gmail.com',
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'admin',
            'password' => 'my-own-pass-1',
            'password_confirmation' => 'my-own-pass-1',
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $admin->fresh()->must_change_password);
    }

    public function test_the_forced_change_locks_the_account_until_a_new_password_is_set(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['password' => Hash::make('old-pass-123')]);

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'personal_email' => 'someone@gmail.com',
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => 'staff',
            'password' => 'admin-set-456',
            'password_confirmation' => 'admin-set-456',
        ])->assertSessionHasNoErrors();

        $this->actingAs($staff->fresh())->get('/pos')->assertRedirect(route('profile.edit'));

        $this->actingAs($staff->fresh())->put('/password', [
            'current_password' => 'admin-set-456',
            'password' => 'my-choice-789',
            'password_confirmation' => 'my-choice-789',
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $staff->fresh()->must_change_password);
    }

    public function test_an_edit_without_a_password_says_nothing_about_passwords(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'personal_email' => 'someone@gmail.com',
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
