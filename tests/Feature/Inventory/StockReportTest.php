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
