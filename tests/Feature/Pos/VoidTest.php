<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Voiding a completed sale (SaleController::void()) -- requires a reason
 * (Sale::VOID_REASONS), restocks every line back onto the batch it came
 * from, and stops the sale counting in "today" figures while leaving it
 * fully visible with who/when/why.
 *
 * SHARED, not admin-only, as of 2026-09-22: staff may void a sale THEY rang
 * up, given the correct manager passcode (Setting::checkVoidPasscode(), set
 * by an admin at /settings) -- a real, audited, passcode-gated path, not the
 * old supervisor-passcode bypass this replaces the SPIRIT of (removed
 * deliberately, see REMEDI.md) but not its shape: that one could mark a sale
 * voided AT CHECKOUT; this one reverses an already-completed sale, same as
 * an admin's void always has. An admin needs neither the ownership check nor
 * the passcode.
 */
class VoidTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function cashier(): User
    {
        return User::factory()->create();
    }

    private function checkoutProduct(int $qty = 10, string $price = '10.00'): array
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => 'Voidable Item '.uniqid(),
            'sku' => 'SKU-'.substr(md5(uniqid()), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => $price,
            'reorder_level' => 1,
        ]);

        $batch = ProductBatch::create([
            'batch_number' => 'B'.substr(md5(uniqid()), 0, 6),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDay()->toDateString(),
        ]);

        return [$product, $batch];
    }

    private function checkout(User $cashier, Product $product, int $qty, float $amountPaid): Sale
    {
        $this->actingAs($cashier)
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => $qty]],
                'amount_paid' => $amountPaid,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        return Sale::latest('id')->first();
    }

    public function test_voiding_with_no_reason_is_refused(): void
    {
        [$product] = $this->checkoutProduct();
        $sale = $this->checkout($this->cashier(), $product, 3, 30);

        $this->actingAs($this->admin())
            ->patch("/sales/{$sale->id}/void")
            ->assertSessionHasErrors('reason');

        $this->assertFalse($sale->fresh()->payment_voided);
    }

    public function test_staff_cannot_void_without_a_passcode_set_at_all(): void
    {
        [$product] = $this->checkoutProduct();
        $cashier = $this->cashier();
        $sale = $this->checkout($cashier, $product, 3, 30);

        // No Setting::setVoidPasscode() call -- none exists yet.
        $this->actingAs($cashier)
            ->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error', 'passcode' => '123456'])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse($sale->fresh()->payment_voided);
    }

    public function test_staff_cannot_void_with_the_wrong_passcode(): void
    {
        Setting::setVoidPasscode('112233');
        [$product] = $this->checkoutProduct();
        $cashier = $this->cashier();
        $sale = $this->checkout($cashier, $product, 3, 30);

        $this->actingAs($cashier)
            ->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error', 'passcode' => '000000'])
            ->assertSessionHasErrors('passcode');

        $this->assertFalse($sale->fresh()->payment_voided);
    }

    public function test_staff_can_void_their_own_sale_with_the_correct_passcode(): void
    {
        Setting::setVoidPasscode('112233');
        [$product, $batch] = $this->checkoutProduct(10);
        $cashier = $this->cashier();
        $sale = $this->checkout($cashier, $product, 4, 40);

        $this->actingAs($cashier)
            ->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error', 'passcode' => '112233'])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertTrue($sale->payment_voided);
        $this->assertSame($cashier->id, $sale->voided_by);
        $this->assertSame(10, (int) $batch->fresh()->quantity);
    }

    public function test_staff_cannot_void_someone_elses_sale_even_with_the_correct_passcode(): void
    {
        Setting::setVoidPasscode('112233');
        [$product] = $this->checkoutProduct();
        $sale = $this->checkout($this->cashier(), $product, 3, 30);

        // A DIFFERENT cashier, not the one who rang this sale up.
        $this->actingAs($this->cashier())
            ->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error', 'passcode' => '112233'])
            ->assertForbidden();

        $this->assertFalse($sale->fresh()->payment_voided);
    }

    public function test_admin_does_not_need_a_passcode(): void
    {
        // No Setting::setVoidPasscode() call, and none passed -- an admin's
        // own void is unaffected either way.
        [$product] = $this->checkoutProduct();
        $sale = $this->checkout($this->cashier(), $product, 3, 30);

        $this->actingAs($this->admin())
            ->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sale->fresh()->payment_voided);
    }

    public function test_voiding_restocks_the_batch_and_flags_the_sale(): void
    {
        [$product, $batch] = $this->checkoutProduct(10);
        $sale = $this->checkout($this->cashier(), $product, 4, 40);

        $this->assertSame(6, (int) $batch->fresh()->quantity, 'precondition: checkout deducted the batch');

        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}/void", ['reason' => 'customer_cancelled'])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertTrue($sale->payment_voided);
        $this->assertSame('customer_cancelled', $sale->void_reason);
        $this->assertSame($admin->id, $sale->voided_by);
        $this->assertNotNull($sale->voided_at);

        // The 4 units are back on the shelf.
        $this->assertSame(10, (int) $batch->fresh()->quantity);

        // And the stock card carries the reversal.
        $movement = StockMovement::where('sale_id', $sale->id)
            ->where('type', StockMovement::TYPE_VOID)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(4, $movement->quantity_change);
        $this->assertSame(10, $movement->balance_after);
    }

    public function test_voiding_an_unknown_reason_is_refused(): void
    {
        [$product] = $this->checkoutProduct();
        $sale = $this->checkout($this->cashier(), $product, 2, 20);

        $this->actingAs($this->admin())
            ->patch("/sales/{$sale->id}/void", ['reason' => 'because'])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($sale->fresh()->payment_voided);
    }

    public function test_voiding_an_already_voided_sale_is_refused_and_does_not_double_restock(): void
    {
        [$product, $batch] = $this->checkoutProduct(10);
        $sale = $this->checkout($this->cashier(), $product, 3, 30);
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/sales/{$sale->id}/void", ['reason' => 'duplicate']);
        $this->assertSame(10, (int) $batch->fresh()->quantity);

        $this->actingAs($admin)
            ->patch("/sales/{$sale->id}/void", ['reason' => 'duplicate'])
            ->assertSessionHasErrors('reason');

        // Still 10, not 13 -- the second void must not restock again.
        $this->assertSame(10, (int) $batch->fresh()->quantity);
    }

    public function test_a_voided_sale_is_excluded_from_todays_total_but_still_listed(): void
    {
        [$product] = $this->checkoutProduct();
        $cashier = $this->cashier();
        $sale = $this->checkout($cashier, $product, 2, 20);

        $this->actingAs($this->admin())->patch("/sales/{$sale->id}/void", ['reason' => 'cashier_error']);

        $response = $this->actingAs($this->admin())->get('/sales?all=1');

        $response->assertOk();
        // Still visible in the list...
        $response->assertSee($sale->transaction_no);
        // ...but today's total no longer counts it.
        $response->assertSee('Voided');
    }

    public function test_checkout_defaults_to_cash_when_no_payment_method_is_sent(): void
    {
        [$product] = $this->checkoutProduct();
        $sale = $this->checkout($this->cashier(), $product, 1, 10);

        $this->assertSame('cash', $sale->payment_method);
    }

    public function test_checkout_records_the_chosen_payment_method(): void
    {
        [$product] = $this->checkoutProduct();

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => 10,
                'payment_method' => 'gcash',
            ])
            ->assertOk();

        $this->assertSame('gcash', Sale::latest('id')->first()->payment_method);
    }

    public function test_checkout_refuses_an_unknown_payment_method(): void
    {
        [$product] = $this->checkoutProduct();

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => 10,
                'payment_method' => 'bitcoin',
            ])
            ->assertStatus(422);
    }

    /**
     * Card/debit/credit removed 2026-09-22 at the user's request -- this
     * till only ever took cash and QR-based e-wallets. 'card' is no longer
     * in Sale::PAYMENT_METHODS, so it now fails the same validation any
     * other unrecognised value would.
     */
    public function test_checkout_refuses_the_removed_card_method(): void
    {
        [$product] = $this->checkoutProduct();

        $this->actingAs($this->cashier())
            ->postJson('/pos/checkout', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'amount_paid' => 10,
                'payment_method' => 'card',
            ])
            ->assertStatus(422);
    }
}
