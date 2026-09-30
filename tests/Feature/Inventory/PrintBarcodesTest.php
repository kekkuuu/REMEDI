<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Print Barcodes (BarcodeController, 2026-09-30). The labels are drawn in the
 * browser, so what the server owes the page is the right PRODUCTS: active
 * ones only, the SKU exactly as stored, and admins only.
 */
class PrintBarcodesTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, Category $category, string $sku): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '16.72',
            'reorder_level' => 5,
        ]);
    }

    public function test_only_an_admin_can_open_it(): void
    {
        $this->get('/barcodes')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/barcodes')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/barcodes/products?category_id=1')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/barcodes')->assertOk()->assertSee('Print Barcodes');
    }

    public function test_the_product_page_button_preselects_that_product(): void
    {
        $cat = Category::create(['name' => 'Water & Beverages']);
        $product = $this->product('ACEITE ALCAMFORADO IPI 25ML', $cat, '4801351531223');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('products.edit', $product))
            ->assertSee(route('barcodes.index', ['product' => $product->id]), false);

        $seed = $this->seedOf($this->actingAs($admin)->get('/barcodes?product='.$product->id));
        $this->assertSame([[
            'id' => $product->id, 'name' => 'ACEITE ALCAMFORADO IPI 25ML', 'sku' => '4801351531223', 'price' => 16.72,
        ]], $seed['preselected']);
    }

    public function test_an_archived_product_is_not_preselected(): void
    {
        $product = $this->product('Gone', Category::create(['name' => 'Snacks']), '111');
        $product->delete();

        $seed = $this->seedOf($this->actingAs(User::factory()->admin()->create())->get('/barcodes?product='.$product->id));
        $this->assertSame([], $seed['preselected']);
    }

    public function test_a_whole_category_lists_its_active_products_only(): void
    {
        $cat = Category::create(['name' => 'Water & Beverages']);
        $other = Category::create(['name' => 'Snacks']);
        $a = $this->product('ABSOLUTE 6L', $cat, '4807995970241');
        $b = $this->product('ABSOLUTE 8L', $cat, '4808896606039');
        $this->product('ELSEWHERE', $other, '222');
        $this->product('ARCHIVED', $cat, '333')->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/barcodes/products?category_id='.$cat->id)
            ->assertOk()
            ->assertExactJson(['products' => [
                ['id' => $a->id, 'name' => 'ABSOLUTE 6L', 'sku' => '4807995970241', 'price' => 16.72],
                ['id' => $b->id, 'name' => 'ABSOLUTE 8L', 'sku' => '4808896606039', 'price' => 16.72],
            ]]);
    }

    public function test_a_missing_or_archived_category_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = Category::create(['name' => 'Old']);
        $cat->delete();

        $this->actingAs($admin)->getJson('/barcodes/products')->assertStatus(422);
        $this->actingAs($admin)->getJson('/barcodes/products?category_id='.$cat->id)->assertStatus(422);
        $this->actingAs($admin)->getJson('/barcodes/products?category_id[]=1')->assertStatus(422);
        $this->actingAs($admin)->getJson('/barcodes?product=banana')->assertStatus(422);
    }

    /** @return array<string, mixed> */
    private function seedOf($response): array
    {
        $response->assertOk();
        preg_match('#<script type="application/json" id="bcSeed">(.*?)</script>#s', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the page carries its seed');

        return json_decode($m[1], true);
    }
}
