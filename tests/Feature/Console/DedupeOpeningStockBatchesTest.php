<?php

namespace Tests\Feature\Console;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * batches:dedupe-opening-stock, exercised against every shape found while
 * sizing up the real bug on production: 2,637 of ~2,638 products carrying a
 * second, untouched OPENING-<sku> batch from a reseed/import that ran twice.
 * The command's whole job is picking the SAFE row to delete without ever
 * touching one a real sale or a real return points at -- these tests are
 * here specifically because a mistake in that logic deletes real history.
 */
class DedupeOpeningStockBatchesTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $cat = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => 'Item '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $cat->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    private function batch(Product $product, string $batchNumber, string $receivedDate, int $qty = 20, ?string $returnedAt = null): ProductBatch
    {
        $batch = ProductBatch::create([
            'batch_number' => $batchNumber,
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => $receivedDate,
        ]);

        if ($returnedAt) {
            $batch->forceFill(['returned_at' => $returnedAt])->save();
        }

        return $batch;
    }

    private function sell(Product $product, ProductBatch $batch, int $qty = 1): void
    {
        $sale = Sale::create([
            'transaction_no' => 'TXN-'.uniqid(),
            'user_id' => User::factory()->create()->id,
            'total_amount' => $qty * 10,
            'amount_paid' => $qty * 10,
            'change_due' => 0,
            'payment_voided' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_batch_id' => $batch->id,
            'quantity' => $qty,
            'price' => 10,
            'subtotal' => $qty * 10,
        ]);
    }

    public function test_the_untouched_newer_row_is_deleted_and_the_older_kept(): void
    {
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP1', '2026-08-15');
        $newer = $this->batch($product, 'OPENING-DUP1', '2026-09-09');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $older->id]);
        $this->assertDatabaseMissing('product_batches', ['id' => $newer->id]);
    }

    public function test_a_row_with_real_sales_is_never_deleted(): void
    {
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP2', '2026-08-15');
        $newer = $this->batch($product, 'OPENING-DUP2', '2026-09-09');
        $this->sell($product, $older);

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $older->id]);
        $this->assertDatabaseMissing('product_batches', ['id' => $newer->id]);
    }

    public function test_a_returned_row_is_never_deleted(): void
    {
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP3', '2026-08-15', 20, now()->toDateTimeString());
        $newer = $this->batch($product, 'OPENING-DUP3', '2026-09-09');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $older->id]);
        $this->assertDatabaseMissing('product_batches', ['id' => $newer->id]);
    }

    public function test_the_command_falls_back_to_deleting_the_older_row_when_only_the_newer_one_is_clean(): void
    {
        // The inverse of the real-world pattern: here the NEWER row is the one
        // with a sale against it, so the older, untouched row is the safe one
        // to remove instead of blindly always deleting "the later date".
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP4', '2026-08-15');
        $newer = $this->batch($product, 'OPENING-DUP4', '2026-09-09');
        $this->sell($product, $newer);

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseMissing('product_batches', ['id' => $older->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $newer->id]);
    }

    public function test_a_pair_where_both_rows_carry_history_is_left_alone(): void
    {
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP5', '2026-08-15');
        $newer = $this->batch($product, 'OPENING-DUP5', '2026-09-09');
        $this->sell($product, $older);
        $this->sell($product, $newer);

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $older->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $newer->id]);
    }

    public function test_a_group_of_three_is_left_alone_as_ambiguous(): void
    {
        $product = $this->product();
        $a = $this->batch($product, 'OPENING-DUP6', '2026-08-15');
        $b = $this->batch($product, 'OPENING-DUP6', '2026-09-01');
        $c = $this->batch($product, 'OPENING-DUP6', '2026-09-09');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $a->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $b->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $c->id]);
    }

    public function test_a_tied_received_date_is_left_alone_as_ambiguous(): void
    {
        $product = $this->product();
        $a = $this->batch($product, 'OPENING-DUP7', '2026-08-15');
        $b = $this->batch($product, 'OPENING-DUP7', '2026-08-15');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $a->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $b->id]);
    }

    public function test_without_apply_it_reports_but_deletes_nothing(): void
    {
        $product = $this->product();
        $older = $this->batch($product, 'OPENING-DUP8', '2026-08-15');
        $newer = $this->batch($product, 'OPENING-DUP8', '2026-09-09');

        $this->artisan('batches:dedupe-opening-stock')->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $older->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $newer->id]);
    }

    public function test_applying_writes_one_audit_entry(): void
    {
        $product = $this->product();
        $this->batch($product, 'OPENING-DUP9', '2026-08-15');
        $this->batch($product, 'OPENING-DUP9', '2026-09-09');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1, AuditTrail::where('action', 'Deleted')
            ->where('details', 'like', '%duplicate opening-stock%')->count());
    }

    public function test_a_product_with_no_duplicates_is_untouched(): void
    {
        $product = $this->product();
        $only = $this->batch($product, 'OPENING-SOLO', '2026-08-15');

        $this->artisan('batches:dedupe-opening-stock', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('product_batches', ['id' => $only->id]);
    }
}
