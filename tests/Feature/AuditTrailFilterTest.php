<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit trail's action filter, and the account events it was hiding.
 *
 * The dropdown carried a hand-typed list — Login, Logout, Viewed, **Create**,
 * **Update**, **Delete** — while `AuditTrail::log()` has always been called
 * with the past tense, and `applyFilters()` matches the column exactly. So
 * three of the six options could never match a row: on this install
 * `?action=Create` returned 0 of 109, `?action=Update` 0 of 35,
 * `?action=Delete` 0 of 12.
 *
 * Nothing errored. The page rendered its normal empty state, which reads as
 * "the app does not record this" — and that is exactly how creating,
 * deactivating and deleting user accounts all came to look unlogged when every
 * one of them was sitting in the table the whole time. The filter, not the
 * logging, was broken.
 *
 * Two halves, so it cannot come back: the four account events are asserted to
 * be written at all, and the control is asserted to offer only spellings that
 * can match.
 */
class AuditTrailFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ---- The events themselves ---- */

    public function test_creating_an_account_is_logged(): void
    {
        $this->actingAs($this->admin())->post('/register', [
            'name' => 'New Cashier',
            'email' => 'new.cashier@remedi.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        $this->assertDatabaseHas('audit_trails', ['action' => 'Created']);
        $this->assertTrue(
            AuditTrail::where('details', 'like', '%Added user account: New Cashier%')->exists()
        );
    }

    public function test_updating_an_account_is_logged(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => 'New Name',
            'email' => $user->email,
            'role' => 'staff',
        ]);

        $this->assertTrue(
            AuditTrail::where('action', 'Updated')
                ->where('details', 'like', '%Updated user account%')
                ->exists()
        );
    }

    public function test_deactivating_an_account_is_logged(): void
    {
        $user = User::factory()->create(['name' => 'Leaver']);

        $this->actingAs($this->admin())->patch("/users/{$user->id}/toggle");

        $this->assertTrue(
            AuditTrail::where('action', 'Updated')
                ->where('details', 'like', '%Account deactivated: Leaver%')
                ->exists()
        );
    }

    public function test_deleting_an_account_is_logged(): void
    {
        $user = User::factory()->create(['name' => 'Removed']);

        $this->actingAs($this->admin())->delete("/users/{$user->id}");

        $this->assertTrue(
            AuditTrail::where('action', 'Deleted')
                ->where('details', 'like', '%Deleted user account: Removed%')
                ->exists()
        );
    }

    /* ---- The filter that was hiding them ---- */

    public function test_every_offered_action_is_one_the_app_actually_writes(): void
    {
        // The bug in one assertion: an option the column can never hold.
        foreach (AuditTrail::ACTIONS as $action) {
            $this->assertNotContains(
                $action,
                array_keys(AuditTrail::ACTION_ALIASES),
                "the filter offers '{$action}', which is a superseded spelling"
            );
        }
    }

    public function test_the_created_filter_finds_a_created_row(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/register', [
            'name' => 'Findable Cashier',
            'email' => 'findable@remedi.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        $this->actingAs($admin)
            ->get('/audit?action=Created')
            ->assertOk()
            ->assertSee('Added user account: Findable Cashier');
    }

    public function test_the_old_singular_spelling_still_finds_its_rows(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/register', [
            'name' => 'Bookmarked Cashier',
            'email' => 'bookmarked@remedi.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        // A link from before the list was fixed must not answer "nothing
        // happened" — an audit trail asserting that is the worst failure it has.
        $this->actingAs($admin)
            ->get('/audit?action=Create')
            ->assertOk()
            ->assertSee('Added user account: Bookmarked Cashier');
    }

    public function test_the_dropdown_offers_the_stored_spellings(): void
    {
        $html = $this->actingAs($this->admin())->get('/audit')->getContent();

        foreach (['Created', 'Updated', 'Deleted'] as $action) {
            $this->assertStringContainsString('<option value="'.$action.'"', $html);
        }

        foreach (['Create', 'Update', 'Delete'] as $dead) {
            $this->assertStringNotContainsString('<option value="'.$dead.'"', $html);
        }
    }

    public function test_a_filter_still_narrows_rather_than_showing_everything(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/register', [
            'name' => 'Narrowing Probe',
            'email' => 'narrowing@remedi.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'staff',
        ]);

        AuditTrail::log('Login', 'Someone logged in');

        // Asserted against the AJAX partial, which is the TABLE alone.
        // A plain GET also renders the notification bell, and the bell lists
        // recent audit rows by their details — so `assertDontSee` on the whole
        // page fails on the bell's copy no matter what the filter did, the
        // mirror image of the trap REMEDI.md records for `assertSee`.
        $rows = $this->actingAs($admin)
            ->getJson('/audit?action=Created')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Added user account: Narrowing Probe', $rows);
        $this->assertStringNotContainsString('Someone logged in', $rows);
    }
}
