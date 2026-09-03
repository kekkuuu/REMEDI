<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale cannot have been rung up by an account that did not exist yet.
 *
 * `pos:backfill` resolved its cashier list ONCE, from every active user, and
 * then picked from it at random for every backdated sale -- so accounts created
 * on 2026-09-02 were credited with transactions going back to 2026-08-16. On
 * this install that was 611 of 874 rows, and it is visible in three places that
 * all print `$sale->user->name`: the Sales history list, /sales/{id}, and every
 * reprinted receipt. Nothing errored; the cashier column simply named someone
 * who had been hired a fortnight after the sale.
 *
 * Both halves are covered here: the command no longer writes such a row, and
 * `--fix-attribution` repairs the ones already written.
 */
class BackfillAttributionTest extends TestCase
{
    use RefreshDatabase;

    /** Enough of a catalogue for the backfill to have something to sell. */
    private function sellableProduct(): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => 'Backfill Item',
            'sku' => 'SKU-BACKFILL',
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);

        ProductBatch::create([
            'batch_number' => 'B-BACKFILL',
            'product_id' => $product->id,
            'quantity' => 5000,
            'qty_received' => 5000,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDays(60)->toDateString(),
        ]);

        // The command weights what it sells by the imported record's last 90
        // days, so without a history row there is nothing to put in a basket.
        SalesHistory::create([
            'product_sku' => $product->sku,
            'sale_date' => now()->subDays(10)->toDateString(),
            'quantity_sold' => 100,
        ]);

        return $product;
    }

    public function test_the_backfill_never_credits_a_sale_to_an_account_created_after_it(): void
    {
        $this->sellableProduct();

        $veteran = User::factory()->create(['created_at' => now()->subDays(30)]);
        $newHire = User::factory()->create(['created_at' => now()->subHours(2)]);

        $this->artisan('pos:backfill', [
            '--from' => now()->subDays(6)->toDateString(),
            '--to' => now()->subDay()->toDateString(),
            '--per-day' => 4,
        ])->assertSuccessful();

        $this->assertGreaterThan(0, Sale::count(), 'the backfill wrote nothing, so this proves nothing');

        // Every one of those days closed before the new hire's account opened.
        $this->assertSame(0, Sale::where('user_id', $newHire->id)->count());
        $this->assertSame(Sale::count(), Sale::where('user_id', $veteran->id)->count());
    }

    public function test_a_sale_is_only_offered_to_accounts_that_existed_at_its_own_timestamp(): void
    {
        $this->sellableProduct();

        // Created midway through the window, not before it: the days on either
        // side of that moment must be attributed differently.
        $joined = now()->subDays(3)->setTime(12, 0);
        $early = User::factory()->create(['created_at' => now()->subDays(30)]);
        $later = User::factory()->create(['created_at' => $joined]);

        $this->artisan('pos:backfill', [
            '--from' => now()->subDays(6)->toDateString(),
            '--to' => now()->subDay()->toDateString(),
            '--per-day' => 6,
        ])->assertSuccessful();

        $this->assertSame(0, Sale::where('user_id', $later->id)->where('created_at', '<', $joined)->count());
        $this->assertGreaterThan(0, Sale::where('user_id', $early->id)->count());
    }

    public function test_fix_attribution_repoints_an_impossible_sale_and_leaves_a_legitimate_one_alone(): void
    {
        $veteran = User::factory()->create(['created_at' => now()->subDays(30)]);
        $newHire = User::factory()->create(['created_at' => now()->subHours(2)]);

        $impossible = $this->sale($newHire, now()->subDays(10));
        $legitimate = $this->sale($newHire, now()->subMinutes(30));
        $untouched = $this->sale($veteran, now()->subDays(20));

        $this->artisan('pos:backfill', ['--fix-attribution' => true])->assertSuccessful();

        $this->assertSame($veteran->id, $impossible->fresh()->user_id);
        $this->assertSame($newHire->id, $legitimate->fresh()->user_id, 'a sale the account could have rung up must not move');
        $this->assertSame($veteran->id, $untouched->fresh()->user_id);
    }

    public function test_fix_attribution_does_not_drag_a_backdated_sale_to_now(): void
    {
        User::factory()->create(['created_at' => now()->subDays(30)]);
        $newHire = User::factory()->create(['created_at' => now()->subHours(2)]);

        $sale = $this->sale($newHire, now()->subDays(10));
        $was = $sale->created_at;

        $this->artisan('pos:backfill', ['--fix-attribution' => true])->assertSuccessful();

        // update() rather than save(), so the sale keeps the day it belongs to.
        $this->assertTrue($was->equalTo($sale->fresh()->created_at));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        User::factory()->create(['created_at' => now()->subDays(30)]);
        $newHire = User::factory()->create(['created_at' => now()->subHours(2)]);

        $sale = $this->sale($newHire, now()->subDays(10));

        $this->artisan('pos:backfill', ['--fix-attribution' => true, '--dry-run' => true])->assertSuccessful();

        $this->assertSame($newHire->id, $sale->fresh()->user_id);
    }

    private function sale(User $user, \DateTimeInterface $at): Sale
    {
        $sale = new Sale([
            'transaction_no' => 'TXN-'.$at->format('Ymd').'-'.str_pad((string) (Sale::count() + 1), 5, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'total_amount' => 100,
            'amount_paid' => 100,
            'change_due' => 0,
            'payment_voided' => false,
        ]);

        $sale->created_at = $at;
        $sale->updated_at = $at;
        $sale->save();

        return $sale;
    }
}
