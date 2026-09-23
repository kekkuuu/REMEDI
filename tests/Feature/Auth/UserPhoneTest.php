<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone field on User Management's Add User and Edit User forms --
 * `users.phone` already existed (migration 2026_08_19_000002) and was
 * editable from My Profile, but neither admin form wrote it.
 */
class UserPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_add_user_saves_a_phone_number(): void
    {
        $this->actingAs($this->admin())->post('/register', [
            'name' => 'New Hire',
            'email' => 'newhire@remedi.com',
            'role' => 'staff',
            'phone' => '+63 912 345 6789',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+63 912 345 6789', User::where('email', 'newhire@remedi.com')->value('phone'));
    }

    /**
     * Inverted 2026-09-23, at the user's request: Add User now REQUIRES a
     * phone number. The app changed deliberately, so the test that asserted
     * the old behaviour is rewritten to assert the new one rather than the
     * app being loosened to keep it green.
     *
     * The PROFILE form keeps it nullable -- see ProfileUpdateRequest. An admin
     * creating an account has the person to hand and can ask; requiring it of
     * an existing account would block it from saving any other change until it
     * supplied one.
     */
    public function test_add_user_now_requires_a_phone_number(): void
    {
        $this->actingAs($this->admin())->post('/register', [
            'name' => 'No Phone Yet',
            'email' => 'nophone@remedi.com',
            'role' => 'staff',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('phone');

        $this->assertNull(User::where('email', 'nophone@remedi.com')->first());
    }

    public function test_add_user_saves_an_optional_personal_email(): void
    {
        $this->actingAs($this->admin())->post('/register', [
            'name' => 'With Personal',
            'email' => 'personal@remedi.com',
            'role' => 'staff',
            'phone' => '+63 912 345 6789',
            'personal_email' => 'someone@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $this->assertSame('someone@gmail.com', User::where('email', 'personal@remedi.com')->value('personal_email'));
    }

    public function test_edit_user_updates_the_phone_number(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => 'staff', 'phone' => null]);

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => 'staff',
            'phone' => '+63 917 000 1111',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+63 917 000 1111', $staff->fresh()->phone);
    }
}
