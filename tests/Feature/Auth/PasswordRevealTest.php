<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every password field carries a show / hide button (2026-10-25, at the
 * user's request): public/assets/pw-reveal.js adds it, so each of the three
 * page shells -- the welcome splash, the guest layout and the app layout --
 * must load it. The button itself is drawn in the browser.
 */
class PasswordRevealTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_welcome_page_loads_the_reveal_script(): void
    {
        $this->get('/')->assertOk()->assertSee('assets/pw-reveal.js', false);
    }

    public function test_the_guest_pages_load_the_reveal_script(): void
    {
        $this->get('/login')->assertOk()->assertSee('assets/pw-reveal.js', false);
        $this->get('/forgot-password')->assertOk()->assertSee('assets/pw-reveal.js', false);
    }

    public function test_the_app_pages_load_the_reveal_script(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk()
            ->assertSee('assets/pw-reveal.js', false);
    }
}
