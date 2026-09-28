<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An email field posted as an array (`email[]=x`) used to reach a bare
 * (string) cast and raise "Array to string conversion" -- a 500, and on the
 * guest-facing Forgot Password form. Each must answer a validation error.
 */
class ArrayEmailInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_refuses_an_array_email_without_crashing(): void
    {
        $this->post('/forgot-password', ['email' => ['x']])->assertSessionHasErrors('email');
    }

    public function test_add_user_refuses_an_array_email_without_crashing(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/register', ['email' => ['x']])
            ->assertSessionHasErrors('email');
    }

    public function test_edit_user_refuses_an_array_email_without_crashing(): void
    {
        $staff = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put('/users/'.$staff->id, ['name' => 'X', 'email' => ['x'], 'role' => 'staff'])
            ->assertSessionHasErrors('email');
    }

    public function test_my_profile_refuses_an_array_email_without_crashing(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->patch('/profile', ['name' => 'X', 'email' => ['x']])
            ->assertSessionHasErrors('email');
    }

    /** current_password handed an array to password_verify(), which threw. */
    public function test_password_checks_refuse_an_array_without_crashing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile', ['password' => ['x']])
            ->assertSessionHasErrors('password', null, 'userDeletion');
        $this->actingAs($user)->put('/password', ['current_password' => ['x'], 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');
        $this->actingAs($user)->post('/confirm-password', ['password' => ['x']])
            ->assertSessionHasErrors('password');
    }

    /** (int) of a 20-digit page is PHP_INT_MAX; the offset maths overflowed. */
    public function test_inventory_survives_an_absurd_page_number(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/inventory?page=99999999999999999999')
            ->assertOk();
    }
}
