<?php

namespace Tests\Feature\Auth;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Forgot your password?" -- an in-app request-an-admin flow, not Breeze's
 * email-based reset. This app has no working mail delivery (MAIL_MAILER
 * points at a local mailpit catcher -- see PasswordResetRequestController's
 * docblock), so /forgot-password and /reset-password were repointed at
 * PasswordResetRequestController and UserController::resetPassword() instead.
 *
 * Replaces the Breeze scaffolding test that used to own these two routes
 * (PasswordResetTest), which asserted a ResetPassword notification this app
 * no longer sends -- the standing rule applies: never change the app to
 * satisfy a test; fix the test.
 */
class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_requesting_a_reset_flags_the_account_and_logs_it(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->password_reset_requested_at);
        $this->assertDatabaseHas('audit_trails', [
            'action' => 'Requested',
            'details' => "Password reset requested: {$user->name}",
        ]);
    }

    public function test_requesting_a_reset_for_an_unknown_email_is_refused(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@remedi.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_requesting_a_reset_for_an_archived_account_is_refused(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors('email');
    }

    public function test_a_second_request_does_not_duplicate_the_audit_entry(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $firstRequestedAt = $user->fresh()->password_reset_requested_at;

        $this->post('/forgot-password', ['email' => $user->email]);

        $this->assertSame(
            $firstRequestedAt->toIso8601String(),
            $user->fresh()->password_reset_requested_at->toIso8601String()
        );
        $this->assertSame(1, AuditTrail::where('action', 'Requested')->count());
    }

    public function test_an_admin_can_reset_the_password_and_it_forces_a_change(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->password_reset_requested_at = now();
        $user->save();

        $response = $this->actingAs($admin)->patch("/users/{$user->id}/reset-password");

        $response->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_reset_requested_at);
        $this->assertTrue(Hash::check(User::DEFAULT_RESET_PASSWORD, $user->password));
        $this->assertDatabaseHas('audit_trails', [
            'action' => 'Reset',
            'details' => "Password reset: {$user->name}",
        ]);
    }

    public function test_an_admin_can_reset_a_password_with_no_pending_request(): void
    {
        // Not gated on password_reset_requested_at -- an admin can act on a
        // phone call or someone at the counter, not only the online form.
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/users/{$user->id}/reset-password")
            ->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check(User::DEFAULT_RESET_PASSWORD, $user->fresh()->password));
    }

    public function test_staff_cannot_reset_a_password(): void
    {
        $cashier = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($cashier)->patch("/users/{$user->id}/reset-password")
            ->assertForbidden();
    }

    public function test_a_forced_password_change_redirects_away_from_the_rest_of_the_app(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_the_profile_page_itself_stays_reachable_while_forced(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_an_ajax_request_is_locked_out_too_not_just_full_page_loads(): void
    {
        // "You can't enter the system unless you change it" -- a hard block,
        // not a nag that a background poll or an in-progress checkout could
        // quietly slip past.
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->getJson('/alerts')
            ->assertStatus(423)
            ->assertJson(['success' => false]);
    }

    public function test_changing_the_password_clears_the_forced_flag(): void
    {
        // Factory default password is plaintext 'password' -- see
        // UserFactory's own note on why it's hashed at runtime rather than
        // hardcoded.
        $user = User::factory()->create(['must_change_password' => true]);

        $response = $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'a-real-new-password',
            'password_confirmation' => 'a-real-new-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
