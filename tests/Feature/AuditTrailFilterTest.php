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

    /* ---- "My Profile"'s "View all activity logs" link (user_id) ----
     *
     * The link used to point straight at /audit with no scope at all, so
     * "View all activity logs" under one account's own activity card opened
     * the WHOLE system's trail -- every account's actions, not the one the
     * heading above it was describing.
     */

    public function test_user_id_narrows_to_one_accounts_rows_only(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['name' => 'Someone Else']);

        AuditTrail::create([
            'user_id' => $admin->id, 'username' => $admin->name, 'role' => 'admin',
            'action' => 'Updated', 'details' => 'The admin did this',
        ]);
        AuditTrail::create([
            'user_id' => $other->id, 'username' => $other->name, 'role' => 'staff',
            'action' => 'Updated', 'details' => 'Someone else did this',
        ]);

        $rows = $this->actingAs($admin)
            ->getJson('/audit?all=1&user_id='.$admin->id)
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('The admin did this', $rows);
        $this->assertStringNotContainsString('Someone else did this', $rows);
    }

    public function test_profiles_activity_link_is_scoped_to_the_viewers_own_account(): void
    {
        $admin = $this->admin();

        // The link only renders once the account HAS recorded activity (see
        // profile/edit.blade.php's isNotEmpty() guard); a freshly-created
        // test user has none, and the page falls back to "No recorded
        // activity yet." with no link at all to assert against.
        AuditTrail::create([
            'user_id' => $admin->id, 'username' => $admin->name, 'role' => 'admin',
            'action' => 'Login', 'details' => 'Signed in',
        ]);

        $html = $this->actingAs($admin)->get('/profile')->getContent();

        // Blade escapes the "&" between query params to "&amp;" -- e() to
        // compare against what actually lands in the HTML, not the raw URL.
        $this->assertStringContainsString(
            'href="'.e(route('audit.index', ['user_id' => $admin->id, 'all' => 1])).'"',
            $html
        );
    }

    public function test_the_scoped_view_names_the_account_and_offers_a_way_back(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)
            ->get('/audit?all=1&user_id='.$admin->id)
            ->getContent();

        $this->assertStringContainsString('Showing activity for', $html);
        $this->assertStringContainsString($admin->name, $html);
        // The way back drops user_id but keeps the rest of the query string.
        $this->assertStringContainsString(
            'href="'.e(route('audit.index', ['all' => 1])).'"',
            $html
        );
    }

    public function test_an_unscoped_visit_shows_no_such_banner(): void
    {
        $html = $this->actingAs($this->admin())->get('/audit')->getContent();

        $this->assertStringNotContainsString('Showing activity for', $html);
    }
}
