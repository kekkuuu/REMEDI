<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Deactivating an account must end its LIVE session, not just block the next
 * sign-in.
 *
 * `active` (EnsureUserIsActive) used to live inside EnsureUserHasRole, which is
 * only attached to the `role:admin` routes -- so it never ran for the
 * staff-facing half of the app, and a deactivated cashier with a session still
 * open could keep ringing up sales through POST /pos/checkout. LoginRequest
 * blocks a fresh sign-in, but that does nothing about a session already open.
 *
 * NOTE FOR ANYONE EXTENDING THIS FILE. Laravel's session guard memoises the
 * resolved user for the lifetime of the application instance, and the test
 * application is NOT rebuilt between requests inside one test method. Flip
 * is_active in the database and issue another request without clearing that
 * memo and the middleware reads a stale in-memory model, still active, and
 * happily returns 200 -- which looks exactly like this protection being broken
 * when it is not. `Auth::forgetUser()` forces the re-resolve from the session
 * that a genuine second HTTP request performs in its own process.
 */
class DeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function signedInCashier(): User
    {
        $user = User::factory()->create(['email' => 'cashier@remedi.test']);

        $this->post('/login', [
            'email' => 'cashier@remedi.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        return $user;
    }

    public function test_a_deactivated_cashier_loses_an_open_session_on_a_page_request(): void
    {
        $user = $this->signedInCashier();

        $this->get('/pos')->assertOk();

        User::whereKey($user->id)->update(['is_active' => false]);
        Auth::forgetUser();

        $this->get('/pos')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_deactivated_cashier_cannot_check_out_over_ajax(): void
    {
        $user = $this->signedInCashier();

        User::whereKey($user->id)->update(['is_active' => false]);
        Auth::forgetUser();

        // 401, not a 302 to the login page: the till checks out over fetch, and
        // a redirect would arrive as unparseable HTML and read as "broken"
        // rather than "signed out".
        $this->postJson('/pos/checkout', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'amount_paid' => 100,
        ])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_sign_in_at_all(): void
    {
        User::factory()->inactive()->create(['email' => 'retired@remedi.test']);

        $this->post('/login', [
            'email' => 'retired@remedi.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_deactivation_notice_is_a_real_sentence(): void
    {
        // lang/en/auth.php defines this key and nothing else. It was missing,
        // so the login page rendered the literal string "auth.deactivated" to
        // the one person least able to interpret it.
        $this->assertSame(
            'This account has been deactivated. Please contact an administrator.',
            trans('auth.deactivated')
        );
    }
}
