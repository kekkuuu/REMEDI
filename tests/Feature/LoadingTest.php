<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loading work of 2026-10-22 (measured on the live site: 1-2.5 s a page,
 * of which the server's own share was 0.15-0.5 s -- the rest the trip to the
 * hosting in Europe). Each case pins one of the changes that cut trips or bytes.
 */
class LoadingTest extends TestCase
{
    use RefreshDatabase;

    // ── Prefetch on hover ────────────────────────────────────────────────

    /** Every page carries the rules, and they never reach a GET that does something. */
    public function test_pages_prefetch_on_hover_but_never_a_report_export_or_backup(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get('/profile')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<script type="speculationrules">(.*?)<\/script>/s', $html, $m));
        $rules = json_decode($m[1], true);
        $this->assertSame('moderate', $rules['prefetch'][0]['eagerness']);

        $excluded = collect($rules['prefetch'][0]['where']['and'])->pluck('not.href_matches')->filter()->values()->all();
        // Reports write a "Viewed" audit row on every open; exports download;
        // the backup and Safeguard sit behind a password.
        foreach (['/reports/*', '/*export*', '/admin/*', '/safeguard*', '/logout*'] as $pattern) {
            $this->assertContains($pattern, $excluded);
        }
    }

    // ── The dashboard in one request ─────────────────────────────────────

    /** Off (the local default): the shell, which runs no queries, as always. */
    public function test_the_dashboard_answers_with_its_shell_when_inline_is_off(): void
    {
        config(['dashboard.inline_body' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="dashboardRoot"', false);
    }

    /**
     * On (the live default), the arrival from sign-in still gets the shell and
     * its loader card, so signing in never waits on a cold dashboard. (The
     * inline body itself runs MySQL-only aggregates this suite cannot.)
     */
    public function test_the_visit_after_sign_in_keeps_the_shell_even_when_inline_is_on(): void
    {
        config(['dashboard.inline_body' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['remedi.just_signed_in' => true])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="dashboardRoot"', false)
            ->assertSee('is-first-load', false);
    }

    // ── The Sales Report's product suggestions, on demand ────────────────

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => Category::firstOrCreate(['name' => 'General Merchandise'])->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);
    }

    public function test_the_product_list_is_fetched_not_rendered_and_lists_only_what_sold(): void
    {
        $sold = $this->product('Sold Once', '4800000000011');
        $this->product('Never Sold', '4800000000028');
        SalesHistory::create(['product_sku' => $sold->sku, 'sale_date' => now()->subMonth()->toDateString(), 'quantity_sold' => 3]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/reports/sales/products')
            ->assertOk()
            ->assertExactJson([['4800000000011', 'Sold Once']]);
    }

    public function test_staff_cannot_read_the_report_product_list(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/reports/sales/products')->assertForbidden();
    }
}
