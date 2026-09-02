<?php

namespace Tests\Feature\Alerts;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bottom-right alert toasts.
 *
 * These assert against the #remediToasts container ONLY, never the whole
 * response. Every authenticated page also renders the notification bell, which
 * lists all five alert kinds -- so a bare assertSee('Expired stock') would pass
 * on the bell's copy and prove nothing about the toasts.
 */
class AlertToastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AlertService::forget();
    }

    private function product(string $name, int $reorder, int $qty, string $expiry): Product
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
            'qty_received' => $qty,
            'unit_cost' => 1,
            'expiry_date' => $expiry,
            'received_date' => now()->subDays(30)->toDateString(),
        ]);

        return $product->fresh('batches');
    }

    /**
     * The JSON the toast queue is seeded from.
     *
     * Named toastSeed, not seed: TestCase::seed() already exists (it runs a
     * database seeder) and shadowing it turns every call here into a
     * BindingResolutionException with the whole page as the "class name".
     *
     * @return array{items?: list<array<string,mixed>>, counts?: array<string,int>}
     */
    private function toastSeed(string $html): array
    {
        if (! preg_match('/id="remediToastSeed">(.*?)<\/script>/s', $html, $m)) {
            return [];
        }

        return json_decode(html_entity_decode($m[1]), true) ?: [];
    }

    /**
     * The alert kinds the greeting would play.
     *
     * @return list<string>
     */
    private function kinds(string $html): array
    {
        return array_values(array_unique(
            array_column($this->toastSeed($html)['items'] ?? [], 'kind')
        ));
    }

    private function page(): string
    {
        return $this->actingAs(User::factory()->create())->get('/inventory')->content();
    }

    private function seedAllThreeKinds(): void
    {
        // Deep below its reorder level, but nowhere near expiry.
        $this->product('Low Item', 10, 2, now()->addDays(300)->toDateString());
        // Inside the 30-day expiry horizon, outside the 10-day non-pharma
        // return window.
        $this->product('Soon Item', 1, 50, now()->addDays(20)->toDateString());
        // Inside both, so it drives the returnable kind too.
        $this->product('Return Item', 1, 50, now()->addDays(5)->toDateString());
    }

    public function test_it_queues_every_stock_kind_it_watches(): void
    {
        $this->seedAllThreeKinds();
        $this->product('Expired Item', 1, 50, now()->subDays(10)->toDateString());

        $kinds = $this->kinds($this->page());

        $this->assertContains('low_stock', $kinds);
        $this->assertContains('expiring', $kinds);
        $this->assertContains('expired', $kinds);
        $this->assertContains('need_to_return', $kinds);
    }

    /**
     * One card per notification, naming the product -- not one card summarising
     * a kind. The old summary wording must not survive anywhere in the seed.
     */
    public function test_each_card_names_a_product_rather_than_summarising_a_kind(): void
    {
        $this->seedAllThreeKinds();

        $items = $this->toastSeed($this->page())['items'];

        $this->assertNotEmpty($items);
        $this->assertContains('Low Item', array_column($items, 'title'));

        foreach ($items as $item) {
            $this->assertStringNotContainsString('at or below reorder level', $item['body']);
        }
    }

    /** Every queued card carries the moment its alert began. */
    public function test_every_card_carries_a_date(): void
    {
        $this->seedAllThreeKinds();

        foreach ($this->toastSeed($this->page())['items'] as $item) {
            $this->assertArrayHasKey('when', $item);
            $this->assertMatchesRegularExpression(
                '/^Since [A-Z][a-z]{2} \d{1,2}/',
                (string) $item['when'],
                'An inventory alert is a standing condition, so its time is an onset.'
            );
        }
    }

    /**
     * A missed return window is a standing regret rather than news, so it stays
     * on the bell and never interrupts anyone.
     */
    public function test_it_leaves_missed_returns_to_the_bell(): void
    {
        $this->seedAllThreeKinds();
        $this->product('Expired Item', 1, 50, now()->subDays(10)->toDateString());

        $this->assertNotContains('fail_to_return', $this->kinds($this->page()));
    }

    /**
     * The container is the mount point live pops are appended to, so it has to
     * exist even on a page that loaded with nothing wrong -- otherwise an alert
     * that appears while you are working would have nowhere to go.
     */
    public function test_the_container_still_renders_with_no_open_alerts(): void
    {
        $this->product('Healthy Item', 1, 500, now()->addDays(300)->toDateString());

        $html = $this->page();

        $this->assertStringContainsString('id="remediToasts"', $html, 'The mount point must render even when quiet.');
        $this->assertSame([], $this->toastSeed($html)['items'], 'Nothing should be queued.');
    }

    /**
     * The watched kinds travel with the seed even when nothing is open, because
     * the live watcher uses them to decide which incoming items it cares about.
     */
    public function test_the_seed_names_every_watched_kind(): void
    {
        $this->product('Healthy Item', 1, 500, now()->addDays(300)->toDateString());

        $this->assertSame(
            ['low_stock', 'expiring', 'expired', 'need_to_return'],
            $this->toastSeed($this->page())['kinds']
        );
    }

    /**
     * Cards are raised per item, never per kind. A summary card cannot say which
     * product, cannot carry an onset and cannot link at anything but a filter.
     */
    public function test_the_seed_carries_no_kind_summaries(): void
    {
        $this->seedAllThreeKinds();

        foreach ($this->toastSeed($this->page())['items'] as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertStringNotContainsString('at or below reorder level', $item['body']);
            $this->assertStringNotContainsString('can still go back to the supplier', $item['body']);
        }
    }

    /**
     * An empty shelf is not the same message as one running low, so it gets its
     * own colour -- while staying inside the low_stock KIND, which is what the
     * counts, tabs and Inventory filter all key on.
     */
    public function test_out_of_stock_is_coloured_apart_from_low_stock(): void
    {
        $this->product('Running Low', 10, 2, now()->addDays(300)->toDateString());
        $this->product('Sold Out', 10, 0, now()->addDays(300)->toDateString());

        $items = collect($this->toastSeed($this->page())['items'])->keyBy('title');

        $this->assertSame('is-low', $items['Running Low']['cls']);
        $this->assertSame('is-out', $items['Sold Out']['cls']);

        // Same kind either way: splitting it would double-count the totals.
        $this->assertSame('low_stock', $items['Sold Out']['kind']);
        $this->assertStringContainsString('Out of stock', $items['Sold Out']['body']);
    }

    public function test_a_fresh_sign_in_is_flagged_so_the_tab_is_greeted_again(): void
    {
        $this->seedAllThreeKinds();

        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertStringContainsString('data-fresh-login="1"', $this->get('/inventory')->content());
    }

    public function test_a_later_page_view_is_not_flagged_as_a_fresh_sign_in(): void
    {
        $this->seedAllThreeKinds();

        $this->assertStringContainsString('data-fresh-login="0"', $this->page());
    }
}
