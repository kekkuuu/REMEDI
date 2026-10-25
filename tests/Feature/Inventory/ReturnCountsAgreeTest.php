<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A count and the list it links to must describe the same set (2026-10-25,
 * found by a logic audit): the bell's "Return window missed" counted medicine
 * only (120) while linking to the Inventory "Fail to Return" tab (185, which
 * also lists other products holding expired stock), and the dashboard's
 * Medicine / Other Product Returns cards each counted one side but linked to
 * lists of both. Now the bell uses the tab's rule (ProductBatch::missed_return)
 * and the cards link with ?medicine=1 / ?medicine=0.
 */
class ReturnCountsAgreeTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $category, int $expiresInDays): Product
    {
        $cat = Category::firstOrCreate(['name' => $category]);
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $cat->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
        ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => 20,
            'qty_received' => 20,
            'unit_cost' => 1,
            'expiry_date' => now()->addDays($expiresInDays)->toDateString(),
            'received_date' => now()->subDays(400)->toDateString(),
        ]);

        return $product;
    }

    /** @return list<int> product ids listed on one Inventory view */
    private function listed(User $admin, string $query): array
    {
        $html = $this->actingAs($admin)->getJson('/inventory?'.$query)->assertOk()->json('html');
        preg_match_all('/id="product-row-(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function test_the_bells_missed_return_count_matches_the_list_it_opens(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = $this->product('Amoxicillin 500mg Cap', Category::MEDICINE, 40);   // under 90 days: missed
        $snack = $this->product('Potato Chips 60g', 'Snacks & Confectionery', -5);      // expired on the shelf
        $fresh = $this->product('Bottled Water 1L', 'Water & Beverages', 200);           // fine

        $alerts = collect(app(AlertService::class)->payload()['alerts'])->pluck('count', 'kind');
        $listed = $this->listed($admin, 'filter=fail_to_return');

        $this->assertEqualsCanonicalizing([$medicine->id, $snack->id], $listed);
        $this->assertSame(count($listed), $alerts['fail_to_return']);
        $this->assertNotContains($fresh->id, $listed);
    }

    public function test_the_medicine_narrowing_splits_a_list_by_the_medicine_rule(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = $this->product('Amoxicillin 500mg Cap', Category::MEDICINE, 40);
        $snack = $this->product('Potato Chips 60g', 'Snacks & Confectionery', -5);

        $this->assertSame([$medicine->id], $this->listed($admin, 'filter=fail_to_return&medicine=1'));
        $this->assertSame([$snack->id], $this->listed($admin, 'filter=fail_to_return&medicine=0'));

        $page = $this->actingAs($admin)->get('/inventory?filter=fail_to_return&medicine=1')->assertOk()->getContent();
        $this->assertStringContainsString('Showing medicine only', $page);
        // The tabs carry it, so switching tab keeps the narrowing.
        $this->assertMatchesRegularExpression('/filter=low_stock[^"]*medicine=1/', $page);
    }

    public function test_an_unknown_medicine_value_is_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $this->product('Amoxicillin 500mg Cap', Category::MEDICINE, 40);
        $this->product('Potato Chips 60g', 'Snacks & Confectionery', -5);

        $this->assertCount(2, $this->listed($admin, 'filter=fail_to_return&medicine=banana'));
        $this->actingAs($admin)->getJson('/inventory?filter=fail_to_return&medicine[]=1')->assertOk();
    }
}
