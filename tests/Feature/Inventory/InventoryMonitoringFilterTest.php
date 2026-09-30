<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Inventory monitoring filters (2026-09-30): an Out of Stock tab, and an
 * expiry window (a month or a from/to pair) that narrows any tab to products
 * with stock expiring inside it. Rows are asserted by id -- every page also
 * renders the bell, which names products too.
 */
class InventoryMonitoringFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, int $reorder = 5): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.uniqid(),
            'category_id' => Category::firstOrCreate(['name' => 'General Merchandise'])->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => $reorder,
        ]);
    }

    private function batch(Product $product, int $qty, string $expiry): ProductBatch
    {
        return ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => max($qty, 1),
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => '2025-01-01',
        ]);
    }

    private function inventory(array $query)
    {
        return $this->actingAs(User::factory()->admin()->create())->get(route('inventory.index', $query));
    }

    public function test_out_of_stock_lists_only_empty_shelves(): void
    {
        $empty = $this->product('Empty Shelf');
        $this->batch($empty, 0, '2027-06-01');
        $low = $this->product('Low But There', 50);
        $this->batch($low, 3, '2027-06-01');

        $this->inventory(['filter' => 'out_of_stock'])->assertOk()
            ->assertSee('id="product-row-'.$empty->id.'"', false)
            ->assertDontSee('id="product-row-'.$low->id.'"', false);

        // Both are still Low Stock, which counts zero as low.
        $this->inventory(['filter' => 'low_stock'])
            ->assertSee('id="product-row-'.$empty->id.'"', false)
            ->assertSee('id="product-row-'.$low->id.'"', false);
    }

    public function test_expired_in_a_month(): void
    {
        $august = $this->product('Expired In August');
        $this->batch($august, 10, '2026-08-20');
        $july = $this->product('Expired In July');
        $this->batch($july, 10, '2026-07-10');

        $this->inventory(['filter' => 'expired', 'expiry_from' => '2026-08-01', 'expiry_to' => '2026-08-31'])->assertOk()
            ->assertSee('id="product-row-'.$august->id.'"', false)
            ->assertDontSee('id="product-row-'.$july->id.'"', false);
    }

    public function test_expiring_with_a_window_replaces_the_90_day_horizon(): void
    {
        $far = $this->product('Expires Next Spring');
        $this->batch($far, 10, '2027-04-15'); // ~200 days out

        // Default Expiring Soon is 90 days: not listed.
        $this->inventory(['filter' => 'expiring'])
            ->assertDontSee('id="product-row-'.$far->id.'"', false);

        $this->inventory(['filter' => 'expiring', 'expiry_from' => '2027-04-01', 'expiry_to' => '2027-04-30'])
            ->assertSee('id="product-row-'.$far->id.'"', false);
    }

    public function test_the_window_narrows_the_all_tab_and_ignores_empty_batches(): void
    {
        $inside = $this->product('Stock Expiring In Window');
        $this->batch($inside, 5, '2026-11-10');
        $outside = $this->product('Stock Expiring Later');
        $this->batch($outside, 5, '2027-03-10');
        $soldOut = $this->product('Empty Batch In Window');
        $this->batch($soldOut, 0, '2026-11-12');

        $this->inventory(['expiry_from' => '2026-11-01', 'expiry_to' => '2026-11-30'])
            ->assertSee('id="product-row-'.$inside->id.'"', false)
            ->assertDontSee('id="product-row-'.$outside->id.'"', false)
            ->assertDontSee('id="product-row-'.$soldOut->id.'"', false);
    }

    public function test_a_reversed_window_is_put_in_order(): void
    {
        $inside = $this->product('Stock Expiring In Window');
        $this->batch($inside, 5, '2026-11-10');

        $this->inventory(['expiry_from' => '2026-11-30', 'expiry_to' => '2026-11-01'])
            ->assertSee('id="product-row-'.$inside->id.'"', false)
            ->assertSee('value="2026-11-01"', false);
    }

    public function test_an_unparseable_date_is_refused_not_ignored(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('inventory.index', ['expiry_from' => 'banana']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_from');
    }
}
