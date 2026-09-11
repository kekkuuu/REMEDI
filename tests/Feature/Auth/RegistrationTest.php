<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/register` is the admin "Add User" form, NOT public signup.
 *
 * The stock Breeze tests asserted that anyone could reach it and that
 * registering signs you in. Neither is true here: the route sits behind
 * `['auth', 'active', 'role:admin']`, and an admin creating a staff account
 * must stay signed in as themselves afterwards -- being silently swapped into
 * the account you just created would be a serious bug in a pharmacy system,
 * where the audit trail attributes every sale and stock movement to whoever
 * is signed in.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_public(): void
    {
        $this->get('/register')->assertRedirect('/login');
    }

    public function test_staff_cannot_reach_the_add_user_screen(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/register')->assertForbidden();
    }

    public function test_an_admin_can_render_the_add_user_screen(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/register')->assertOk();
    }

    public function test_an_admin_can_create_a_user_and_stays_signed_in_as_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => 'staff',
        ]);

        // Still the admin, not the new account.
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * The Add User form is a `js-confirm` form (layouts/app.blade.php), so
     * every real submission goes through the shared confirm-dialog handler,
     * which posts via fetch() with these two headers -- making
     * `$request->wantsJson()` true and `store()` return a JsonResponse
     * rather than a redirect. The method's own return type used to promise
     * only RedirectResponse, so PHP's strict return-type check threw a
     * fatal TypeError on every single AJAX-driven account creation -- the
     * user WAS created, but the response blew up building the success
     * reply, and the confirm dialog surfaced the TypeError message as
     * "could not create." The plain-POST test above never exercises this
     * path, which is exactly how it went unnoticed.
     */
    public function test_an_admin_can_create_a_user_via_the_ajax_confirm_dialog(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/register', [
            'name' => 'Ajax User',
            'email' => 'ajax@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'ajax@example.com',
            'role' => 'staff',
        ]);
        $this->assertAuthenticatedAs($admin);
    }
}
