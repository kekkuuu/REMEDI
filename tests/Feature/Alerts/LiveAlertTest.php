<?php

namespace Tests\Feature\Alerts;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bell is live by POLLING /alerts (every 15s, and immediately after a
 * checkout or any confirm-dialog action -- see layouts/app.blade.php). That
 * only works if the very next poll after a stock change already carries the
 * change, rather than a cached payload from before it: checkout calls
 * AlertService::forget() for exactly this. These pin that server half.
 */
class LiveAlertTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock, int $reorder): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Medicine']);
        $p = Product::create(['name' => 'LIVE TAB '.$stock, 'sku' => 'LIVE'.$stock, 'category_id' => $cat->id,
            'unit' => 'BOX', 'selling_price' => 10, 'reorder_level' => $reorder]);
        ProductBatch::create(['product_id' => $p->id, 'batch_number' => 'LV'.$stock, 'quantity' => $stock,
            'qty_received' => $stock, 'expiry_date' => now()->addYear(), 'received_date' => now()->subDay()]);

        return $p;
    }

    private function lowStockCount(): int
    {
        // A kind with nothing open is left out of the payload entirely.
        return (int) (collect($this->getJson('/alerts')->assertOk()->json('alerts'))
            ->firstWhere('kind', 'low_stock')['count'] ?? 0);
    }

    public function test_a_sale_that_drops_stock_below_reorder_shows_on_the_very_next_poll(): void
    {
        $product = $this->product(stock: 12, reorder: 5);
        $cashier = User::factory()->create();
        $this->actingAs($cashier);

        // Warm the cache: this is the payload every open bell is holding.
        $this->assertSame(0, $this->lowStockCount());

        $this->postJson('/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 8]],
            'amount_paid' => 80,
            'idempotency_key' => 'live-1',
        ])->assertOk();

        // No waiting out the cache TTL: the next poll already has it.
        $this->assertSame(1, $this->lowStockCount());
    }

    /**
     * The receipt carries the alert it caused (2026-10-22), so the till pops it
     * at once instead of after a second trip to /alerts -- and with the SAME id
     * that poll lists, which is what stops the toast showing it twice.
     */
    public function test_the_receipt_carries_the_low_stock_alert_the_sale_caused(): void
    {
        $product = $this->product(stock: 12, reorder: 5);
        $this->actingAs(User::factory()->create());

        $alerts = $this->postJson('/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 8]],
            'amount_paid' => 80,
            'idempotency_key' => 'live-receipt-1',
        ])->assertOk()->json('alerts');

        $this->assertCount(1, $alerts);
        $this->assertSame('low_stock:'.$product->id.':4', $alerts[0]['id']);
        $this->assertSame('low_stock', $alerts[0]['kind']);
        $this->assertSame('4 BOX left · reorder at 5', $alerts[0]['body']);
        $this->assertNotEmpty($alerts[0]['when']);

        // The bell's next poll lists the very same item.
        $polled = collect($this->getJson('/alerts')->json('items'))->firstWhere('kind', 'low_stock');
        $this->assertSame($alerts[0]['id'], $polled['id']);
    }

    /** A sale that leaves the shelf healthy hands back no alert. */
    public function test_a_sale_that_leaves_stock_healthy_carries_no_alert(): void
    {
        $product = $this->product(stock: 50, reorder: 5);
        $this->actingAs(User::factory()->create());

        $this->postJson('/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'amount_paid' => 20,
            'idempotency_key' => 'live-receipt-2',
        ])->assertOk()->assertJsonPath('alerts', []);
    }

    public function test_the_poll_is_never_cached_by_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/alerts')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }
}
