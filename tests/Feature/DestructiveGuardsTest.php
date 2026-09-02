<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DemandForecast;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The refusals that stand between an ordinary click and lost data.
 *
 * Every one of these guards exists because the thing it prevents either
 * happened on this install or was one request away, and REMEDI.md records them
 * one by one -- a cashier deleted along with the 25 sales they had rung up, a
 * category rename that silently reclassified 1,393 products and zeroed an alert
 * kind, an admin locking themselves out of the only role that can let them back
 * in. None of them had a test: they were verified by hand once and then trusted.
 *
 * They are cheap to assert and expensive to lose, which is the whole argument
 * for this file.
 */
class DestructiveGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function product(string $name = 'Guarded', string $sku = 'SKU-GUARD-1'): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    private function batch(Product $product, string $number = 'B-GUARD'): ProductBatch
    {
        return ProductBatch::create([
            'batch_number' => $number,
            'product_id' => $product->id,
            'quantity' => 10,
            'qty_received' => 10,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function sell(User $cashier, Product $product, ProductBatch $batch, string $txn): Sale
    {
        $sale = Sale::create([
            'transaction_no' => $txn,
            'user_id' => $cashier->id,
            'total_amount' => 10,
            'amount_paid' => 10,
            'change_due' => 0,
            'payment_voided' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_batch_id' => $batch->id,
            'quantity' => 1,
            'price' => 10,
            'subtotal' => 10,
        ]);

        return $sale;
    }

    // ── Accounts ─────────────────────────────────────────────────────────

    /**
     * `sales.user_id` was ON DELETE CASCADE, and `sale_items.sale_id` cascades
     * in turn, so deleting an account destroyed every transaction it had rung
     * up -- while reporting success and leaving the deducted stock deducted.
     * Two accounts went that way on this install; 25 sale ids are missing.
     */
    public function test_a_cashier_with_sales_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->create(['role' => 'staff']);
        $product = $this->product();
        $this->sell($cashier, $product, $this->batch($product), 'TXN-GUARD-1');

        $this->actingAs($admin)->delete('/users/'.$cashier->id)->assertSessionHasErrors('user');

        $this->assertTrue(User::whereKey($cashier->id)->exists(), 'the account must survive');
        $this->assertSame(1, Sale::count(), 'and so must the sale');
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete('/users/'.$admin->id)->assertSessionHasErrors('user');

        $this->assertTrue(User::whereKey($admin->id)->exists());
    }

    /** The admin pages are role:admin, so this lockout is unrecoverable in the UI. */
    public function test_an_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'staff',
        ])->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    /** profile.destroy is the stock Breeze route, live even though its card is hidden. */
    public function test_the_last_admin_cannot_delete_their_own_profile(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete('/profile', ['password' => 'password']);

        $this->assertTrue(User::whereKey($admin->id)->exists(), 'the only admin must survive');
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    // ── Categories ───────────────────────────────────────────────────────

    /**
     * is_medicine reads the category NAME, so renaming this one reclassifies
     * every product in it: 1,393 products stopped being medicine and an entire
     * alert kind went to zero when it was tried.
     */
    public function test_a_rule_driving_category_cannot_be_renamed(): void
    {
        $medicine = Category::firstOrCreate(['name' => Category::MEDICINE]);

        $this->actingAs($this->admin())
            ->put('/categories/'.$medicine->id, ['name' => 'Drugs'])
            ->assertSessionHasErrors();

        $this->assertSame(Category::MEDICINE, $medicine->fresh()->name);
    }

    public function test_a_category_holding_products_cannot_be_deleted(): void
    {
        $category = Category::firstOrCreate(['name' => 'Household']);
        $this->product('Attached', 'SKU-ATTACHED')->update(['category_id' => $category->id]);

        $this->actingAs($this->admin())->delete('/categories/'.$category->id)->assertSessionHasErrors();

        $this->assertTrue(Category::whereKey($category->id)->exists());
        $this->assertTrue(Product::where('sku', 'SKU-ATTACHED')->exists());
    }

    // ── Stock the sales record depends on ────────────────────────────────

    /**
     * product_batch_id is ON DELETE RESTRICT: letting the constraint fire
     * returns a 500 with raw SQL in the body, so the controller has to refuse
     * first and say why.
     */
    public function test_a_batch_a_sale_refers_to_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $batch = $this->batch($product);
        $this->sell($admin, $product, $batch, 'TXN-GUARD-2');

        $this->actingAs($admin)->delete('/batches/'.$batch->id)->assertSessionHasErrors('batch');

        $this->assertTrue(ProductBatch::whereKey($batch->id)->exists());
    }

    public function test_a_product_that_has_been_sold_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->sell($admin, $product, $this->batch($product), 'TXN-GUARD-3');

        $this->actingAs($admin)->delete('/products/'.$product->id)->assertSessionHasErrors();

        $this->assertTrue(Product::whereKey($product->id)->exists());
    }

    // ── The key with no foreign key ──────────────────────────────────────

    /**
     * Four tables key on `products.sku` as a plain string with no FK, so a
     * rename orphans them unless the controller re-points each one. It cost
     * PHP 909,358.82 of lifetime revenue on one product before it was fixed.
     */
    public function test_a_sku_rename_repoints_every_table_keyed_on_it(): void
    {
        $product = $this->product('Renamed', 'SKU-OLD-1');

        SalesHistory::create([
            'product_sku' => 'SKU-OLD-1',
            'sale_date' => now()->subMonth()->toDateString(),
            'quantity_sold' => 12,
        ]);

        DemandForecast::create([
            'product_sku' => 'SKU-OLD-1',
            'forecast_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'forecast_value' => 5,
            'lower_ci' => 3,
            'upper_ci' => 7,
            'generated_at' => now(),
        ]);

        DB::table('inventory_receipts')->insert([
            'product_sku' => 'SKU-OLD-1',
            'qty' => 30,
            'received_at' => now()->subMonths(2)->toDateString(),
        ]);

        DB::table('forecast_accuracy')->insert([
            'product_sku' => 'SKU-OLD-1',
            'mae' => 1.2, 'rmse' => 1.5, 'smape' => 30,
            'holdout_months' => 3, 'points_scored' => 3,
            'method' => 'arima', 'generated_at' => now(),
        ]);

        $this->actingAs($this->admin())->put('/products/'.$product->id, [
            'name' => $product->name,
            'sku' => 'SKU-NEW-1',
            'category_id' => $product->category_id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ])->assertSessionHasNoErrors();

        foreach (['sales_history', 'demand_forecasts', 'inventory_receipts', 'forecast_accuracy'] as $table) {
            $this->assertSame(0, DB::table($table)->where('product_sku', 'SKU-OLD-1')->count(),
                "$table still points at the old SKU");
            $this->assertSame(1, DB::table($table)->where('product_sku', 'SKU-NEW-1')->count(),
                "$table did not follow the rename");
        }
    }
}
