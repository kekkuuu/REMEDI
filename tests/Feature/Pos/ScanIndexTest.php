<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /pos/scan-index -- the list the till matches scans against in the browser
 * (PosController::scanIndex), so a scan does not wait 1.5-4 s on the network.
 *
 * What it must get right is what lookupBySku() already does: the stock it
 * quotes is SELLABLE stock (expired, expiring-today and returned batches do not
 * count -- the till may not sell them), archived products are absent, and both
 * the SKU and the printed barcode are there to match.
 */
class ScanIndexTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $attributes = [], array $batches = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);
        $product = Product::create(array_merge([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '12.50',
            'reorder_level' => 1,
        ], $attributes));

        foreach ($batches as $i => $batch) {
            ProductBatch::create([
                'batch_number' => 'B'.substr(md5($name.$i), 0, 6),
                'product_id' => $product->id,
                'quantity' => $batch['qty'],
                'qty_received' => $batch['qty'],
                'expiry_date' => $batch['expiry'],
                'received_date' => now()->subDays(30)->toDateString(),
                'returned_at' => $batch['returned'] ?? null,
            ]);
        }

        return $product;
    }

    /** @return array<int, array> rows keyed by product id */
    private function rows(User $user): array
    {
        $rows = $this->actingAs($user)->getJson('/pos/scan-index')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('products');

        return collect($rows)->keyBy(0)->all();
    }

    public function test_each_row_carries_what_a_scan_needs(): void
    {
        $product = $this->product('Scan Row', ['barcode' => '4806523434980'], [
            ['qty' => 7, 'expiry' => now()->addYear()->toDateString()],
        ]);

        $row = $this->rows(User::factory()->create())[$product->id];

        $this->assertSame([$product->id, 'Scan Row', 12.5, 7, $product->sku, '4806523434980', 7], $row);
    }

    /** Units on the shelf the till may not sell (expired) are counted apart, so a scan can say "expired". */
    public function test_expired_units_on_the_shelf_are_counted_apart_from_sellable_stock(): void
    {
        $product = $this->product('All Expired', [], [
            ['qty' => 10, 'expiry' => now()->subDays(3)->toDateString()],
            ['qty' => 4, 'expiry' => now()->subDays(40)->toDateString(), 'returned' => now()],
        ]);
        $user = User::factory()->create();

        $row = $this->rows($user)[$product->id];
        $this->assertSame(0, $row[3], 'nothing sellable');
        $this->assertSame(10, $row[6], 'ten expired units still on the shelf; the returned batch is not');

        $this->actingAs($user)->getJson('/pos/lookup?sku='.$product->sku)
            ->assertJsonPath('stock', 0)
            ->assertJsonPath('on_shelf', 10);
    }

    /** The same figure lookupBySku() quotes, from the same scope checkout uses. */
    public function test_stock_is_sellable_stock_only(): void
    {
        $product = $this->product('Mixed Shelf', [], [
            ['qty' => 5, 'expiry' => now()->addMonths(6)->toDateString()],
            ['qty' => 40, 'expiry' => now()->subDay()->toDateString()],                        // expired
            ['qty' => 30, 'expiry' => now()->toDateString()],                                  // expires today
            ['qty' => 20, 'expiry' => now()->addYear()->toDateString(), 'returned' => now()],  // sent back
        ]);
        $user = User::factory()->create();

        $this->assertSame(5, $this->rows($user)[$product->id][3]);
        $this->actingAs($user)->getJson('/pos/lookup?sku='.$product->sku)->assertJsonPath('stock', 5);
    }

    /** A barcode that only repeats the SKU is sent as null: the SKU already matches. */
    public function test_a_barcode_equal_to_the_sku_is_not_sent_twice(): void
    {
        $product = $this->product('Same Code', ['sku' => '4801812140441', 'barcode' => '4801812140441']);

        $this->assertNull($this->rows(User::factory()->create())[$product->id][5]);
    }

    public function test_archived_products_are_not_in_the_list(): void
    {
        $kept = $this->product('Still Sold');
        $gone = $this->product('Discontinued');
        $gone->delete();   // archives (SoftDeletes on archived_at)

        $rows = $this->rows(User::factory()->create());

        $this->assertArrayHasKey($kept->id, $rows);
        $this->assertArrayNotHasKey($gone->id, $rows);
    }

    /** Shared like the till itself: staff ring sales up too. */
    public function test_staff_can_load_it_and_a_guest_cannot(): void
    {
        $this->product('Anyone');

        // Guest first: the guard memoises the user for the rest of the method
        // once actingAs() has run (see Feature\Auth\DeactivationTest).
        $this->getJson('/pos/scan-index')->assertUnauthorized();
        $this->assertNotEmpty($this->rows(User::factory()->create()));
    }
}
