<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    /**
     * "User ID" on the profile page is User::$staff_code ("ADM-001" /
     * "STF-004", derived from the primary key), not the bare numeric id --
     * and it has to agree with what User Management's own ID column shows
     * for the same account, or the two pages disagree about what a user's
     * ID even is.
     */
    public function test_the_profile_page_shows_the_staff_code_not_a_bare_id(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->actingAs($admin)->get('/profile')->assertSee($admin->staff_code);
        $this->actingAs($staff)->get('/profile')->assertSee($staff->staff_code);
    }

    public function test_admin_and_staff_get_different_code_prefixes(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->assertStringStartsWith('ADM-', $admin->staff_code);
        $this->assertStringStartsWith('STF-', $staff->staff_code);
    }

    public function test_user_management_shows_the_same_staff_code_as_the_profile_page(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'Findable Cashier']);

        $this->actingAs($admin)
            ->get('/users')
            ->assertSee($staff->staff_code)
            ->assertSee($admin->staff_code);
    }

    /**
     * Staff may correct their own name and phone -- not their email.
     *
     * ProfileUpdateRequest only admits `email`, `department` and
     * `preferred_language` when the signed-in user is an admin. The Breeze
     * version of this test posted a new email as an ordinary user and asserted
     * it stuck, which has never been true here: email is the login identity,
     * and in a pharmacy the audit trail attributes stock movements and sales
     * to it, so changing it is an administrator's decision.
     */
    public function test_staff_can_update_their_name_but_not_their_email(): void
    {
        $user = User::factory()->create(['email' => 'cashier@remedi.test']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'someone-else@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('cashier@remedi.test', $user->email);
    }

    public function test_an_admin_can_update_their_email_and_it_needs_reverifying(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch('/profile', [
                'name' => 'Test Admin',
                'email' => 'test@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $admin->refresh();

        $this->assertSame('Test Admin', $admin->name);
        $this->assertSame('test@example.com', $admin->email);
        $this->assertNull($admin->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    /**
     * Deleting your own account must not resurrect it.
     *
     * Regression test. SessionGuard::logout() calls cycleRememberToken() when
     * the account has a non-empty remember_token, and that ends in
     * $user->save(). With the logout AFTER the delete -- where stock Breeze
     * puts it, and where this controller used to have it -- `exists` is
     * already false, so Eloquent runs that save as an INSERT and writes the
     * row back with its original id.
     *
     * The account came back while the audit trail said it had been deleted,
     * the session ended, and the user was redirected as though it worked.
     * ProfileController::destroy() therefore logs out BEFORE deleting; this
     * test fails if anyone puts that back the other way round.
     */
    public function test_deleting_your_own_account_does_not_resurrect_it(): void
    {
        $user = User::factory()->create(['remember_token' => 'a-remembered-session']);
        $id = $user->id;

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(User::find($id), 'The account was re-inserted after being deleted.');
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }
}
