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

    public function test_the_poll_is_never_cached_by_the_browser(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/alerts')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }
}
