<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `/` renders its own splash/landing/login page (resources/views/
     * welcome.blade.php) for a guest -- it used to just redirect straight to
     * `/login`, but every surface behind it (POS, inventory, dashboard) is
     * still behind auth, so this is the front door, not a bypass of it.
     */
    public function test_the_root_url_shows_the_landing_page_for_a_guest(): void
    {
        $this->get('/')->assertOk()->assertViewIs('welcome');
    }

    /**
     * Same `guest` middleware alias GET/POST login already use: a signed-in
     * visitor lands on the dashboard rather than the marketing page again.
     */
    public function test_the_root_url_redirects_a_signed_in_user_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect('/dashboard');
    }

    public function test_the_login_screen_is_reachable_for_a_guest(): void
    {
        $this->get('/login')->assertOk();
    }
}
