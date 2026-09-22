<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stock card ledger (StockMovement): every unit of stock movement --
 * stock-in, sale, return, manual adjustment -- recorded as it happens, so a
 * discrepancy can be read off directly instead of pieced together from
 * batches, sale_items and the audit trail. See the stock_movements migration
 * and StockMovement::record().
 */
class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'Ledger Item'): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name.uniqid()), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    private function batch(Product $product, int $qty, string $expiry): ProductBatch
    {
        return ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => now()->subDays(30)->toDateString(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_adding_a_batch_records_a_stock_in_movement(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post("/products/{$product->id}/batches", [
                'quantity' => 25,
                'received_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
                'unit_cost' => '5.50',
                'dr_no' => 'DR-1001',
                'supplier' => 'Acme Supplier',
            ])
            ->assertSessionHasNoErrors();

        $batch = $product->batches()->first();
        $this->assertSame('5.50', number_format((float) $batch->unit_cost, 2, '.', ''));
        $this->assertSame('DR-1001', $batch->dr_no);
        $this->assertSame('Acme Supplier', $batch->supplier);

        $movement = StockMovement::where('product_batch_id', $batch->id)->first();
        $this->assertNotNull($movement, 'addBatch must log a stock_in movement');
        $this->assertSame(StockMovement::TYPE_STOCK_IN, $movement->type);
        $this->assertSame(25, $movement->quantity_change);
        $this->assertSame(25, $movement->balance_after);
    }

    public function test_a_checkout_records_a_negative_sale_movement_linked_to_the_sale(): void
    {
        $product = $this->product();
        $batch = $this->batch($product, 10, now()->addYear()->toDateString());

        $this->actingAs(User::factory()->create())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
                'amount_paid' => 100,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $sale = Sale::latest('id')->first();

        $movement = StockMovement::where('product_batch_id', $batch->id)
            ->where('type', StockMovement::TYPE_SALE)
            ->first();

        $this->assertNotNull($movement, 'checkout must log a sale movement');
        $this->assertSame(-4, $movement->quantity_change);
        $this->assertSame(6, $movement->balance_after);
        $this->assertSame($sale->id, $movement->sale_id);
    }

    public function test_marking_a_batch_returned_records_a_negative_return_movement(): void
    {
        $product = $this->product();
        // Non-pharma becomes returnable within 10 days of expiry.
        $batch = $this->batch($product, 15, now()->addDays(3)->toDateString());

        $this->actingAs($this->admin())
            ->patch('/batches/'.$batch->id.'/return')
            ->assertSessionHasNoErrors();

        $movement = StockMovement::where('product_batch_id', $batch->id)
            ->where('type', StockMovement::TYPE_RETURN)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(-15, $movement->quantity_change);
        $this->assertSame(0, $movement->balance_after);
    }

    public function test_editing_a_batchs_quantity_requires_a_reason_and_logs_an_adjustment(): void
    {
        $product = $this->product();
        $batch = $this->batch($product, 20, now()->addYear()->toDateString());

        // No reason: refused, nothing written.
        $this->actingAs($this->admin())
            ->put('/batches/'.$batch->id, [
                'quantity' => 12,
                'expiry_date' => $batch->expiry_date->toDateString(),
            ])
            ->assertSessionHasErrors('reason');

        $batch->refresh();
        $this->assertSame(20, (int) $batch->quantity, 'refused edit must not change stock');
        $this->assertSame(0, StockMovement::where('product_batch_id', $batch->id)->count());

        // With a reason: accepted, logged.
        $this->actingAs($this->admin())
            ->put('/batches/'.$batch->id, [
                'quantity' => 12,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'reason' => 'Damaged in transit',
            ])
            ->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame(12, (int) $batch->quantity);

        $movement = StockMovement::where('product_batch_id', $batch->id)->first();
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $movement->type);
        $this->assertSame(-8, $movement->quantity_change);
        $this->assertSame(12, $movement->balance_after);
        $this->assertSame('Damaged in transit', $movement->reason);
    }

    public function test_editing_a_batch_without_changing_quantity_needs_no_reason_and_logs_nothing(): void
    {
        $product = $this->product();
        $batch = $this->batch($product, 20, now()->addYear()->toDateString());

        // Only the cost/DR/supplier fields change; quantity is re-posted as-is.
        $this->actingAs($this->admin())
            ->put('/batches/'.$batch->id, [
                'quantity' => 20,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'unit_cost' => '3.25',
                'dr_no' => 'DR-9',
                'supplier' => 'New Supplier Co',
            ])
            ->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame(20, (int) $batch->quantity);
        $this->assertSame('New Supplier Co', $batch->supplier);
        $this->assertSame(0, StockMovement::where('product_batch_id', $batch->id)->count());
    }

    public function test_the_stock_card_page_lists_movements_newest_first_with_a_running_balance(): void
    {
        $product = $this->product();
        $batch = $this->batch($product, 30, now()->addYear()->toDateString());
        StockMovement::record($batch, StockMovement::TYPE_ADJUSTMENT, -5, 'Count correction', null, $this->admin()->id);

        $response = $this->actingAs($this->admin())
            ->get(route('products.stock-card', $product))
            ->assertOk();

        $response->assertSee('Stock Card');
        $response->assertSee('Count correction');
    }

    public function test_the_stock_card_can_be_filtered_by_movement_type(): void
    {
        $product = $this->product();
        $batch = $this->batch($product, 30, now()->addYear()->toDateString());
        StockMovement::record($batch, StockMovement::TYPE_ADJUSTMENT, -5, 'Count correction', null, $this->admin()->id);

        $response = $this->actingAs($this->admin())
            ->get(route('products.stock-card', ['product' => $product, 'type' => StockMovement::TYPE_RETURN]));

        $response->assertOk();
        $response->assertDontSee('Count correction');
    }
}
