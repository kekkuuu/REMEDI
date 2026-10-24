<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The welcome page's splash (2026-10-24, at the user's request): the REMEDI
 * logo-reveal video plays full screen, stops on its last frame and waits for
 * Get Started; signing out lands here. Playback itself is the browser's, so
 * these pin what the server sends.
 */
class WelcomeSplashTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_welcome_page_plays_the_logo_reveal_and_waits_for_get_started(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<video[^>]*id="splashVideo"[^>]*src="[^"]*assets\/remedi-reveal\.mp4\?v=[0-9a-f]+"[^>]*\smuted/s', $html);
        $this->assertStringNotContainsString('Logo.mp4"', $html);
        $this->assertStringContainsString('id="getStartedBtn"', $html);
        // Nothing loops or advances on its own: the video has no loop/autoplay
        // attribute, and the script plays it once.
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*id="splashVideo"[^>]*\sloop/s', $html);
    }

    public function test_the_login_page_has_no_separate_intro(): void
    {
        $this->assertStringNotContainsString('remedi-reveal.mp4', $this->get('/login')->assertOk()->getContent());
    }

    public function test_signing_out_lands_on_the_splash(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');
    }
}
