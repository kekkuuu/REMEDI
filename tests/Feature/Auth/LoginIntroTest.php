<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * The logo-reveal intro in front of the login page (2026-10-23, at the user's
 * request). Whether it plays is decided in the browser (once per session);
 * these pin what the server sends: the video on a plain visit, muted so it can
 * autoplay, with a Skip button, and never over an error.
 */
class LoginIntroTest extends TestCase
{
    public function test_the_login_page_carries_the_intro_video(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('id="intro"', $html);
        $this->assertMatchesRegularExpression('/<video[^>]*src="[^"]*assets\/remedi-reveal\.mp4\?v=[0-9a-f]+"[^>]*\smuted/', $html);
        $this->assertStringContainsString('id="introSkip"', $html);
    }

    public function test_a_failed_sign_in_does_not_bring_it_back(): void
    {
        $html = $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => 'These credentials do not match our records.']))])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="intro"', $html);
    }

    public function test_other_guest_pages_do_not_play_it(): void
    {
        $this->assertStringNotContainsString('id="intro"', $this->get('/forgot-password')->assertOk()->getContent());
    }
}
