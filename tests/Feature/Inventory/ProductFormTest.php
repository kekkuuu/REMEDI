<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the product and batch forms accept.
 *
 * Two rules, both about a field that looked validated and was not:
 *
 * 1. UNIT IS A LIST, NOT FREE TEXT. It used to be a text box that defaulted to
 *    lowercase "pcs" while all 2,637 rows in the catalogue say "PCS", so every
 *    product added through the form would have started a second spelling of the
 *    same unit -- and anything grouping by unit would have split it in two. The
 *    catalogue picked up one product whose unit is the string "20" that way.
 *
 * 2. NUMBERS ARE BOUNDED BY THEIR COLUMN. Money here is decimal(10,2) and
 *    counts are signed ints, and MySQL runs with STRICT_TRANS_TABLES -- so a
 *    value past either limit is not clamped, it raises SQLSTATE[22003] and the
 *    request dies as a 500 with SQL in the body. selling_price, reorder_level
 *    and batch quantity were all validated for type and a LOWER bound and
 *    nothing else, so their ceiling was whatever the database would take.
 *
 * The bounds live on Controller::MAX_MONEY / MAX_COUNT; these tests assert the
 * behaviour rather than the constants, so widening a column and its bound
 * together does not break them. The till's own ceiling -- a mistyped payment,
 * the one that actually took a checkout down mid-sale -- is covered in
 * Feature\Pos\CheckoutTest, beside the rest of the payment rules.
 */
class ProductFormTest extends TestCase
{
    use RefreshDatabase;

    private const OVER_MONEY = '100000000.00';   // decimal(10,2) stops at 99,999,999.99

    private const OVER_COUNT = '2147483648';     // signed int stops at 2,147,483,647

    private function payload(array $overrides = []): array
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return array_merge([
            'name' => 'Test Item',
            'sku' => 'SKU-UNIT-1',
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 5,
        ], $overrides);
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        return Product::create([
            'name' => 'Bounds Probe '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    // ── The unit list ────────────────────────────────────────────────────

    public function test_a_canonical_unit_is_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', $this->payload(['unit' => 'BOTTLE']))
            ->assertSessionHasNoErrors();

        $this->assertSame('BOTTLE', Product::where('sku', 'SKU-UNIT-1')->value('unit'));
    }

    public function test_free_text_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', $this->payload(['unit' => '20']))
            ->assertSessionHasErrors('unit');

        $this->assertDatabaseMissing('products', ['sku' => 'SKU-UNIT-1']);
    }

    /** Lowercase would be a second spelling of a unit that already exists. */
    public function test_the_wrong_case_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', $this->payload(['unit' => 'pcs']))
            ->assertSessionHasErrors('unit');
    }

    /**
     * A legacy row whose unit is not on the list must stay editable, or an
     * unrelated edit to it would be rejected for a field nobody touched.
     */
    public function test_a_legacy_unit_survives_an_edit(): void
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);
        $product = Product::create([
            'name' => 'Legacy Item', 'sku' => 'SKU-LEGACY',
            'category_id' => $category->id, 'unit' => '20',
            'selling_price' => '10.00', 'reorder_level' => 5,
        ]);

        $this->assertContains('20', Product::unitOptions($product->unit));

        $this->actingAs(User::factory()->admin()->create())
            ->put('/products/'.$product->id, [
                'name' => 'Legacy Item Renamed',
                'sku' => 'SKU-LEGACY',
                'category_id' => $category->id,
                'unit' => '20',
                'selling_price' => '10.00',
                'reorder_level' => 5,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Legacy Item Renamed', $product->fresh()->name);
    }

    public function test_the_form_renders_a_dropdown_of_the_canonical_units(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get('/products/create')->content();

        $this->assertMatchesRegularExpression('/<select[^>]*name="unit"/', $html);

        foreach (Product::UNITS as $unit) {
            $this->assertStringContainsString('>'.$unit.'<', $html);
        }
    }

    // ── Numeric ceilings ─────────────────────────────────────────────────

    public function test_a_price_larger_than_the_column_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', $this->payload([
                'name' => 'Absurdly Priced',
                'sku' => 'SKU-OVER-PRICE',
                'selling_price' => self::OVER_MONEY,
            ]))
            ->assertSessionHasErrors('selling_price');

        $this->assertDatabaseMissing('products', ['sku' => 'SKU-OVER-PRICE']);
    }

    public function test_a_reorder_level_larger_than_the_column_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/products', $this->payload([
                'name' => 'Absurd Reorder Level',
                'sku' => 'SKU-OVER-LEVEL',
                'reorder_level' => self::OVER_COUNT,
            ]))
            ->assertSessionHasErrors('reorder_level');

        $this->assertDatabaseMissing('products', ['sku' => 'SKU-OVER-LEVEL']);
    }

    /**
     * Stock cannot have been received on a day that has not happened.
     *
     * The date input caps itself at today, but a picker is a suggestion and
     * this is the rule -- and a future received_date is not harmless: expiry
     * validates after:received_date, and edit() derives the next batch's
     * suggested shelf life from the gap between the two.
     */
    public function test_a_delivery_dated_in_the_future_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/products/'.$product->id.'/batches', [
                'batch_number' => 'B'.uniqid(),
                'quantity' => 10,
                'received_date' => now()->addDay()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ])
            ->assertSessionHasErrors('received_date');

        $this->assertSame(0, ProductBatch::count());
    }

    public function test_a_delivery_received_today_is_accepted(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/products/'.$product->id.'/batches', [
                'batch_number' => 'B'.uniqid(),
                'quantity' => 10,
                'received_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ProductBatch::count(), 'today is not the future');
    }

    public function test_a_batch_quantity_larger_than_the_column_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/products/'.$product->id.'/batches', [
                'batch_number' => 'B'.uniqid(),
                'quantity' => self::OVER_COUNT,
                'expiry_date' => now()->addYear()->toDateString(),
                'received_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, ProductBatch::count());
    }

    public function test_editing_a_batch_to_an_absurd_quantity_is_refused(): void
    {
        $product = $this->product();
        $batch = ProductBatch::create([
            'batch_number' => 'B'.uniqid(),
            'product_id' => $product->id,
            'quantity' => 10,
            'qty_received' => 10,
            'unit_cost' => 1,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/batches/'.$batch->id, [
                'batch_number' => $batch->batch_number,
                'quantity' => self::OVER_COUNT,
                'expiry_date' => $batch->expiry_date->toDateString(),
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(10, $batch->fresh()->quantity, 'the stored quantity must be untouched');
    }

    /*
     * The batch number follows the RECEIVED DATE.
     *
     * It used to be a required free-text field, and the catalogue shows what
     * that produced: 2,641 seeded `OPENING-<barcode>` rows and exactly one
     * hand-typed number, `12323`. The form now fills it in from the date, and
     * `ProductController::addBatch` derives the same value server-side when the
     * field arrives empty -- so the rule holds with JavaScript off, which is
     * the only reason the browser copy is allowed to exist.
     */

    private function batchPayload(array $overrides = []): array
    {
        return array_merge([
            'quantity' => 10,
            'received_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ], $overrides);
    }

    /**
     * The letters come off the product name, and only the LETTERS.
     *
     * "The first three characters" would produce codes like `3M ` and `G. ` on
     * this catalogue, where names open with digits and punctuation often
     * enough to matter -- 9 products carry `%` in the name and 3 carry `_`.
     */
    public function test_the_letters_are_taken_from_the_product_name(): void
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);
        $admin = User::factory()->admin()->create();

        $cases = [
            'HERACLENE 1MG TAB X100' => 'HER',
            '3M TAPE' => 'MTA',              // digits skipped, not counted
            'G. CROSS ETHYL 70%' => 'GCR',   // punctuation and spaces skipped
            'K2' => 'KXX',                   // too few letters, padded
        ];

        foreach ($cases as $name => $expected) {
            $product = Product::create([
                'name' => $name,
                'sku' => 'SKU-'.uniqid(),
                'category_id' => $category->id,
                'unit' => 'PCS',
                'selling_price' => '10.00',
                'reorder_level' => 1,
            ]);

            $this->actingAs($admin)
                ->post("/products/{$product->id}/batches", $this->batchPayload(['received_date' => '2026-09-01']))
                ->assertRedirect();

            $this->assertSame(
                $expected.'-20260901-01',
                $product->batches()->sole()->batch_number,
                "'{$name}' should code as {$expected}"
            );
        }
    }

    public function test_the_form_shows_the_code_it_will_use(): void
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);
        $product = Product::create([
            'name' => 'HERACLENE 1MG TAB X100',
            'sku' => 'SKU-'.uniqid(),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get("/products/{$product->id}/edit")->content();

        $this->assertStringContainsString('data-batch-code="HER"', $html);
    }

    public function test_a_blank_batch_number_is_derived_from_the_received_date(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/products/{$product->id}/batches", $this->batchPayload([
                'received_date' => '2026-09-01',
            ]))
            ->assertRedirect();

        $this->assertSame('BOU-20260901-01', $product->batches()->sole()->batch_number);
    }

    public function test_the_number_follows_the_received_date_not_today(): void
    {
        $product = $this->product();

        // A delivery entered late still belongs to the day it arrived.
        $this->actingAs(User::factory()->admin()->create())
            ->post("/products/{$product->id}/batches", $this->batchPayload([
                'received_date' => now()->subDays(9)->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertSame(
            'BOU-'.now()->subDays(9)->format('Ymd').'-01',
            $product->batches()->sole()->batch_number
        );
    }

    public function test_a_second_delivery_on_the_same_day_takes_the_next_sequence(): void
    {
        $product = $this->product();
        $admin = User::factory()->admin()->create();

        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($admin)
                ->post("/products/{$product->id}/batches", $this->batchPayload(['received_date' => '2026-09-01']))
                ->assertRedirect();
        }

        $this->assertSame(
            ['BOU-20260901-01', 'BOU-20260901-02', 'BOU-20260901-03'],
            $product->batches()->orderBy('id')->pluck('batch_number')->all()
        );
    }

    public function test_the_sequence_is_per_product(): void
    {
        $admin = User::factory()->admin()->create();
        $one = $this->product();
        $two = $this->product();

        foreach ([$one, $two] as $product) {
            $this->actingAs($admin)
                ->post("/products/{$product->id}/batches", $this->batchPayload(['received_date' => '2026-09-01']))
                ->assertRedirect();
        }

        // batch_number carries no unique constraint and nothing joins on it, so
        // two products receiving stock the same day both start at 01 -- which
        // reads better on each product's own batch table than a shared counter.
        $this->assertSame('BOU-20260901-01', $one->batches()->sole()->batch_number);
        $this->assertSame('BOU-20260901-01', $two->batches()->sole()->batch_number);
    }

    /**
     * The number is ASSIGNED, not entered — so a posted one is discarded.
     *
     * Both forms render the field readonly, but a readonly input still posts
     * its value and a request can carry anything at all. This is the same
     * lesson `markBatchReturned` and `pos.receipt` carry: a gated control is
     * not a gated endpoint. It also closes the race the readonly field would
     * otherwise open — two people adding a batch for the same product on the
     * same day are both SHOWN `-01`, and the second must still be told `-02`.
     */
    public function test_a_posted_batch_number_is_ignored(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/products/{$product->id}/batches", $this->batchPayload([
                'batch_number' => 'WHATEVER-I-LIKE',
                'received_date' => '2026-09-01',
            ]))
            ->assertRedirect();

        $this->assertSame('BOU-20260901-01', $product->batches()->sole()->batch_number);
    }

    public function test_two_submissions_claiming_the_same_number_still_differ(): void
    {
        $product = $this->product();
        $admin = User::factory()->admin()->create();

        // Both browsers previewed -01, which is what they would post.
        foreach ([1, 2] as $ignored) {
            $this->actingAs($admin)
                ->post("/products/{$product->id}/batches", $this->batchPayload([
                    'batch_number' => 'BOU-20260901-01',
                    'received_date' => '2026-09-01',
                ]))
                ->assertRedirect();
        }

        $this->assertSame(
            ['BOU-20260901-01', 'BOU-20260901-02'],
            $product->batches()->orderBy('id')->pluck('batch_number')->all()
        );
    }

    public function test_the_field_cannot_be_typed_into(): void
    {
        $product = $this->product();

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get("/products/{$product->id}/edit")->content();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="batch_number"[^>]*\breadonly\b/',
            $html,
            'the batch number field must be readonly — the number is assigned, not entered'
        );
    }
}
