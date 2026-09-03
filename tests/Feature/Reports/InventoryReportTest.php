<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inventory report's expired stock.
 *
 * The report has always shown an "Expired Stock" KPI, but the per-row badge
 * beside it was guarded on $product->expiredBatches -- which Product never
 * defined. Eloquent resolves an unknown name to null rather than erroring, so
 * the guard was permanently false and the badge never rendered on screen or on
 * the printout, while the KPI counted correctly. There was also no way to
 * filter down to those products.
 */
class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, int $qty, string $expiry, int $reorder = 1): Product
    {
        $category = Category::firstOrCreate(['name' => 'General Merchandise']);

        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => $reorder,
        ]);

        ProductBatch::create([
            'batch_number' => 'B'.substr(md5($name), 0, 6),
            'product_id' => $product->id,
            'quantity' => $qty,
            'qty_received' => max($qty, 1),
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => now()->subDays(200)->toDateString(),
        ]);

        return $product->fresh('batches');
    }

    /**
     * The product names actually rendered as rows.
     *
     * Scoped deliberately: asserting against the whole response would also
     * match a name inside chart data or a filter control, which says nothing
     * about what the table lists.
     *
     * @return list<string>
     */
    /**
     * The product names in the SCREEN copy of the report, in render order.
     *
     * Read out of the DOM rather than by matching a style attribute. This used
     * to grep for `<div style="font-weight:500;color:#111;">`, which tied every
     * assertion below to one inline style -- so moving the report's per-cell
     * styles into CSS classes (which took the page from 7.6 MB to 3.2 MB) broke
     * four tests that are about low-stock ORDERING and MEMBERSHIP and have
     * nothing to say about how a cell is painted.
     *
     * Structural, not stylistic: the second cell of a row is the product, and
     * its first div is the name (an expired-batch note can follow in the same
     * cell). #no-print scopes this to the screen table -- the print copy renders
     * the same products again, and counting both would double every row.
     */
    private function rows(string $html): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $names = [];

        foreach ((new \DOMXPath($dom))->query('//*[@id="no-print"]//tbody/tr/td[2]/div[1]') as $cell) {
            $name = trim($cell->textContent);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function kpi(string $html, string $label): ?string
    {
        return preg_match(
            '/kpi-label">'.preg_quote($label, '/').'<\/span>.*?kpi-value">([\d,]+)</s',
            $html,
            $m
        ) ? $m[1] : null;
    }

    private function report(array $query = [])
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get('/reports/inventory'.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_expired_batches_resolves_instead_of_returning_null(): void
    {
        $product = $this->product('Expired Item', 25, now()->subDays(30)->toDateString());

        $this->assertCount(1, $product->expired_batches);
        $this->assertCount(1, Product::with('batches')->find($product->id)->expiredBatches);
    }

    /** A returned batch is 0 on the shelf, so there is nothing left to pull. */
    public function test_it_ignores_batches_that_are_no_longer_on_the_shelf(): void
    {
        $product = $this->product('Emptied Item', 0, now()->subDays(30)->toDateString());

        $this->assertCount(0, $product->expired_batches);
    }

    public function test_the_row_badge_actually_renders(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());

        $this->report()->assertSee('1 expired batch', false);
    }

    public function test_the_expired_filter_narrows_to_expired_stock(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());
        $this->product('Healthy Item', 40, now()->addDays(300)->toDateString());

        $all = $this->report();
        $all->assertSee('Expired Item');
        $all->assertSee('Healthy Item');

        $filtered = $this->report(['expired' => 1]);
        $filtered->assertSee('Expired Item');
        $filtered->assertDontSee('Healthy Item');
    }

    /*
     * The status filter is ONE choice.
     *
     * It was two independent checkboxes, so both could be ticked -- and that
     * asks for the intersection of two sets this report deliberately keeps
     * disjoint: a product below its reorder level holding nothing but expired
     * units is excluded from low stock ON PURPOSE, because clearing it is the
     * job rather than reordering it. Ticking both therefore returned rows the
     * two KPIs above the table disagreed about. `status` is now a select, and
     * the old parameters are still honoured so bookmarks keep working.
     */

    public function test_the_status_filter_narrows_to_one_choice(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());
        $this->product('Healthy Item', 40, now()->addDays(300)->toDateString());

        $expired = $this->report(['status' => 'expired']);
        $expired->assertSee('Expired Item');
        $expired->assertDontSee('Healthy Item');
    }

    public function test_the_status_filter_is_rendered_as_a_single_select(): void
    {
        $html = $this->report(['status' => 'expired'])->getContent();

        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('<option value="expired" selected>', $html);

        // The two checkboxes are gone; a checkbox is what let both be chosen.
        $this->assertStringNotContainsString('type="checkbox" name="low_stock"', $html);
        $this->assertStringNotContainsString('type="checkbox" name="expired"', $html);
    }

    public function test_the_old_checkbox_parameters_still_work(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());
        $this->product('Healthy Item', 40, now()->addDays(300)->toDateString());

        $legacy = $this->report(['expired' => 1]);
        $legacy->assertSee('Expired Item');
        $legacy->assertDontSee('Healthy Item');

        // And the select shows which filter is actually in force, rather than
        // reading "All stock" above a narrowed table.
        $this->assertStringContainsString('<option value="expired" selected>', $legacy->getContent());
    }

    public function test_a_url_asking_for_both_resolves_to_one(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());
        $this->product('Healthy Item', 40, now()->addDays(300)->toDateString());

        // Hand-written, or a stale bookmark from when both could be ticked.
        $both = $this->report(['low_stock' => 1, 'expired' => 1]);

        // low_stock wins, and the page says so -- what it must NOT do is apply
        // both and print an empty table under two non-zero KPIs.
        $this->assertStringContainsString('<option value="low_stock" selected>', $both->getContent());
        $this->assertStringNotContainsString('<option value="expired" selected>', $both->getContent());
    }

    /** The KPI describes the filtered set, so it must agree with the rows. */
    public function test_the_kpi_and_the_rows_agree_under_the_filter(): void
    {
        $this->product('Expired One', 25, now()->subDays(30)->toDateString());
        $this->product('Expired Two', 10, now()->subDays(5)->toDateString());
        $this->product('Healthy Item', 40, now()->addDays(300)->toDateString());

        $html = $this->report(['expired' => 1])->content();

        $this->assertSame('2', $this->kpi($html, 'Expired Stock'));
    }

    /**
     * Worst first. The Inventory page's low-stock tab already orders this way;
     * the report listed alphabetically, so the product actually about to run
     * out could sit on page three.
     */
    public function test_low_stock_is_ordered_by_stock_not_by_name(): void
    {
        // Names deliberately in the opposite order to the stock levels.
        $this->product('Aaa Plenty', 9, now()->addDays(300)->toDateString(), 10);
        $this->product('Mmm Middle', 4, now()->addDays(300)->toDateString(), 10);
        $this->product('Zzz Empty', 0, now()->addDays(300)->toDateString(), 10);

        $html = $this->report(['low_stock' => 1])->content();

        $this->assertSame(['Zzz Empty', 'Mmm Middle', 'Aaa Plenty'], $this->rows($html));
    }

    /**
     * A shelf full of expired units is an EXPIRED problem, not a reorder one.
     * is_low_stock compares sellable stock, so such a product used to be listed
     * as "Low Stock" on a report that already had an Expired filter saying the
     * same thing better.
     */
    public function test_low_stock_ignores_products_that_are_short_only_because_of_expiry(): void
    {
        // 40 units on the shelf against a reorder level of 1 -- but all expired,
        // so sellable_stock is 0 and is_low_stock is true.
        $expired = $this->product('All Expired', 40, now()->subDays(20)->toDateString(), 1);
        $this->assertTrue($expired->is_low_stock, 'precondition: sellable-based rule flags it');

        // Genuinely running out of good stock.
        $this->product('Really Low', 2, now()->addDays(300)->toDateString(), 10);

        $rows = $this->rows($this->report(['low_stock' => 1])->content());

        $this->assertContains('Really Low', $rows);
        $this->assertNotContains('All Expired', $rows);
    }

    /**
     * The real-world case: a product that is below its reorder level AND whose
     * remaining units are all expired. It passes the "running out" test, so the
     * first cut of this rule still listed it. Clearing the expired stock is the
     * job, not reordering, and the Expired filter already lists it.
     */
    public function test_low_stock_ignores_a_shelf_holding_only_expired_units(): void
    {
        // 1 unit left, expired, reorder level 5 -- low on both counts.
        $this->product('Babyflo Bottle', 1, now()->subDays(20)->toDateString(), 5);
        $this->product('Really Low', 2, now()->addDays(300)->toDateString(), 10);

        $rows = $this->rows($this->report(['low_stock' => 1])->content());

        $this->assertContains('Really Low', $rows);
        $this->assertNotContains('Babyflo Bottle', $rows);

        // It has not vanished from the report -- it belongs to Expired.
        $this->assertContains('Babyflo Bottle', $this->rows($this->report(['expired' => 1])->content()));
    }

    /**
     * A product with NOTHING on the shelf is genuinely out and does need
     * reordering, so the expired-stock exclusion must not swallow it.
     */
    public function test_low_stock_still_includes_products_with_no_stock_at_all(): void
    {
        $this->product('Sold Out', 0, now()->addDays(300)->toDateString(), 5);

        $this->assertContains('Sold Out', $this->rows($this->report(['low_stock' => 1])->content()));
    }

    /**
     * The bell's low-stock alert links straight at the Inventory low-stock
     * filter, so the two must count the same set. When they disagreed about the
     * expiring horizon, a badge promising 28 batches opened a list of 85.
     */
    public function test_the_inventory_tab_and_the_alert_count_agree(): void
    {
        $expired = $this->product('All Expired', 40, now()->subDays(20)->toDateString(), 1);
        $low = $this->product('Really Low', 2, now()->addDays(300)->toDateString(), 10);
        $this->product('Healthy', 90, now()->addDays(300)->toDateString(), 5);

        AlertService::forget();
        $payload = app(AlertService::class)->payload();
        $alertCount = collect($payload['alerts'])->firstWhere('kind', 'low_stock')['count'] ?? 0;

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get('/inventory?filter=low_stock')->content();

        /* Asserted on row ids, not product names. Every authenticated page also
         * renders the notification bell, which lists the expired product by
         * name -- so assertDontSee('All Expired') matches the bell's copy and
         * proves nothing about the table. id="product-row-{id}" is only ever
         * emitted by a rendered row. */
        $this->assertSame(1, $alertCount, 'only the genuinely low product counts');
        $this->assertStringContainsString('id="product-row-'.$low->id.'"', $html);
        $this->assertStringNotContainsString('id="product-row-'.$expired->id.'"', $html);
        $this->assertSame(
            $alertCount,
            preg_match_all('/id="product-row-\d+"/', $html),
            'the badge and the list it opens must agree'
        );
    }

    /** The Low Stock KPI must count the same set the filter shows. */
    public function test_the_low_stock_kpi_uses_the_same_definition(): void
    {
        $this->product('All Expired', 40, now()->subDays(20)->toDateString(), 1);
        $this->product('Really Low', 2, now()->addDays(300)->toDateString(), 10);

        $this->assertSame('1', $this->kpi($this->report()->content(), 'Low Stock'));
    }

    /** Zero is its own state, and it is checked before "low". */
    public function test_out_of_stock_is_labelled_apart_from_low_stock(): void
    {
        $this->product('Sold Out', 0, now()->addDays(300)->toDateString(), 10);
        $this->product('Running Low', 3, now()->addDays(300)->toDateString(), 10);

        $html = $this->report()->content();

        $this->assertStringContainsString('Out of Stock', $html);
        $this->assertStringContainsString('Low Stock', $html);
        // Graphite, the same colour the bell and toasts use for out of stock.
        $this->assertStringContainsString('#334155', $html);
    }

    public function test_the_filter_is_recorded_on_the_audit_entry(): void
    {
        $this->product('Expired Item', 25, now()->subDays(30)->toDateString());

        $this->report(['expired' => 1]);

        $this->assertDatabaseHas('audit_trails', [
            'action' => 'Viewed',
            'details' => 'Generated Inventory Report (filtered)',
        ]);
    }
}
