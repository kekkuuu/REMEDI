<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The till.
 *
 * REMEDI.md records a run of checkout bugs that each cost real money or real
 * stock: FEFO reaching for the MOST expired batch first, exact payment being
 * refused over float arithmetic, a cart naming one product twice being billed
 * for more than it deducted. Every one was found by hand, because none of this
 * was covered. These lock the fixed behaviour in.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{qty:int, expiry:string, returned?:mixed}>  $batches
     */
    private function product(string $name, string $price, array $batches): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => $price,
            'reorder_level' => 1,
        ]);

        foreach ($batches as $i => $batch) {
            ProductBatch::create([
                'batch_number' => 'B'.substr(md5($name.$i), 0, 6),
                'product_id' => $product->id,
                'quantity' => $batch['qty'],
                'qty_received' => $batch['qty'],
                'unit_cost' => 1,
                'expiry_date' => $batch['expiry'],
                'received_date' => now()->subDays(30)->toDateString(),
                'returned_at' => $batch['returned'] ?? null,
            ]);
        }

        return $product->fresh('batches');
    }

    private function cashier(): User
    {
        return User::factory()->create();
    }

    public function test_checkout_draws_from_the_soonest_expiring_batch_first(): void
    {
        $product = $this->product('FEFO Item', '10.00', [
            ['qty' => 3, 'expiry' => now()->addDays(10)->toDateString()],
            ['qty' => 10, 'expiry' => now()->addDays(200)->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
                'amount_paid' => 50,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $batches = $product->batches()->orderBy('expiry_date')->get();

        // The near batch is emptied before the far one is touched at all.
        $this->assertSame(0, (int) $batches[0]->quantity);
        $this->assertSame(8, (int) $batches[1]->quantity);

        // One cart line, two sale_items: batch traceability is kept in the data.
        $sale = Sale::latest('id')->first();
        $items = SaleItem::where('sale_id', $sale->id)->get();
        $this->assertCount(2, $items);
        $this->assertSame(5, (int) $items->sum('quantity'));

        // The stored subtotals must add up to the stored total, exactly.
        $this->assertEquals($sale->total_amount, $items->sum('subtotal'));
    }

    /**
     * A line FEFO split across two batches prints ONCE on the receipt.
     *
     * The data deliberately keeps one sale_item per batch drawn from -- that is
     * the batch traceability the test above asserts -- but a customer reading
     * "PARACETAMOL 5 x 10.00" above "PARACETAMOL 3 x 10.00" sees themselves
     * charged twice for one thing. `_receipt.blade.php` groups on
     * product_id|price; this proves the grouping against the real template
     * rather than against a description of it.
     *
     * Worth its own test because no sale in the live data splits a line: the
     * quantities are small against the batch sizes, so the rule is real but
     * unexercised outside here.
     */
    public function test_a_line_split_across_batches_prints_once_on_the_receipt(): void
    {
        $product = $this->product('Split Line Item', '10.00', [
            ['qty' => 5, 'expiry' => now()->addDays(10)->toDateString()],
            ['qty' => 5, 'expiry' => now()->addDays(200)->toDateString()],
        ]);

        $cashier = $this->cashier();

        $this->actingAs($cashier)
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 8]],
                'amount_paid' => 80,
            ])
            ->assertOk();

        $sale = Sale::latest('id')->first();

        // Precondition: the sale really is split, or this proves nothing.
        $this->assertCount(2, SaleItem::where('sale_id', $sale->id)->get());

        $html = $this->actingAs($cashier)->get('/pos/receipt/'.$sale->id)
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Split Line Item'),
            'the product is printed once, not once per batch');
        $this->assertStringContainsString('8 &times; &#8369;10.00', $html,
            'the printed line carries the SUMMED quantity');
        $this->assertStringContainsString(number_format($sale->total_amount, 2), $html);
    }

    public function test_expired_and_returned_stock_is_never_sold(): void
    {
        $product = $this->product('Mixed Item', '5.00', [
            ['qty' => 4, 'expiry' => now()->subDay()->toDateString()],
            ['qty' => 4, 'expiry' => now()->toDateString()],
            ['qty' => 4, 'expiry' => now()->addDays(90)->toDateString()],
            ['qty' => 4, 'expiry' => now()->addDays(90)->toDateString(), 'returned' => now()],
        ]);

        // 16 units physically on the shelf, 4 the till may actually sell. The
        // expiry date itself counts as expired, so today's batch is out too.
        $this->assertSame(16, (int) $product->total_stock);
        $this->assertSame(4, (int) $product->sellable_stock);

        $cashier = $this->cashier();

        $this->actingAs($cashier)
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 5]],
                'amount_paid' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($cashier)
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 4]],
                'amount_paid' => 20,
            ])
            ->assertOk();

        // All four came from the good batch. Nothing unsellable was touched --
        // and note FEFO did NOT reach for the earliest date, because that batch
        // is expired.
        $remaining = array_map('intval', $product->batches()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame([4, 4, 0, 4], $remaining);
    }

    public function test_exact_payment_is_accepted_on_a_price_that_floats_badly(): void
    {
        // 1.05 * 3 is 3.1500000000000004 in float arithmetic, which used to
        // refuse the customer's exact money at the counter.
        $product = $this->product('Centavo Item', '1.05', [
            ['qty' => 10, 'expiry' => now()->addDays(100)->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'amount_paid' => 3.15,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $sale = Sale::latest('id')->first();
        $this->assertEquals(3.15, (float) $sale->total_amount);
        $this->assertEquals(0.0, (float) $sale->change_due);
    }

    public function test_a_payment_one_centavo_short_is_refused(): void
    {
        $product = $this->product('Short Pay Item', '281.98', [
            ['qty' => 5, 'expiry' => now()->addDays(100)->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => 281.97,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Nothing charged, nothing deducted.
        $this->assertSame(0, Sale::count());
        $this->assertSame(5, (int) $product->fresh('batches')->sellable_stock);
    }

    public function test_a_cart_naming_the_same_product_twice_is_summed_before_the_stock_check(): void
    {
        // 5 + 5 against 6 in stock passed both lines individually, billed for
        // 10 and deducted 6.
        $product = $this->product('Duplicate Line Item', '100.00', [
            ['qty' => 6, 'expiry' => now()->addDays(100)->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5],
                    ['product_id' => $product->id, 'quantity' => 5],
                ],
                'amount_paid' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Sale::count());
        $this->assertSame(6, (int) $product->fresh('batches')->sellable_stock);
    }

    /**
     * A mistyped payment must be refused, not crash the till.
     *
     * sales.amount_paid is decimal(10,2) and MySQL runs with
     * STRICT_TRANS_TABLES, so anything over 99,999,999.99 is not clamped -- it
     * raises SQLSTATE[22003] and the request dies as a 500 with SQL in the
     * body. Verified against MySQL before the fix: "Out of range value for
     * column 'amount_paid' at row 1". The sale itself was never at risk (the
     * insert is inside DB::transaction, so it rolled back) but the register
     * showed a server error with a customer standing at it, and nine digits is
     * all it takes to get there.
     */
    public function test_a_payment_larger_than_the_column_is_refused_rather_than_crashing(): void
    {
        $product = $this->product('Bounded Payment', '10.00', [
            ['qty' => 5, 'expiry' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'amount_paid' => '100000000.00',   // decimal(10,2) stops at 99,999,999.99
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_paid');

        $this->assertSame(0, Sale::count(), 'no sale may be recorded from a refused payment');
    }

    /** The ceiling is a legal value: the bound must not be off by one. */
    public function test_a_payment_at_the_column_ceiling_still_goes_through(): void
    {
        $product = $this->product('Ceiling Payment', '10.00', [
            ['qty' => 5, 'expiry' => now()->addYear()->toDateString()],
        ]);

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => '99999999.99',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, Sale::count());
    }

    public function test_transaction_numbers_are_unique_and_increment_within_the_day(): void
    {
        $product = $this->product('Numbered Item', '1.00', [
            ['qty' => 10, 'expiry' => now()->addDays(100)->toDateString()],
        ]);

        $cashier = $this->cashier();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($cashier)->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => 1,
            ])->assertOk();
        }

        $numbers = Sale::orderBy('id')->pluck('transaction_no')->all();
        $prefix = 'TXN-'.now()->format('Ymd').'-';

        $this->assertSame($numbers, array_values(array_unique($numbers)));
        $this->assertSame([$prefix.'00001', $prefix.'00002', $prefix.'00003'], $numbers);
    }
}
