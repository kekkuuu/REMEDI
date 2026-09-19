<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Delete became Archive.
 *
 * Every "Delete" button on products, batches, categories and user accounts now
 * stamps `archived_at` instead of removing a row. What these assert is the two
 * halves of that promise: the record really does leave the working lists, the
 * till and the alerts -- and nothing that refers to it is lost, so it can come
 * back with Restore.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function product(string $name = 'Archivable', string $sku = 'SKU-ARC-1', ?Category $category = null): Product
    {
        $category ??= Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    private function batch(Product $product, string $number = 'B-ARC', int $qty = 10): ProductBatch
    {
        return ProductBatch::create([
            'batch_number' => $number,
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDay()->toDateString(),
        ]);
    }

    // ── Products ─────────────────────────────────────────────────────────

    public function test_archiving_a_product_takes_it_and_its_batches_off_every_list_but_deletes_nothing(): void
    {
        $product = $this->product();
        $batch = $this->batch($product);

        $this->actingAs($this->admin())->delete('/products/'.$product->id)->assertSessionHasNoErrors();

        $this->assertFalse(Product::whereKey($product->id)->exists());
        $this->assertFalse(ProductBatch::whereKey($batch->id)->exists());

        // Still in the tables, stamped -- nothing was removed.
        $this->assertNotNull(Product::withTrashed()->find($product->id)->archived_at);
        $this->assertNotNull(ProductBatch::withTrashed()->find($batch->id)->archived_at);
    }

    public function test_an_archived_product_is_gone_from_the_product_list_and_present_in_the_archived_one(): void
    {
        $admin = $this->admin();
        $product = $this->product('Vanishing Item', 'SKU-VANISH');

        $this->actingAs($admin)->delete('/products/'.$product->id);

        // Asserted on the row's own SKU cell, not the name: every page also
        // renders the bell, whose activity feed says "Archived product:
        // Vanishing Item", so the name is on the page either way.
        $row = '<small>SKU: SKU-VANISH</small>';

        $this->actingAs($admin)->get('/products')->assertOk()->assertDontSee($row, false);
        $this->actingAs($admin)->get('/products?archived=1')->assertOk()->assertSee($row, false);
    }

    public function test_an_archived_product_cannot_be_sold(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->batch($product);

        $this->actingAs($admin)->delete('/products/'.$product->id);

        $this->actingAs($admin)->postJson('/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'amount_paid' => 100,
        ])->assertStatus(422);
    }

    public function test_an_archived_product_leaves_the_inventory(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->batch($product);

        $this->actingAs($admin)->get('/inventory')->assertSee('id="product-row-'.$product->id.'"', false);

        $this->actingAs($admin)->delete('/products/'.$product->id);

        $this->actingAs($admin)->get('/inventory')->assertDontSee('id="product-row-'.$product->id.'"', false);
    }

    /**
     * The point of archiving over deleting: sales_history keys on the SKU with
     * no foreign key, and every revenue report joins it to products. Deleting
     * the product silently dropped its revenue from all of them; archiving
     * must not.
     */
    public function test_archiving_a_product_leaves_its_sales_history_intact(): void
    {
        $product = $this->product('History Item', 'SKU-HIST');
        SalesHistory::create([
            'product_sku' => 'SKU-HIST',
            'sale_date' => now()->subMonth()->toDateString(),
            'quantity_sold' => 7,
        ]);

        $this->actingAs($this->admin())->delete('/products/'.$product->id)->assertSessionHasNoErrors();

        $this->assertSame(1, SalesHistory::where('product_sku', 'SKU-HIST')->count());

        // The raw join every revenue aggregate uses does not see the soft-delete
        // scope, so the product still prices its own history.
        $this->assertEquals(
            10,
            DB::table('products')->where('sku', 'SKU-HIST')->value('selling_price')
        );
    }

    public function test_an_archived_products_sku_stays_reserved(): void
    {
        $admin = $this->admin();
        $product = $this->product('Reserved', 'SKU-RESERVED');

        $this->actingAs($admin)->delete('/products/'.$product->id);

        // A new product must not inherit the archived one's history and forecast.
        $this->actingAs($admin)->post('/products', [
            'name' => 'Impostor',
            'sku' => 'SKU-RESERVED',
            'category_id' => $product->category_id,
            'unit' => 'PCS',
            'selling_price' => '5.00',
            'reorder_level' => 1,
        ])->assertSessionHasErrors('sku');
    }

    public function test_restoring_a_product_brings_back_the_batches_archived_with_it_but_not_earlier_ones(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $withProduct = $this->batch($product, 'B-WITH');
        $earlier = $this->batch($product, 'B-EARLIER');

        // Archived on its own, before the product was.
        $this->actingAs($admin)->delete('/batches/'.$earlier->id);
        $this->travel(1)->minutes();
        $this->actingAs($admin)->delete('/products/'.$product->id);

        $this->actingAs($admin)->patch('/products/'.$product->id.'/restore')->assertSessionHasNoErrors();

        $this->assertTrue(Product::whereKey($product->id)->exists());
        $this->assertTrue(ProductBatch::whereKey($withProduct->id)->exists(), 'archived WITH the product: comes back');
        $this->assertFalse(ProductBatch::whereKey($earlier->id)->exists(), 'archived on its own first: stays archived');
    }

    public function test_a_product_cannot_be_restored_into_an_archived_category(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Seasonal']);
        $product = $this->product('Seasonal Item', 'SKU-SEASON', $category);

        $this->actingAs($admin)->delete('/products/'.$product->id);
        $this->actingAs($admin)->delete('/categories/'.$category->id)->assertSessionHasNoErrors();

        $this->actingAs($admin)->patch('/products/'.$product->id.'/restore')->assertSessionHasErrors('product');

        $this->assertFalse(Product::whereKey($product->id)->exists(), 'still archived');
    }

    // ── Batches ──────────────────────────────────────────────────────────

    public function test_an_archived_batch_no_longer_counts_as_stock_and_can_be_restored(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $keep = $this->batch($product, 'B-KEEP', 4);
        $gone = $this->batch($product, 'B-GONE', 6);

        $this->assertSame(10, (int) $product->fresh()->total_stock);

        $this->actingAs($admin)->delete('/batches/'.$gone->id)->assertSessionHasNoErrors();

        $this->assertSame(4, (int) $product->fresh()->total_stock, 'archived stock is off the shelf');
        $this->actingAs($admin)->get('/products/'.$product->id.'/edit')->assertOk()->assertSee('Archived batches')->assertSee('B-GONE');

        $this->actingAs($admin)->patch('/batches/'.$gone->id.'/restore')->assertSessionHasNoErrors();

        $this->assertSame(10, (int) $product->fresh()->total_stock);
        $this->assertTrue(ProductBatch::whereKey($keep->id)->exists());
    }

    // ── Categories ───────────────────────────────────────────────────────

    public function test_an_empty_category_can_be_archived_and_restored(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Empty Shelf']);

        $this->actingAs($admin)->delete('/categories/'.$category->id)->assertSessionHasNoErrors();

        $this->assertFalse(Category::whereKey($category->id)->exists());
        $this->actingAs($admin)->get('/categories')->assertOk()->assertSee('Archived categories');

        $this->actingAs($admin)->patch('/categories/'.$category->id.'/restore')->assertSessionHasNoErrors();

        $this->assertTrue(Category::whereKey($category->id)->exists());
    }

    public function test_a_product_cannot_be_filed_under_an_archived_category(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Retired']);
        $this->actingAs($admin)->delete('/categories/'.$category->id);

        $this->actingAs($admin)->post('/products', [
            'name' => 'Misfiled',
            'sku' => 'SKU-MISFILED',
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '5.00',
            'reorder_level' => 1,
        ])->assertSessionHasErrors('category_id');
    }

    // ── Accounts ─────────────────────────────────────────────────────────

    public function test_an_archived_account_cannot_sign_in_and_a_restored_one_can(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->create(['email' => 'archived@remedi.com', 'role' => 'staff']);

        $this->actingAs($admin)->delete('/users/'.$cashier->id)->assertSessionHasNoErrors();

        auth()->logout();
        $this->post('/login', ['email' => 'archived@remedi.com', 'password' => 'password']);
        $this->assertGuest();

        $this->actingAs($admin)->patch('/users/'.$cashier->id.'/restore')->assertSessionHasNoErrors();

        auth()->logout();
        $this->post('/login', ['email' => 'archived@remedi.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($cashier->fresh());
    }

    public function test_archived_accounts_are_listed_apart_and_left_out_of_the_kpis(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->create(['name' => 'Gone Cashier', 'email' => 'gone.cashier@remedi.com', 'role' => 'staff']);

        $this->actingAs($admin)->delete('/users/'.$cashier->id);

        // The email, not the name: the bell's feed says "Archived user account:
        // Gone Cashier" on every page.
        $this->actingAs($admin)->get('/users')->assertOk()->assertDontSee('gone.cashier@remedi.com');
        $this->actingAs($admin)->get('/users?archived=1')->assertOk()->assertSee('gone.cashier@remedi.com');
    }

    // ── The buttons themselves ───────────────────────────────────────────

    public function test_no_delete_button_is_left_on_the_four_lists(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->batch($product);
        User::factory()->create(['role' => 'staff']);
        Category::create(['name' => 'Some Empty Category']);

        foreach (['/products', '/categories', '/users', '/products/'.$product->id.'/edit'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('data-confirm-label="Delete"', $html, "$url still offers Delete");
            $this->assertStringNotContainsString('data-confirm-label="Remove"', $html, "$url still offers Remove");
            $this->assertStringContainsString('data-confirm-label="Archive"', $html, "$url has no Archive");
        }
    }
}
