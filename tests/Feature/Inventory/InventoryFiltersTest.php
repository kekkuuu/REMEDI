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
 * The Inventory page's monitoring filters: Out of Stock, and an expiry window
 * (a month or a from/to pair) on All / Expiring Soon / Expired.
 *
 * Asserted on `id="product-row-{id}"`, which only a rendered row emits: every
 * authenticated page also renders the bell, which names products, so a name
 * check would match the bell's copy and prove nothing.
 */
class InventoryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function product(string $name, int $reorder = 1): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => $reorder,
        ]);
    }

    private function batch(Product $product, string $expiry, int $qty = 10): ProductBatch
    {
        return ProductBatch::create([
            'batch_number' => 'B'.substr(md5($product->name.$expiry), 0, 6),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => '2026-01-01',
        ]);
    }

    private function row(Product $p): string
    {
        return 'id="product-row-'.$p->id.'"';
    }

    private function list(string $query)
    {
        return $this->actingAs($this->user())->get('/inventory?'.$query)->assertOk();
    }

    // ── Out of Stock ─────────────────────────────────────────────────────

    public function test_out_of_stock_lists_only_products_with_nothing_on_the_shelf(): void
    {
        $empty = $this->product('Empty Shelf');
        $stocked = $this->product('Stocked Shelf', reorder: 1);
        $this->batch($stocked, '2027-06-01');

        $this->list('filter=out_of_stock')
            ->assertSee($this->row($empty), false)
            ->assertDontSee($this->row($stocked), false);
    }

    public function test_a_product_whose_batch_is_emptied_counts_as_out_of_stock(): void
    {
        $product = $this->product('Sold Down');
        $this->batch($product, '2027-06-01', qty: 0);

        $this->list('filter=out_of_stock')->assertSee($this->row($product), false);
    }

    public function test_a_shelf_of_only_expired_units_is_expired_not_out_of_stock(): void
    {
        $product = $this->product('All Expired');
        $this->batch($product, '2026-08-01');

        $this->list('filter=out_of_stock')->assertDontSee($this->row($product), false);
        $this->list('filter=expired')->assertSee($this->row($product), false);
    }

    public function test_the_out_of_stock_badge_replaces_low_stock_at_zero(): void
    {
        $this->product('Nothing Here');

        $html = $this->list('filter=out_of_stock')->getContent();

        $this->assertStringContainsString('Out of Stock</span>', $html);
    }

    // ── Expiry window ────────────────────────────────────────────────────

    public function test_a_month_narrows_the_expired_list_to_batches_that_expired_in_it(): void
    {
        $july = $this->product('Expired July');
        $august = $this->product('Expired August');
        $this->batch($july, '2026-07-10');
        $this->batch($august, '2026-08-10');

        $this->list('filter=expired&expiry_month=2026-07')
            ->assertSee($this->row($july), false)
            ->assertDontSee($this->row($august), false);

        // And with no window both are there.
        $this->list('filter=expired')
            ->assertSee($this->row($july), false)
            ->assertSee($this->row($august), false);
    }

    public function test_a_date_range_narrows_the_expiring_list_and_reaches_past_the_ninety_day_horizon(): void
    {
        $soon = $this->product('Soon');
        $later = $this->product('Later');
        $this->batch($soon, '2026-10-05');     // 19 days away: inside the 90-day view
        $this->batch($later, '2027-03-15');    // months away: outside it

        // Without a window the tab is the 90-day planning view.
        $this->list('filter=expiring')
            ->assertSee($this->row($soon), false)
            ->assertDontSee($this->row($later), false);

        // With one, the window IS the horizon -- "what expires in March".
        $this->list('filter=expiring&expiry_from=2027-03-01&expiry_to=2027-03-31')
            ->assertSee($this->row($later), false)
            ->assertDontSee($this->row($soon), false);
    }

    public function test_the_expiring_window_never_lists_already_expired_stock(): void
    {
        $expired = $this->product('Already Gone');
        $this->batch($expired, '2026-09-01');

        $this->list('filter=expiring&expiry_from=2026-08-01&expiry_to=2026-12-31')
            ->assertDontSee($this->row($expired), false);
    }

    public function test_all_with_a_window_lists_whatever_expires_in_it(): void
    {
        $in = $this->product('Inside');
        $out = $this->product('Outside');
        $this->batch($in, '2026-11-20');
        $this->batch($out, '2027-02-20');

        $this->list('filter=all&expiry_from=2026-11-01&expiry_to=2026-11-30')
            ->assertSee($this->row($in), false)
            ->assertDontSee($this->row($out), false);
    }

    public function test_a_window_open_on_one_side_still_filters(): void
    {
        $early = $this->product('Early');
        $late = $this->product('Late');
        $this->batch($early, '2026-10-01');
        $this->batch($late, '2027-05-01');

        $this->list('filter=all&expiry_from=2027-01-01')
            ->assertSee($this->row($late), false)
            ->assertDontSee($this->row($early), false);
    }

    public function test_a_reversed_window_is_put_the_right_way_round(): void
    {
        $product = $this->product('In The Middle');
        $this->batch($product, '2026-11-15');

        $this->list('filter=all&expiry_from=2026-12-01&expiry_to=2026-11-01')
            ->assertSee($this->row($product), false);
    }

    public function test_dates_beat_a_month_carried_beside_them(): void
    {
        $november = $this->product('November Batch');
        $december = $this->product('December Batch');
        $this->batch($november, '2026-11-10');
        $this->batch($december, '2026-12-10');

        $this->list('filter=all&expiry_month=2026-11&expiry_from=2026-12-01&expiry_to=2026-12-31')
            ->assertSee($this->row($december), false)
            ->assertDontSee($this->row($november), false);
    }

    public function test_unparseable_input_is_dropped_not_a_server_error(): void
    {
        $product = $this->product('Any Product');
        $this->batch($product, '2026-11-15');

        $this->list('filter=all&expiry_from=banana&expiry_to=2026-13-45&expiry_month=nope')
            ->assertSee($this->row($product), false);
    }

    public function test_the_window_is_ignored_on_tabs_it_means_nothing_on(): void
    {
        $low = $this->product('Running Low', reorder: 5);
        $this->batch($low, '2030-01-01', qty: 2);

        // The window excludes this batch entirely; the Low Stock tab must not care.
        $this->list('filter=low_stock&expiry_from=2026-01-01&expiry_to=2026-01-31')
            ->assertSee($this->row($low), false);
    }

    public function test_the_page_echoes_the_window_it_applied(): void
    {
        $html = $this->list('filter=expired&expiry_month=2026-07')->getContent();

        $this->assertStringContainsString('id="expiry-month" value="2026-07"', $html);

        $html = $this->list('filter=expired&expiry_from=2026-07-01&expiry_to=2026-07-31')->getContent();

        $this->assertStringContainsString('id="expiry-from" value="2026-07-01"', $html);
        $this->assertStringContainsString('id="expiry-to" value="2026-07-31"', $html);
    }

    public function test_the_expiry_bar_is_shown_only_on_the_tabs_it_applies_to(): void
    {
        foreach (['all', 'expiring', 'expired'] as $tab) {
            $html = $this->list('filter='.$tab)->getContent();
            $this->assertMatchesRegularExpression('/<div class="expiry-range" id="expiry-range"\s*>/', $html, "$tab shows the bar");
        }

        $html = $this->list('filter=low_stock')->getContent();
        $this->assertMatchesRegularExpression('/<div class="expiry-range" id="expiry-range"\s+hidden\s*>/', $html);
    }

    public function test_the_ajax_response_carries_the_window(): void
    {
        $in = $this->product('AJAX Inside');
        $out = $this->product('AJAX Outside');
        $this->batch($in, '2026-07-10');
        $this->batch($out, '2026-08-10');

        $json = $this->actingAs($this->user())
            ->getJson('/inventory?filter=expired&expiry_month=2026-07')
            ->assertOk()
            ->json();

        $this->assertStringContainsString($this->row($in), $json['html']);
        $this->assertStringNotContainsString($this->row($out), $json['html']);
    }
}
