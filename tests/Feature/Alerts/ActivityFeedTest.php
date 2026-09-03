<?php

namespace Tests\Feature\Alerts;

use App\Models\AuditTrail;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bell's System / Updates feed, and the noise that used to fill it.
 *
 * `AlertService::activity()` takes the newest few audit rows with no notion of
 * importance. `Viewed` is written every time anyone opens a report -- including
 * the same report twice while adjusting a filter -- and on this install it grew
 * to 877 of 1,426 rows, 62% of the trail. So the newest few were almost always
 * report views, and everything that actually CHANGED something was pushed out:
 * measured before the fix, all six slots read "New report generated" while a
 * batch addition and a live sale sat unseen in the same window.
 *
 * Nothing downstream was broken. The mapping always classified a batch
 * correctly; those rows were simply never selected, which is exactly why it
 * looked like the notification system was ignoring them.
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AlertService::forget();
    }

    /** Audit rows are written in order, so `latest('id')` returns them reversed. */
    private function log(string $action, string $details): void
    {
        AuditTrail::log($action, $details);
    }

    private function feed(): array
    {
        AlertService::forget();

        return app(AlertService::class)->activity();
    }

    public function test_a_new_batch_reaches_the_feed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', "Added batch 'ACE-20260903-01' (100 units) for ACEITE");

        $bodies = array_column($this->feed(), 'body');

        $this->assertNotEmpty(array_filter($bodies, fn ($b) => str_contains($b, 'ACE-20260903-01')));
    }

    /**
     * The regression itself: a batch addition buried under report views.
     *
     * Twelve views is well past the six slots the panel has, so before the fix
     * the batch could not appear at any position.
     */
    public function test_report_views_do_not_crowd_out_a_new_batch(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', "Added batch 'ACE-20260903-01' (100 units) for ACEITE");

        foreach (range(1, 12) as $i) {
            $this->log('Viewed', "Generated Inventory Report (filtered) #{$i}");
        }

        $feed = $this->feed();
        $bodies = array_column($feed, 'body');

        $this->assertNotEmpty(
            array_filter($bodies, fn ($b) => str_contains($b, 'ACE-20260903-01')),
            'the batch must survive a run of report views'
        );

        $this->assertEmpty(
            array_filter($bodies, fn ($b) => str_contains($b, 'Generated Inventory Report')),
            'a report VIEW changes nothing and does not belong in a notification panel'
        );
    }

    public function test_the_feed_still_carries_the_other_kinds(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', "Added batch 'ACE-20260903-01' (5 units) for ACEITE");
        $this->log('Updated', 'Account deactivated: Leaver');
        $this->log('Deleted', 'Deleted product: Something');
        $this->log('Login', 'Admin logged in');

        $titles = array_column($this->feed(), 'title');

        $this->assertContains('Record added', $titles);
        // Named precisely now: this used to read the generic "Account updated".
        $this->assertContains('Account deactivated', $titles);
        $this->assertContains('Record deleted', $titles);
        $this->assertContains('Signed in', $titles);
    }

    /** Sales are changes too, and were being lost the same way. */
    public function test_a_checkout_reaches_the_feed_past_report_views(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', 'Processed sale TXN-20260903-00006 - Total: 111.96');

        foreach (range(1, 10) as $i) {
            $this->log('Viewed', "Generated Sales Report #{$i}");
        }

        $bodies = array_column($this->feed(), 'body');

        $this->assertNotEmpty(array_filter($bodies, fn ($b) => str_contains($b, 'TXN-20260903-00006')));
    }

    /* ---- Account changes, and the toast stack ------------------------- */

    /**
     * Each account event is NAMED, not lumped into one label.
     *
     * Deleting an account read as "Account updated", which is the one account
     * change you would most want stated plainly in a notification.
     */
    public function test_each_account_event_is_named(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', 'Added user account: Cesia Austria (staff)');
        $this->log('Updated', 'Updated user account: Cesia Austria');
        $this->log('Updated', 'Account deactivated: Cesia Austria');
        $this->log('Deleted', 'Deleted user account: Cesia Austria');

        $titles = array_column($this->feed(), 'title');

        $this->assertContains('New user added', $titles);
        $this->assertContains('User account updated', $titles);
        $this->assertContains('Account deactivated', $titles);
        $this->assertContains('User account deleted', $titles);
    }

    public function test_account_changes_carry_the_toast_kind_and_sign_ins_do_not(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->log('Created', 'Added user account: Cesia Austria (staff)');
        $this->log('Login', 'Admin logged in');

        $byTitle = collect($this->feed())->keyBy('title');

        $this->assertSame(AlertService::ACCOUNT_KIND, $byTitle['New user added']['kind']);
        // A card every time anybody signs in would make the stack useless.
        $this->assertSame('activity', $byTitle['Signed in']['kind']);
    }

    /** The seed the toast stack plays on page load. */
    private function toastSeed(string $html): array
    {
        if (! preg_match('/id="remediToastSeed">(.*?)<\/script>/s', $html, $m)) {
            return [];
        }

        return json_decode(html_entity_decode($m[1]), true) ?: [];
    }

    public function test_an_account_change_reaches_the_toast_seed(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->log('Deleted', 'Deleted user account: Mirae Montero');

        $seed = $this->toastSeed($this->actingAs($admin)->get('/users')->getContent());

        $this->assertContains(AlertService::ACCOUNT_KIND, $seed['kinds'] ?? []);
        $this->assertNotEmpty(
            array_filter($seed['items'] ?? [], fn ($i) => str_contains($i['body'] ?? '', 'Mirae Montero')),
            'an account change must be playable as a toast, not only listed in the bell'
        );
    }

    /**
     * Staff must never receive audit-derived rows, toasts included.
     *
     * activity() is admin-only and deliberately outside payload()'s cache,
     * which every signed-in user shares -- putting role-dependent rows in that
     * key is how a staff account ends up seeing whatever an admin cached first.
     */
    public function test_staff_never_see_an_account_change_in_their_toasts(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->log('Deleted', 'Deleted user account: Mirae Montero');

        $staff = User::factory()->create();
        $seed = $this->toastSeed($this->actingAs($staff)->get('/dashboard')->getContent());

        $this->assertEmpty(
            array_filter($seed['items'] ?? [], fn ($i) => ($i['kind'] ?? '') === AlertService::ACCOUNT_KIND),
            'a staff toast stack must carry no audit-derived rows at all'
        );
    }
}
