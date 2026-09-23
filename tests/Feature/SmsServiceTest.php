<?php

namespace Tests\Feature;

use App\Services\SmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * App\Services\SmsService -- the one place a text leaves this app, and the
 * thing the admin password-reset code rides on.
 *
 * Http::fake() throughout: no test ever reaches semaphore.co, which would
 * cost real credits and send a real text to whatever number the fixture
 * happened to use.
 *
 * The cases that matter are the ones where a message did NOT go out but a
 * naive integration would say it did -- a 200 carrying a "Failed" status, a
 * missing API key, a gateway that times out. Every one of those has to come
 * back false, because the caller turns false into "we could not text you,
 * use another way" and true into a screen that tells somebody to go and check
 * a phone.
 */
class SmsServiceTest extends TestCase
{
    private function useSemaphore(string $key = 'test-key'): void
    {
        config(['sms.driver' => 'semaphore', 'sms.semaphore.key' => $key, 'sms.semaphore.sender_name' => '']);
    }

    public function test_a_sent_message_reports_success(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response([['message_id' => 1, 'status' => 'Pending']], 200)]);
        $this->useSemaphore();

        $this->assertTrue(SmsService::send('09171234567', 'code 123456'));
    }

    public function test_a_failed_status_inside_a_200_is_not_treated_as_sent(): void
    {
        // Semaphore answers 200 with per-message statuses, so a refused
        // message (bad number, no credits) arrives looking like success.
        Http::fake(['api.semaphore.co/*' => Http::response([['message_id' => 1, 'status' => 'Failed']], 200)]);
        $this->useSemaphore();

        $this->assertFalse(SmsService::send('09171234567', 'code 123456'));
    }

    public function test_an_http_error_is_not_treated_as_sent(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['message' => 'Unauthorized'], 401)]);
        $this->useSemaphore();

        $this->assertFalse(SmsService::send('09171234567', 'code 123456'));
    }

    public function test_a_missing_api_key_refuses_rather_than_calling_the_gateway(): void
    {
        Http::fake();
        $this->useSemaphore('');

        $this->assertFalse(SmsService::send('09171234567', 'code 123456'));
        Http::assertNothingSent();
    }

    public function test_a_gateway_that_throws_does_not_take_the_page_with_it(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('connection timed out');
        });
        $this->useSemaphore();

        // A login screen must not 500 because an SMS gateway is having a bad
        // day -- the caller needs a false it can explain.
        $this->assertFalse(SmsService::send('09171234567', 'code 123456'));
    }

    public function test_an_empty_number_never_reaches_the_gateway(): void
    {
        Http::fake();
        $this->useSemaphore();

        $this->assertFalse(SmsService::send('', 'code 123456'));
        $this->assertFalse(SmsService::send(null, 'code 123456'));
        Http::assertNothingSent();
    }

    /**
     * People type a number into an optional profile field however they like,
     * and all of these are the same handset.
     */
    public function test_ph_numbers_are_normalised_for_the_gateway(): void
    {
        foreach (['+63 945 455 3998', '0945-455-3998', '9454553998', '09454553998'] as $typed) {
            Http::fake(['api.semaphore.co/*' => Http::response([['status' => 'Pending']], 200)]);
            $this->useSemaphore();

            SmsService::send($typed, 'code 123456');

            Http::assertSent(function ($request) use ($typed) {
                return $request['number'] === '09454553998'
                    || $this->fail("[$typed] reached the gateway as ".$request['number']);
            });
        }
    }

    public function test_an_unregistered_sender_name_is_not_sent_unless_configured(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response([['status' => 'Pending']], 200)]);
        $this->useSemaphore();

        SmsService::send('09454553998', 'code 123456');

        // Semaphore rejects an unapproved sender name outright, so the
        // default has to be "don't send one at all".
        Http::assertSent(fn ($request) => ! isset($request['sendername']));
    }

    public function test_a_configured_sender_name_is_passed_through(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response([['status' => 'Pending']], 200)]);
        $this->useSemaphore();
        config(['sms.semaphore.sender_name' => 'REMEDI']);

        SmsService::send('09454553998', 'code 123456');

        Http::assertSent(fn ($request) => $request['sendername'] === 'REMEDI');
    }

    public function test_the_log_driver_reports_success_without_a_gateway(): void
    {
        Http::fake();
        config(['sms.driver' => 'log']);

        $this->assertTrue(SmsService::send('09454553998', 'code 123456'));
        $this->assertFalse(SmsService::delivers(), 'the log driver must never claim it delivers');
        Http::assertNothingSent();
    }

    public function test_delivers_is_true_once_a_real_gateway_is_set(): void
    {
        $this->useSemaphore();

        // This is what removes the "look in the log" notice from the OTP
        // screen, so it has to follow the driver rather than be set by hand.
        $this->assertTrue(SmsService::delivers());
    }
}
