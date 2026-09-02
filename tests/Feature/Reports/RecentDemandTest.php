<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's High/Low Demand cards must mean demand NOW.
 *
 * `recentDemand()` anchored its window to the newest row in `sales_history`,
 * which was right while that table ran up to the present. It stopped being
 * right the moment the imported record was cut back to the day before the
 * terminal went live: the anchor froze there, so the cards described the thirty
 * days ending at the handoff and could not see a single sale the till had taken
 * since -- eighteen days and 918 transactions, on the live install.
 *
 * Nothing failed, nothing errored, and the cards were full of plausible
 * products. That is what makes this worth a test: the only symptom was a number
 * that was quietly out of date.
 */
class RecentDemandTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    private function sellOnTerminal(Product $product, int $qty, string $on): void
    {
        $batch = ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => 500,
            'qty_received' => 500,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subYear()->toDateString(),
        ]);

        $sale = Sale::create([
            'transaction_no' => 'TXN-'.uniqid(),
            'user_id' => User::factory()->create()->id,
            'total_amount' => $qty * 10,
            'amount_paid' => $qty * 10,
            'change_due' => 0,
            'payment_voided' => false,
        ]);

        $sale->forceFill(['created_at' => $on, 'updated_at' => $on])->save();

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_batch_id' => $batch->id,
            'quantity' => $qty,
            'price' => 10,
            'subtotal' => $qty * 10,
        ]);
    }

    public function test_the_window_counts_terminal_sales_the_imported_record_cannot_see(): void
    {
        // The imported record stops three weeks back, as it does on the live
        // install: it ends the day before the terminal was switched on.
        $old = $this->product('Imported Favourite');

        SalesHistory::create([
            'product_sku' => $old->sku,
            'sale_date' => now()->subDays(25)->toDateString(),
            'quantity_sold' => 40,
        ]);

        // Since then the shop has been selling something else, on the till.
        $new = $this->product('Counter Favourite');
        $this->sellOnTerminal($new, 90, now()->subDays(3)->toDateString().' 10:00:00');

        $top = SalesHistory::recentDemand(30)['top'];

        $this->assertNotEmpty($top, 'the cards must not be empty when the till has been busy');
        $this->assertSame('Counter Favourite', $top->first()->name,
            'the busiest product of the last 30 days is the one the TERMINAL sold');
        $this->assertEqualsWithDelta(90, $top->first()->total_qty, 0.01);

        // The imported half is still counted, not replaced.
        $this->assertTrue($top->contains(fn ($row) => $row->name === 'Imported Favourite'));
    }

    public function test_a_sale_older_than_the_window_is_left_out(): void
    {
        $stale = $this->product('Last Season');
        $this->sellOnTerminal($stale, 500, now()->subDays(60)->toDateString().' 10:00:00');

        $recent = $this->product('This Month');
        $this->sellOnTerminal($recent, 5, now()->subDay()->toDateString().' 10:00:00');

        $top = SalesHistory::recentDemand(30)['top'];

        $this->assertSame('This Month', $top->first()->name);
        $this->assertFalse($top->contains(fn ($row) => $row->name === 'Last Season'),
            'a window anchored on today cannot reach back two months');
    }

    /** A checkout has to retire the cards, or they describe the sale before it. */
    public function test_a_checkout_retires_the_cached_window(): void
    {
        $product = $this->product('Sells Later');
        $this->sellOnTerminal($product, 4, now()->subDay()->toDateString().' 10:00:00');

        $this->assertEqualsWithDelta(4, SalesHistory::recentDemand(30)['top']->first()->total_qty, 0.01);

        $this->sellOnTerminal($product, 20, now()->toDateString().' 11:00:00');
        SalesHistory::bumpCacheVersion();   // what PosController::checkout does

        $this->assertEqualsWithDelta(24, SalesHistory::recentDemand(30)['top']->first()->total_qty, 0.01,
            'the cards still hold the figure from before the sale');
    }
}
