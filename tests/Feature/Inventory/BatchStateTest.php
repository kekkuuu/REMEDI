<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a batch reports about itself, and who may write it off.
 *
 * WRITING IT OFF. Marking a batch returned sets quantity to 0. Both Return
 * buttons are gated on ProductBatch::$is_returnable, but the endpoint behind
 * them was not -- so a POST could zero any batch at all. On the live catalogue
 * that was 2,531 ineligible batches holding 491,379 units. The button being
 * gated is not the same thing as the endpoint being gated.
 *
 * WHAT IT REPORTS. ProductBatch caches is_expired / is_sellable /
 * return_status into $derivedMemo for the length of a request, which is right
 * while an instance is read-only -- that memo took ~10k Carbon operations off
 * the dashboard. It was wrong the moment the instance was WRITTEN to: an
 * expired batch whose expiry_date was moved into the future kept answering
 * is_expired = true, and so is_sellable = false, on the same instance. Nothing
 * in the app reads an accessor after a write today, which is the only reason it
 * had not surfaced; it was a trap laid for the next person. expiry_date is the
 * field that matters most, because it decides whether the till may dispense the
 * batch (scopeSellable / $is_sellable).
 */
class BatchStateTest extends TestCase
{
    use RefreshDatabase;

    private function batch(int $qty, string $expiry, string $category = 'General Merchandise'): ProductBatch
    {
        $cat = Category::firstOrCreate(['name' => $category]);

        $product = Product::create([
            'name' => 'Item '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $cat->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);

        return ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => now()->subDays(200)->toDateString(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ── Returning stock to the supplier ──────────────────────────────────

    public function test_a_batch_inside_its_return_window_can_be_returned(): void
    {
        // Non-pharma becomes returnable within 10 days of expiry.
        $batch = $this->batch(40, now()->addDays(3)->toDateString());
        $this->assertTrue($batch->is_returnable, 'precondition');

        $this->actingAs($this->admin())
            ->patch('/batches/'.$batch->id.'/return')
            ->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertNotNull($batch->returned_at);
        $this->assertSame(0, $batch->quantity);
    }

    public function test_it_refuses_a_batch_that_is_not_returnable(): void
    {
        // Expires in two years: nowhere near its return window.
        $batch = $this->batch(300, now()->addDays(700)->toDateString());
        $this->assertFalse($batch->is_returnable, 'precondition');

        $this->actingAs($this->admin())
            ->patch('/batches/'.$batch->id.'/return')
            ->assertSessionHasErrors('batch');

        $batch->refresh();
        $this->assertNull($batch->returned_at);
        $this->assertSame(300, $batch->quantity, 'sellable stock must not be written off');
    }

    public function test_it_refuses_a_batch_that_was_already_returned(): void
    {
        $batch = $this->batch(40, now()->addDays(3)->toDateString());

        $this->actingAs($this->admin())->patch('/batches/'.$batch->id.'/return');
        $first = $batch->fresh()->returned_at;

        $this->actingAs($this->admin())
            ->patch('/batches/'.$batch->id.'/return')
            ->assertSessionHasErrors('batch');

        $this->assertEquals($first, $batch->fresh()->returned_at, 'the original return date stands');
    }

    /** The AJAX path gets 422 with a message, not a redirect. */
    public function test_the_refusal_answers_json_for_the_confirm_dialog(): void
    {
        $batch = $this->batch(300, now()->addDays(700)->toDateString());

        $this->actingAs($this->admin())
            ->patchJson('/batches/'.$batch->id.'/return')
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ── Derived state after a write ──────────────────────────────────────

    public function test_expiry_state_follows_an_update_on_the_same_instance(): void
    {
        $batch = $this->batch(50, now()->subDays(10)->toDateString());

        // Read first: this is what fills the memo.
        $this->assertTrue($batch->is_expired);
        $this->assertFalse($batch->is_sellable);

        $batch->update(['expiry_date' => now()->addYear()->toDateString()]);

        $this->assertFalse($batch->is_expired, 'the batch is no longer expired');
        $this->assertTrue($batch->is_sellable, 'and the till may now sell it');
    }

    public function test_expiry_state_follows_a_refresh(): void
    {
        $batch = $this->batch(50, now()->subDays(10)->toDateString());
        $this->assertTrue($batch->is_expired);

        // Someone else moved the date -- a second request, a console command.
        ProductBatch::whereKey($batch->id)->update(['expiry_date' => now()->addYear()->toDateString()]);

        $batch->refresh();

        $this->assertFalse($batch->is_expired, 'refresh() must not answer from the old memo');
        $this->assertTrue($batch->is_sellable);
    }

    public function test_a_batch_written_off_stops_reporting_itself_as_sellable(): void
    {
        $batch = $this->batch(40, now()->addDays(3)->toDateString());
        $this->assertTrue($batch->is_sellable);

        // What markBatchReturned() does: the units have gone back to the supplier.
        $batch->update(['returned_at' => now(), 'quantity' => 0]);

        $this->assertFalse($batch->is_sellable, 'returned stock is not sellable stock');
    }

    public function test_the_memo_still_serves_repeated_reads(): void
    {
        $batch = $this->batch(50, now()->addYear()->toDateString());

        // Nothing has changed between these, so the answer must be stable --
        // the point of the memo is that this costs one computation, not four.
        $this->assertSame(
            [false, true, false, true],
            [$batch->is_expired, $batch->is_sellable, $batch->is_expired, $batch->is_sellable]
        );
    }
}
