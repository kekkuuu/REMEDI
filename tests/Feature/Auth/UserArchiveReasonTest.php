<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why an account was archived from User Management: Resigned or Fired,
 * required -- see User::ARCHIVE_REASONS and UserController::destroy(). Not
 * asked of a self-archive (ProfileController::destroy) -- that path is
 * untouched by this.
 */
class UserArchiveReasonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function staff(string $name = 'Departing Staff'): User
    {
        return User::factory()->create(['role' => 'staff', 'name' => $name]);
    }

    public function test_archiving_with_no_reason_is_refused(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->delete('/users/'.$staff->id)
            ->assertSessionHasErrors('reason');

        $this->assertTrue(User::whereKey($staff->id)->exists(), 'a refused archive must not have happened');
    }

    public function test_archiving_with_an_unknown_reason_is_refused(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->delete('/users/'.$staff->id, ['reason' => 'retired'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(User::whereKey($staff->id)->exists());
    }

    public function test_archiving_resigned_records_the_reason_on_the_row(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->delete('/users/'.$staff->id, ['reason' => 'resigned'])
            ->assertSessionHasNoErrors();

        $archived = User::withTrashed()->find($staff->id);
        $this->assertSame('resigned', $archived->archive_reason);
        $this->assertSame('Resigned', $archived->archive_reason_label);
    }

    public function test_archiving_fired_records_the_reason_on_the_row(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)
            ->delete('/users/'.$staff->id, ['reason' => 'fired'])
            ->assertSessionHasNoErrors();

        $archived = User::withTrashed()->find($staff->id);
        $this->assertSame('fired', $archived->archive_reason);
        $this->assertSame('Fired', $archived->archive_reason_label);
    }

    public function test_the_archived_list_shows_the_reason_in_the_status_badge(): void
    {
        $admin = $this->admin();
        $staff = $this->staff('Badge Check');

        $this->actingAs($admin)->delete('/users/'.$staff->id, ['reason' => 'fired']);

        $this->actingAs($admin)
            ->get('/users?archived=1')
            ->assertOk()
            ->assertSee('Archived')
            ->assertSee('Fired');
    }

    public function test_restoring_clears_the_reason(): void
    {
        $admin = $this->admin();
        $staff = $this->staff();

        $this->actingAs($admin)->delete('/users/'.$staff->id, ['reason' => 'resigned']);
        $this->actingAs($admin)->patch('/users/'.$staff->id.'/restore')->assertSessionHasNoErrors();

        $this->assertNull(User::find($staff->id)->archive_reason);
    }

    /** The self-archive refusal fires before the reason is even validated. */
    public function test_an_admin_archiving_themselves_is_refused_before_the_reason_check(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete('/users/'.$admin->id)
            ->assertSessionHasErrors('user')
            ->assertSessionDoesntHaveErrors('reason');
    }
}
