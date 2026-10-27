<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Personal Email (2026-10-23, at the user's request): OPTIONAL when an admin
 * creates or edits an account, and asked of the account holder on sign-in --
 * EnsureUserHasPersonalEmail sends an account without one to My Profile, where
 * a pop-up that cannot be dismissed asks for it, until it is saved. My Profile
 * itself requires it. It is where the account's own reset code is emailed.
 */
class PersonalEmailRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_user_creates_an_account_without_one(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/register', [
            'name' => 'Jane Cruz',
            'email' => 'jane@remedi.com',
            'role' => 'staff',
            'phone' => '09171234567',
            'personal_email' => '',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'jane@remedi.com')->value('personal_email'));
    }

    public function test_edit_user_may_leave_it_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['personal_email' => null]);

        $this->actingAs($admin)->put('/users/'.$staff->id, [
            'name' => 'Renamed',
            'email' => $staff->email,
            'role' => 'staff',
            'personal_email' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $staff->fresh()->name);
    }

    public function test_an_account_without_one_is_sent_to_my_profile_and_asked_for_it(): void
    {
        $staff = User::factory()->create(['personal_email' => null]);

        $this->actingAs($staff)->get('/pos')->assertRedirect(route('profile.edit'));
        $this->actingAs($staff)->getJson('/alerts')->assertStatus(423);

        $html = $this->actingAs($staff)->get('/profile')->assertOk()->getContent();
        $this->assertStringContainsString('id="personalEmailModal"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="pe-personal-email"[^>]*\srequired/s', $html);
    }

    public function test_saving_it_lifts_the_block_and_goes_where_they_were_headed(): void
    {
        $staff = User::factory()->create(['personal_email' => null]);

        $this->actingAs($staff)->get('/pos')->assertRedirect(route('profile.edit'));

        $this->actingAs($staff)->patch('/profile', [
            'name' => $staff->name,
            'personal_email' => 'cashier@gmail.com',
        ])->assertRedirect(url('/pos'));

        $this->assertSame('cashier@gmail.com', $staff->fresh()->personal_email);
        $this->actingAs($staff->fresh())->get('/pos')->assertOk();
        $this->assertStringNotContainsString('id="personalEmailModal"', $this->actingAs($staff->fresh())->get('/profile')->getContent());
    }

    /** An admin without one is asked too; the pop-up carries the work email the admin's profile save requires. */
    public function test_an_admin_without_one_is_asked_too(): void
    {
        $admin = User::factory()->admin()->create(['personal_email' => null]);

        $this->actingAs($admin)->get('/users')->assertRedirect(route('profile.edit'));

        $this->actingAs($admin)->patch('/profile', [
            'name' => $admin->name,
            'email' => $admin->email,
            'personal_email' => 'owner@gmail.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('owner@gmail.com', $admin->fresh()->personal_email);
    }

    /**
     * A temporary password is set first; the personal-email pop-up waits for it,
     * rendered CLOSED so the page can open it the moment the password change
     * succeeds (2026-10-27: it used to be absent and needed a reload).
     */
    public function test_a_temporary_password_comes_first(): void
    {
        $staff = User::factory()->create(['personal_email' => null, 'must_change_password' => true]);

        $html = $this->actingAs($staff)->get('/profile')->assertOk()->getContent();
        $this->assertStringContainsString('class="remedi-modal" id="personalEmailModal"', $html);
        $this->assertStringContainsString('data-locked-by-password', $html);

        // Once the password is changed, the same page renders it open.
        $staff->forceFill(['must_change_password' => false])->save();
        $this->assertStringContainsString('class="remedi-modal is-open" id="personalEmailModal"',
            $this->actingAs($staff->fresh())->get('/profile')->getContent());
    }

    public function test_my_profile_refuses_a_save_without_one(): void
    {
        $user = User::factory()->create(['personal_email' => null]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Changed Name',
        ])->assertSessionHasErrors('personal_email');

        $this->assertNotSame('Changed Name', $user->fresh()->name);
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $user = User::factory()->create(['personal_email' => null]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'personal_email' => 'not-an-email',
        ])->assertSessionHasErrors('personal_email');

        $this->assertNull($user->fresh()->personal_email);
    }

    public function test_only_my_profile_marks_it_required(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $required = '/<input[^>]*name="personal_email"[^>]*\srequired/s';

        $this->assertMatchesRegularExpression($required, $this->actingAs($admin)->get('/profile')->getContent());
        $this->assertDoesNotMatchRegularExpression($required, $this->actingAs($admin)->get('/register')->getContent());
        $this->assertDoesNotMatchRegularExpression($required, $this->actingAs($admin)->get('/users/'.$staff->id.'/edit')->getContent());
    }
}
