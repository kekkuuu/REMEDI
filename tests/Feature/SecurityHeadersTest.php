<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every response says it may not be framed by another site and must not be
 * type-sniffed (2026-11-07). See App\Http\Middleware\SecurityHeaders.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertProtected($response): void
    {
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_guest_page_a_signed_in_page_and_an_error_all_carry_them(): void
    {
        $this->assertProtected($this->get('/login')->assertOk());
        $this->assertProtected($this->actingAs(User::factory()->create())->get('/profile')->assertOk());
        $this->assertProtected($this->get('/does-not-exist')->assertNotFound());
    }

    public function test_a_json_answer_carries_them_too(): void
    {
        $this->assertProtected($this->actingAs(User::factory()->create())->getJson('/alerts')->assertOk());
    }
}
