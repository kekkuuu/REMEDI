<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` is not a landing page in this app -- it redirects straight to login.
     *
     * The stock Breeze version of this test asserted 200 here, which never
     * passed against REMEDI: routes/web.php defines `/` as nothing but a
     * redirect to the login screen, because every surface (POS, inventory,
     * dashboard) is behind auth. Asserting the redirect is the honest test.
     */
    public function test_the_root_url_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_login_screen_is_reachable_for_a_guest(): void
    {
        $this->get('/login')->assertOk();
    }
}
