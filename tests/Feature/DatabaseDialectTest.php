<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What must read the same on MySQL and PostgreSQL (and the sqlite suite). Each
 * case is something that worked on MySQL by accident of how it compares values,
 * and broke silently or with a 500 the moment PostgreSQL answered instead. Run
 * this suite against a PostgreSQL TEST database as well as sqlite.
 */
class DatabaseDialectTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'PARACETAMOL 500MG', string $sku = 'ABC123'): Product
    {
        return Product::create([
            'category_id' => Category::create(['name' => 'Dialect '.uniqid()])->id,
            'name' => $name,
            'sku' => $sku,
            'barcode' => $sku,
            'unit' => 'PCS',
            'selling_price' => 10,
            'reorder_level' => 5,
        ]);
    }

    public function test_the_date_helpers_read_a_date_the_same_on_every_server(): void
    {
        $this->product();
        DB::table('sales_history')->insert(['product_sku' => 'ABC123', 'sale_date' => '2026-03-14', 'quantity_sold' => 7]);

        $row = DB::table('sales_history')->selectRaw(
            Sql::yearMonth('sale_date').' AS ym, '.Sql::firstOfMonth('sale_date').' AS first_day, '
            .Sql::month('sale_date').' AS m, '.Sql::year('sale_date').' AS y'
        )->first();

        $this->assertSame('2026-03', $row->ym);
        $this->assertSame('2026-03-01', $row->first_day);
        $this->assertSame(3, (int) $row->m);
        $this->assertSame(2026, (int) $row->y);
    }

    public function test_searching_ignores_case(): void
    {
        $this->product();
        $admin = User::factory()->admin()->create();

        foreach (['/products', '/inventory', '/pos'] as $page) {
            $this->actingAs($admin)->getJson($page.'?search=paracetamol')
                ->assertOk()
                ->assertSee('PARACETAMOL 500MG');
        }
        $this->actingAs($admin)->getJson('/suggest/products?q=paracetamol')->assertOk()->assertSee('PARACETAMOL 500MG');
    }

    public function test_a_transaction_number_search_ignores_case(): void
    {
        $admin = User::factory()->admin()->create();
        Sale::create([
            'transaction_no' => 'TXN-20261102-00001', 'user_id' => $admin->id, 'total_amount' => 5,
            'amount_paid' => 5, 'change_due' => 0, 'payment_voided' => false,
        ]);

        $this->actingAs($admin)->getJson('/sales?search=txn-20261102&show_all=1')->assertOk()->assertSee('TXN-20261102-00001');
    }

    public function test_a_typed_code_finds_its_product_whatever_the_case(): void
    {
        $this->product();

        $this->actingAs(User::factory()->create())->getJson('/pos/lookup?sku=abc123')->assertOk();
    }

    public function test_signing_in_ignores_the_case_of_the_address(): void
    {
        User::factory()->create(['email' => 'maria@remedi.com', 'password' => 'secret-password']);

        $this->post('/login', ['email' => 'Maria@Remedi.com', 'password' => 'secret-password']);

        $this->assertTrue(Auth::check());
    }

    /** PostgreSQL raises on these where MySQL finds nothing: 404 or a refusal, never 500. */
    public function test_an_id_that_is_not_one_never_answers_500(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/sales/abc', '/sales/99999999999999999999', '/pos/receipt/abc', '/products/abc/edit',
            '/products/99999999999999999999/edit', '/users/abc/edit', '/products/abc/stock-card'] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }

        foreach (['/products?category_id=abc', '/inventory?category_id=abc', '/audit?user_id=abc'] as $url) {
            $this->assertLessThan(500, $this->actingAs($admin)->get($url)->getStatusCode(), $url);
        }

        $this->actingAs($admin)->postJson('/pos/checkout', [
            'items' => [['product_id' => 'abc', 'quantity' => 1]], 'amount_paid' => 1,
        ])->assertUnprocessable();
    }

    public function test_a_forecast_address_still_takes_a_sku_with_letters(): void
    {
        $this->assertSame('/forecast/ABC-123', route('forecast.show', 'ABC-123', false));
    }
}
