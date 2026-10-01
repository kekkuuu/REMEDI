<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockReport;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Staff notify an admin about low or expired stock; an admin approves or
 * rejects (StockReportController, 2026-09-28). Mostly REFUSALS: a report must
 * describe something true, must not duplicate, and only an admin decides --
 * once.
 */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $qty, string $expiry, int $reorder = 10): Product
    {
        $cat = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => 'Item '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $cat->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => $reorder,
        ]);

        ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => now()->subDays(400)->toDateString(),
        ]);

        return $product;
    }

    private function report(User $user, Product $product, string $type)
    {
        return $this->actingAs($user)->postJson('/stock-reports', ['product_id' => $product->id, 'type' => $type]);
    }

    public function test_staff_can_report_low_stock_and_the_admin_is_notified(): void
    {
        $staff = User::factory()->create();
        $low = $this->product(2, now()->addYear()->toDateString());

        $this->report($staff, $low, 'low_stock')->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('stock_reports', [
            'product_id' => $low->id, 'type' => 'low_stock', 'status' => 'pending', 'reported_by' => $staff->id,
        ]);
        $this->assertSame(1, StockReport::pendingCount());

        // The bell's activity feed carries it as its own toast kind, linking
        // at the page where the admin can act.
        Cache::flush();
        $row = collect(app(AlertService::class)->activity())->firstWhere('kind', AlertService::STOCK_REPORT_KIND);
        $this->assertNotNull($row);
        $this->assertSame('/stock-reports', $row['href']);
        $this->assertSame('Review', $row['action']);
    }

    /** The optional note (2026-09-30): kept, shown to the admin, and blank is fine. */
    public function test_an_optional_note_reaches_the_admin(): void
    {
        $staff = User::factory()->create();
        $low = $this->product(2, now()->addYear()->toDateString());

        $this->actingAs($staff)->postJson('/stock-reports', [
            'product_id' => $low->id, 'type' => 'low_stock', 'note' => 'Customers keep asking for it',
        ])->assertOk();

        $this->assertDatabaseHas('stock_reports', ['product_id' => $low->id, 'note' => 'Customers keep asking for it']);
        $this->assertStringContainsString('Note: "Customers keep asking for it"', AuditTrail::latest('id')->value('details'));

        // Neither Stock Reports list has a Note column (2026-10-01) -- the
        // admin reads the note in the bell.
        $this->actingAs($staff)->get('/stock-reports')
            ->assertOk()->assertDontSee('<th>Note</th>', false);
        $this->actingAs(User::factory()->admin()->create())->get('/stock-reports')
            ->assertOk()->assertDontSee('<th>Note</th>', false);

        // Left blank: still a report, no note, nothing appended.
        $other = $this->product(1, now()->addYear()->toDateString());
        $this->actingAs($staff)->postJson('/stock-reports', [
            'product_id' => $other->id, 'type' => 'low_stock', 'note' => '',
        ])->assertOk();
        $this->assertNull(StockReport::where('product_id', $other->id)->value('note'));
        $this->assertStringNotContainsString('Note:', AuditTrail::latest('id')->value('details'));

        // Past the column's 255 characters: refused, not a 500.
        $third = $this->product(1, now()->addYear()->toDateString());
        $this->actingAs($staff)->postJson('/stock-reports', [
            'product_id' => $third->id, 'type' => 'low_stock', 'note' => str_repeat('x', 256),
        ])->assertStatus(422);
    }

    public function test_the_staff_notify_button_offers_a_note_box(): void
    {
        $this->product(2, now()->addYear()->toDateString());

        $this->actingAs(User::factory()->create())->get('/inventory')
            ->assertOk()
            ->assertSee('data-confirm-note="optional"', false)
            ->assertSee('<input type="hidden" name="note" value="">', false);
    }

    public function test_staff_can_report_expired_stock(): void
    {
        $staff = User::factory()->create();
        $expired = $this->product(30, now()->subDays(5)->toDateString(), 1);

        $this->report($staff, $expired, 'expired')->assertOk();
        $this->assertDatabaseHas('stock_reports', ['product_id' => $expired->id, 'type' => 'expired']);
    }

    public function test_a_report_that_is_not_true_is_refused(): void
    {
        $staff = User::factory()->create();
        $fine = $this->product(500, now()->addYear()->toDateString());

        $this->report($staff, $fine, 'low_stock')->assertStatus(422);
        $this->report($staff, $fine, 'expired')->assertStatus(422);
        $this->assertDatabaseCount('stock_reports', 0);
    }

    public function test_a_second_report_while_one_is_waiting_writes_nothing(): void
    {
        $low = $this->product(1, now()->addYear()->toDateString());

        $this->report(User::factory()->create(), $low, 'low_stock')->assertOk();
        $this->report(User::factory()->create(), $low, 'low_stock')->assertOk();

        $this->assertDatabaseCount('stock_reports', 1);
        $this->assertSame(1, AuditTrail::where('details', 'like', 'Stock report:%')->count());
    }

    public function test_only_an_admin_decides_and_only_once(): void
    {
        $staff = User::factory()->create();
        $low = $this->product(1, now()->addYear()->toDateString());
        $this->report($staff, $low, 'low_stock');
        $report = StockReport::firstOrFail();

        $this->actingAs($staff)->patchJson("/stock-reports/{$report->id}/approve")->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson("/stock-reports/{$report->id}/approve")->assertOk();

        $report->refresh();
        $this->assertSame('approved', $report->status);
        $this->assertSame($admin->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);
        $this->assertSame(0, StockReport::pendingCount());
        $this->assertDatabaseHas('audit_trails', ['action' => 'Approved']);

        // Already answered: rejecting it now is refused, not a flip.
        $this->actingAs($admin)->patchJson("/stock-reports/{$report->id}/reject")->assertStatus(422);
        $this->assertSame('approved', $report->fresh()->status);
    }

    /**
     * The staff member who sent a report hears the answer (2026-09-28) -- in
     * their bell, the polled feed and a toast -- and nobody else does.
     */
    public function test_the_reporter_is_notified_of_the_decision(): void
    {
        $staff = User::factory()->create();
        $colleague = User::factory()->create();
        $low = $this->product(1, now()->addYear()->toDateString());
        $this->report($staff, $low, 'low_stock');

        // Nothing to tell anyone until it is decided.
        $this->assertSame([], app(AlertService::class)->activityFor($staff));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patchJson('/stock-reports/'.StockReport::firstOrFail()->id.'/reject')->assertOk();

        $rows = app(AlertService::class)->activityFor($staff->fresh());
        $this->assertCount(1, $rows);
        $this->assertSame('Your stock report was rejected', $rows[0]['title']);
        $this->assertSame(AlertService::STOCK_REPORT_KIND, $rows[0]['kind']);
        $this->assertSame('alerts', $rows[0]['group']);
        $this->assertSame('/stock-reports', $rows[0]['href']);
        $this->assertStringContainsString($low->name, $rows[0]['body']);

        // The polled feed carries it for them, not for a colleague.
        $this->actingAs($staff)->getJson('/alerts')->assertJsonPath('activity.0.title', 'Your stock report was rejected');
        $this->actingAs($colleague)->getJson('/alerts')->assertJsonCount(0, 'activity');
    }

    public function test_staff_see_only_their_own_reports(): void
    {
        $mine = User::factory()->create(['name' => 'Mine Person']);
        $other = User::factory()->create(['name' => 'Other Person']);
        $a = $this->product(1, now()->addYear()->toDateString());
        $b = $this->product(1, now()->addYear()->toDateString());
        $this->report($mine, $a, 'low_stock');
        $this->report($other, $b, 'low_stock');

        [$ra, $rb] = [StockReport::where('product_id', $a->id)->value('id'), StockReport::where('product_id', $b->id)->value('id')];

        // Row ids, not names or SKUs: every page's bell also lists low-stock
        // products, so a product "absent" from the table is still on the page.
        $this->actingAs($mine)->get('/stock-reports')
            ->assertOk()
            ->assertSee('id="stock-report-'.$ra.'"', false)
            ->assertDontSee('id="stock-report-'.$rb.'"', false);

        $this->actingAs(User::factory()->admin()->create())->get('/stock-reports')
            ->assertOk()
            ->assertSee('id="stock-report-'.$ra.'"', false)
            ->assertSee('id="stock-report-'.$rb.'"', false);
    }
}
