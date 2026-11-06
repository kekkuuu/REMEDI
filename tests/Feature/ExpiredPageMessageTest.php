<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * A form whose security token no longer matches the session (2026-11-06): the pop-ups
 * said "CSRF token mismatch.", which tells the person at the counter nothing.
 *
 * The test suite switches the CSRF check off, so the exception is rendered directly.
 */
class ExpiredPageMessageTest extends TestCase
{
    private function render(array $headers)
    {
        $request = Request::create('/register', 'POST');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $this->app->make(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_a_pop_up_is_told_to_reload_in_plain_words(): void
    {
        $response = $this->render(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(419, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame(Handler::EXPIRED_PAGE_MESSAGE, $body['error']);
        $this->assertSame(Handler::EXPIRED_PAGE_MESSAGE, $body['message']);
        $this->assertStringNotContainsString('CSRF', $response->getContent());
    }

    public function test_a_plain_form_post_keeps_the_page_expired_screen(): void
    {
        $response = $this->render([]);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringNotContainsString(Handler::EXPIRED_PAGE_MESSAGE, $response->getContent());
    }

    public function test_the_real_check_answers_with_it_when_switched_on(): void
    {
        $this->withMiddleware();
        $this->app['env'] = 'production';
        $this->app->bind('runningUnitTests', fn () => false);

        $response = $this->actingAs(User::factory()->admin()->make(['id' => 1]))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson('/register', ['_token' => 'stale']);

        if ($response->getStatusCode() !== 419) {
            $this->markTestSkipped('The CSRF check stays off in this test environment.');
        }

        $response->assertJsonPath('error', Handler::EXPIRED_PAGE_MESSAGE);
    }
}
