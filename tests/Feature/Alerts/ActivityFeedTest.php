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
        $this->assertContains('Account updated', $titles);
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
}
