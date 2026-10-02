<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QrPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\PayMongo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatic QR payments through PayMongo (QrPaymentController, 2026-10-02).
 * PayMongo is FAKED throughout -- no test ever reaches api.paymongo.com. The
 * cases that matter: a paid code becomes exactly one sale, by poll or by
 * webhook or both; nothing is sold before payment; a forged webhook does
 * nothing; and money that lands after the shelf emptied is flagged, not sold.
 */
class QrPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_test_secret';

    /** What the faked PayMongo says the intent's status is right now. */
    private string $intentStatus = 'awaiting_next_action';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymongo.secret_key' => 'sk_test_fake',
            'services.paymongo.public_key' => 'pk_test_fake',
            'services.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function product(int $qty = 10, string $price = '50.00'): array
    {
        $product = Product::create([
            'name' => 'QR Item '.uniqid(),
            'sku' => 'SKU-'.substr(md5(uniqid()), 0, 10),
            'category_id' => Category::firstOrCreate(['name' => 'General Merchandise'])->id,
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

    /**
     * PayMongo's three calls to make a code, then the intent's status --
     * read from $intentStatus at request time, since a second Http::fake()
     * does not replace the first one's stubs.
     */
    private function fakePayMongo(string $status = 'awaiting_next_action'): void
    {
        $this->intentStatus = $status;
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_1/attach' => Http::response(['data' => ['id' => 'pi_test_1', 'attributes' => [
                'status' => 'awaiting_next_action',
                'next_action' => ['type' => 'consume_qr', 'code' => ['image_url' => 'data:image/png;base64,QUJD', 'test_url' => 'https://test.paymongo.example/pay']],
            ]]]),
            'api.paymongo.com/v1/payment_intents/pi_test_1' => fn () => Http::response(['data' => ['id' => 'pi_test_1', 'attributes' => ['status' => $this->intentStatus]]]),
            'api.paymongo.com/v1/payment_intents' => Http::response(['data' => ['id' => 'pi_test_1', 'attributes' => ['client_key' => 'pi_test_1_client_abc', 'status' => 'awaiting_payment_method']]]),
            'api.paymongo.com/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_test_1', 'attributes' => ['type' => 'qrph']]]),
        ]);
    }

    private function makeCode(User $cashier, Product $product, int $qty = 2)
    {
        return $this->actingAs($cashier)->postJson('/pos/qr-payments', [
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'payment_method' => 'gcash',
        ]);
    }

    private function signedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $t = (string) time();
        $sig = hash_hmac('sha256', $t.'.'.$body, self::WEBHOOK_SECRET);

        return $this->call('POST', '/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=",
        ], $body);
    }

    private function paidEvent(string $intentId = 'pi_test_1'): array
    {
        return ['data' => ['attributes' => ['type' => 'payment.paid', 'data' => ['attributes' => ['payment_intent_id' => $intentId, 'status' => 'paid']]]]];
    }

    public function test_a_code_is_made_for_the_cart_total_and_nothing_is_sold_yet(): void
    {
        $this->fakePayMongo();
        [$product, $batch] = $this->product();
        $cashier = User::factory()->create();

        $this->makeCode($cashier, $product, 2)
            ->assertOk()
            ->assertJson(['success' => true, 'amount' => '100.00', 'image' => 'data:image/png;base64,QUJD', 'test_url' => 'https://test.paymongo.example/pay']);

        // The amount PayMongo was asked for is the server's own total, in centavos.
        Http::assertSent(fn ($r) => $r->url() === 'https://api.paymongo.com/v1/payment_intents'
            && data_get($r->data(), 'data.attributes.amount') === 10000
            && data_get($r->data(), 'data.attributes.payment_method_allowed') === ['qrph']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(10, (int) $batch->fresh()->quantity);
        $this->assertSame('pending', QrPayment::first()->status);
    }

    public function test_the_poll_records_the_sale_once_the_payment_lands(): void
    {
        $this->fakePayMongo('awaiting_next_action');
        [$product, $batch] = $this->product();
        $cashier = User::factory()->create();
        $id = $this->makeCode($cashier, $product)->json('id');

        // Not paid yet: still pending, nothing sold.
        $this->actingAs($cashier)->getJson("/pos/qr-payments/{$id}")->assertJson(['status' => 'pending']);
        $this->assertSame(0, Sale::count());

        // Paid: the sale appears, exact and as GCash, with a receipt.
        $this->intentStatus = 'succeeded';
        $this->actingAs($cashier)->getJson("/pos/qr-payments/{$id}")
            ->assertOk()->assertJson(['status' => 'completed'])->assertJsonStructure(['receipt_html', 'transaction_no']);

        $sale = Sale::sole();
        $this->assertSame('gcash', $sale->payment_method);
        $this->assertEquals(100.0, (float) $sale->total_amount);
        $this->assertEquals(100.0, (float) $sale->amount_paid);
        $this->assertEquals(0.0, (float) $sale->change_due);
        $this->assertSame($cashier->id, $sale->user_id);
        $this->assertSame(8, (int) $batch->fresh()->quantity);

        // Asking again changes nothing.
        $this->actingAs($cashier)->getJson("/pos/qr-payments/{$id}")->assertJson(['status' => 'completed']);
        $this->assertSame(1, Sale::count());
    }

    public function test_the_webhook_records_the_sale_and_a_poll_after_it_makes_no_second(): void
    {
        $this->fakePayMongo();
        [$product, $batch] = $this->product();
        $cashier = User::factory()->create();
        $id = $this->makeCode($cashier, $product)->json('id');

        $this->signedWebhook($this->paidEvent())->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertSame('completed', QrPayment::find($id)->status);

        // PayMongo retries, and the till's poll arrives too: still one sale.
        $this->signedWebhook($this->paidEvent())->assertOk();
        $this->intentStatus = 'succeeded';
        $this->actingAs($cashier)->getJson("/pos/qr-payments/{$id}")->assertJson(['status' => 'completed']);
        $this->assertSame(1, Sale::count());
        $this->assertSame(8, (int) $batch->fresh()->quantity);
    }

    public function test_a_forged_or_unsigned_webhook_does_nothing(): void
    {
        $this->fakePayMongo();
        [$product] = $this->product();
        $this->makeCode(User::factory()->create(), $product);

        $body = json_encode($this->paidEvent());
        $this->call('POST', '/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.time().',te='.hash_hmac('sha256', 'x', 'wrong-secret').',li=',
        ], $body)->assertStatus(401);

        $this->assertSame(0, Sale::count());
        $this->assertSame('pending', QrPayment::first()->status);
    }

    public function test_money_that_lands_after_the_shelf_emptied_is_flagged_not_sold(): void
    {
        $this->fakePayMongo();
        [$product, $batch] = $this->product(2);
        $cashier = User::factory()->create();
        $id = $this->makeCode($cashier, $product, 2)->json('id');

        $batch->update(['quantity' => 0]); // sold at another register meanwhile

        $this->signedWebhook($this->paidEvent())->assertOk();

        $this->assertSame(0, Sale::count());
        $payment = QrPayment::find($id);
        $this->assertSame('attention', $payment->status);
        $this->assertStringContainsString('could not be recorded', $payment->message);
        $this->actingAs($cashier)->getJson("/pos/qr-payments/{$id}")->assertJson(['status' => 'attention']);
    }

    public function test_only_the_cashier_or_an_admin_may_watch_a_code(): void
    {
        $this->fakePayMongo();
        [$product] = $this->product();
        $id = $this->makeCode(User::factory()->create(), $product)->json('id');

        $this->actingAs(User::factory()->create())->getJson("/pos/qr-payments/{$id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->getJson("/pos/qr-payments/{$id}")->assertOk();
        // The guard remembers the last user within one test; forget it first.
        $this->app['auth']->forgetGuards();
        $this->getJson("/pos/qr-payments/{$id}")->assertUnauthorized();
    }

    public function test_without_paymongo_keys_the_feature_is_off(): void
    {
        config(['services.paymongo.secret_key' => null, 'services.paymongo.public_key' => null]);
        [$product] = $this->product();

        $this->assertFalse(PayMongo::enabled());
        $this->makeCode(User::factory()->create(), $product)->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/pos')->assertOk()->assertSee('data-paymongo=""', false);
    }

    public function test_a_code_needs_stock_a_qr_method_and_at_least_one_peso(): void
    {
        $this->fakePayMongo();
        [$product] = $this->product(1, '0.50');
        $cashier = User::factory()->create();

        $this->makeCode($cashier, $product, 5)->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->makeCode($cashier, $product, 1)->assertStatus(422); // ₱0.50 is under PayMongo's ₱1.00
        $this->actingAs($cashier)->postJson('/pos/qr-payments', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, QrPayment::count());
    }

    public function test_the_signature_check_itself(): void
    {
        $body = '{"a":1}';
        $sig = hash_hmac('sha256', '123.'.$body, 'k');

        $this->assertTrue(PayMongo::verifySignature($body, "t=123,te={$sig},li=", 'k'));
        $this->assertTrue(PayMongo::verifySignature($body, "t=123,te=,li={$sig}", 'k'));
        $this->assertFalse(PayMongo::verifySignature($body.' ', "t=123,te={$sig},li=", 'k'));
        $this->assertFalse(PayMongo::verifySignature($body, "t=124,te={$sig},li=", 'k'));
        $this->assertFalse(PayMongo::verifySignature($body, null, 'k'));
        $this->assertFalse(PayMongo::verifySignature($body, "t=123,te={$sig},li=", null));
    }
}
