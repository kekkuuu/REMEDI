<?php

namespace Tests\Feature\Reports;

use App\Models\Product;
use App\Models\SalesHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Every report export, downloaded and OPENED: the Sales, Inventory and
 * Analytics reports in Excel and PDF. The Excel files are read back with
 * PhpSpreadsheet and their columns and totals compared with figures computed
 * here straight from SQL -- not from ReportController, so a bug in the report's
 * own arithmetic cannot vouch for itself.
 *
 * MYSQL ONLY, READ-ONLY. The report aggregates use STRAIGHT_JOIN and other
 * MySQL syntax the sqlite suite cannot run, so on sqlite every case is
 * skipped. Against MySQL it reads the real catalogue and sales record:
 * DatabaseTransactions (never RefreshDatabase) rolls back the audit rows the
 * exports write, and nothing else is written. Run it on its own:
 *
 *     php artisan test --filter=ReportExportTest
 *
 * (no sqlite override -- it needs the .env database; the filter keeps every
 * RefreshDatabase class out of the run).
 */
class ReportExportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Report exports run MySQL-only aggregates; run this class against the MySQL database.');
        }
        if (! Product::query()->exists()) {
            $this->markTestSkipped('No catalogue in this database to export.');
        }
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->where('is_active', true)->firstOrFail();
    }

    private function download(string $url): TestResponse
    {
        return $this->actingAs($this->admin())->get($url)->assertOk();
    }

    /** Every row of every sheet, raw values, keyed by sheet title. */
    private function sheets(TestResponse $response): array
    {
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $out = [];
        foreach ($book->getAllSheets() as $sheet) {
            $out[$sheet->getTitle()] = $sheet->toArray(null, true, false, false);
        }

        return $out;
    }

    private function assertPdf(TestResponse $response): void
    {
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $body = $response->baseResponse instanceof BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(1000, strlen($body));
    }

    private static function cents(float|int|string|null $v): int
    {
        return (int) round(((float) $v) * 100);
    }

    /** Imported + till revenue over a range, from SQL alone. */
    private function revenueBetween(string $start, string $end, ?int $categoryId = null): array
    {
        $end = min($end, SalesHistory::reportableThrough());

        $history = DB::table('sales_history as sh')
            ->join('products as p', 'p.sku', '=', 'sh.product_sku')
            ->whereBetween('sh.sale_date', [$start, $end])
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->sum(DB::raw('sh.quantity_sold * p.selling_price'));

        $pos = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->where('s.payment_voided', false)
            ->whereBetween(DB::raw('DATE(s.created_at)'), [$start, $end])
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->sum('si.subtotal');

        return ['history' => (float) $history, 'pos' => (float) $pos, 'total' => (float) $history + (float) $pos];
    }

    // ── Sales Report ─────────────────────────────────────────────────────

    public function test_the_sales_excel_carries_every_bucket_and_adds_up_to_the_total(): void
    {
        [$start] = SalesHistory::dateBounds();
        $end = SalesHistory::reportableThrough();

        $rows = $this->sheets($this->download("/reports/sales/export?format=xlsx&start_date={$start}&end_date={$end}&group=month"));
        $sheet = $rows['Sales Report'] ?? null;
        $this->assertNotNull($sheet, 'Sheet "Sales Report" missing; got: '.implode(', ', array_keys($rows)));

        $this->assertSame(['Period', 'Units Sold', 'Imported Revenue (PHP)', 'This Terminal Revenue (PHP)', 'Total Revenue (PHP)'], $sheet[0]);
        $data = array_slice($sheet, 1);

        $expected = $this->revenueBetween($start, $end);
        $this->assertSame(self::cents($expected['total']), array_sum(array_map(fn ($r) => self::cents($r[4]), $data)), 'Total Revenue column');
        $this->assertSame(self::cents($expected['pos']), array_sum(array_map(fn ($r) => self::cents($r[3]), $data)), 'This Terminal column');
        $this->assertSame(self::cents($expected['history']), array_sum(array_map(fn ($r) => self::cents($r[2]), $data)), 'Imported column');

        // Every row is internally consistent: imported + terminal = total.
        foreach ($data as $r) {
            $this->assertSame(self::cents($r[4]), self::cents($r[2]) + self::cents($r[3]), "Row {$r[0]} does not add up");
        }
    }

    /**
     * A daily export over the whole record is far longer than the screen's
     * breakdown table (which lists the latest rows only) -- the FILE must
     * still carry every day, or its total quietly falls short of the report.
     */
    public function test_a_daily_sales_export_is_not_cut_short(): void
    {
        [$start] = SalesHistory::dateBounds();
        $end = SalesHistory::reportableThrough();

        $sheet = $this->sheets($this->download("/reports/sales/export?format=xlsx&start_date={$start}&end_date={$end}&group=day"))['Sales Report'];
        $data = array_slice($sheet, 1);

        $days = DB::query()->fromSub(
            DB::table('sales_history')->whereBetween('sale_date', [$start, $end])->select(DB::raw('sale_date as d'))
                ->union(DB::table('sales')->where('payment_voided', false)->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])->select(DB::raw('DATE(created_at) as d'))),
            'days'
        )->count();

        $this->assertCount($days, $data, 'one row per day with a sale');
        $this->assertSame(self::cents($this->revenueBetween($start, $end)['total']), array_sum(array_map(fn ($r) => self::cents($r[4]), $data)));
    }

    public function test_a_category_scoped_sales_export_counts_only_that_category(): void
    {
        $categoryId = (int) DB::table('products')->whereNull('archived_at')->groupBy('category_id')
            ->orderByRaw('COUNT(*) DESC')->value('category_id');
        $start = now()->subMonths(3)->startOfMonth()->toDateString();
        $end = SalesHistory::reportableThrough();

        $book = $this->sheets($this->download("/reports/sales/export?format=xlsx&start_date={$start}&end_date={$end}&category_id={$categoryId}"));
        $sheet = reset($book);
        $this->assertStringStartsWith('Sales Report', array_key_first($book));

        $this->assertSame(
            self::cents($this->revenueBetween($start, $end, $categoryId)['total']),
            array_sum(array_map(fn ($r) => self::cents($r[4]), array_slice($sheet, 1)))
        );
    }

    public function test_the_sales_pdf_is_a_real_pdf(): void
    {
        $this->assertPdf($this->download('/reports/sales/export?format=pdf&start_date='.now()->startOfMonth()->toDateString().'&end_date='.SalesHistory::reportableThrough()));
    }

    // ── Inventory Report ─────────────────────────────────────────────────

    public function test_the_inventory_excel_lists_every_active_product_with_its_stock_value(): void
    {
        $sheet = $this->sheets($this->download('/reports/inventory/export?format=xlsx'))['Inventory Report'];
        $this->assertSame(['Product', 'SKU', 'Category', 'Unit', 'Total Stock', 'Unit Price (PHP)', 'Stock Value (PHP)', 'Status'], $sheet[0]);
        $data = array_slice($sheet, 1);

        $this->assertCount(Product::count(), $data, 'one row per active product');
        $this->assertEqualsCanonicalizing(Product::pluck('sku')->map(fn ($s) => (string) $s)->all(), array_map(fn ($r) => (string) $r[1], $data));

        // Units on the shelf and their value, from the batches themselves.
        $units = (int) DB::table('product_batches as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->whereNull('p.archived_at')->whereNull('b.archived_at')->where('b.quantity', '>', 0)->sum('b.quantity');
        $value = DB::table('product_batches as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->whereNull('p.archived_at')->whereNull('b.archived_at')->where('b.quantity', '>', 0)
            ->sum(DB::raw('b.quantity * p.selling_price'));

        $this->assertSame($units, array_sum(array_map(fn ($r) => (int) $r[4], $data)), 'Total Stock column');
        // Rows are rounded to centavos one by one, so allow a centavo per row.
        $this->assertEqualsWithDelta((float) $value, array_sum(array_map(fn ($r) => (float) $r[6], $data)), 0.01 * count($data), 'Stock Value column');

        foreach ($data as $r) {
            $this->assertSame(self::cents((float) $r[4] * (float) $r[5]), self::cents($r[6]), "Stock value for {$r[1]}");
            $this->assertContains($r[7], ['Out of Stock', 'Expired Stock', 'Low Stock', 'OK']);
        }
    }

    public function test_the_low_stock_inventory_export_is_exactly_the_reorder_list(): void
    {
        $sheet = $this->sheets($this->download('/reports/inventory/export?format=xlsx&status=low_stock'))['Inventory Report'];
        $skus = array_map(fn ($r) => (string) $r[1], array_slice($sheet, 1));

        $expected = Product::with('batches')->get()->filter(fn ($p) => $p->is_running_out)->pluck('sku')->map(fn ($s) => (string) $s)->all();

        $this->assertEqualsCanonicalizing($expected, $skus);
    }

    public function test_the_inventory_pdf_is_a_real_pdf(): void
    {
        $this->assertPdf($this->download('/reports/inventory/export?format=pdf&status=low_stock'));
    }

    // ── Analytics Report ─────────────────────────────────────────────────

    public function test_the_analytics_excel_ranks_products_by_revenue_and_gets_each_one_right(): void
    {
        $month = now()->subMonth()->format('Y-m');
        $start = now()->subMonth()->startOfMonth()->toDateString();
        $end = now()->subMonth()->endOfMonth()->toDateString();

        $book = $this->sheets($this->download("/reports/analytics/export?format=xlsx&month={$month}"));
        $this->assertArrayHasKey('Top Selling Products', $book);
        $top = $book['Top Selling Products'];
        $this->assertSame(['SKU', 'Product', 'Units Sold', 'Revenue (PHP)'], $top[0]);
        $rows = array_slice($top, 1);

        $this->assertNotEmpty($rows);
        $this->assertLessThanOrEqual(100, count($rows));

        $revenues = array_map(fn ($r) => self::cents($r[3]), $rows);
        $sorted = $revenues;
        rsort($sorted);
        $this->assertSame($sorted, $revenues, 'Top Selling must be ordered by revenue, highest first');

        // The top five, each recomputed from both records.
        foreach (array_slice($rows, 0, 5) as $r) {
            $sku = (string) $r[0];
            $history = DB::table('sales_history as sh')->join('products as p', 'p.sku', '=', 'sh.product_sku')
                ->where('sh.product_sku', $sku)->whereBetween('sh.sale_date', [$start, $end])
                ->selectRaw('COALESCE(SUM(sh.quantity_sold),0) q, COALESCE(SUM(sh.quantity_sold * p.selling_price),0) r')->first();
            $pos = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->join('products as p', 'p.id', '=', 'si.product_id')
                ->where('p.sku', $sku)->where('s.payment_voided', false)->whereBetween(DB::raw('DATE(s.created_at)'), [$start, $end])
                ->selectRaw('COALESCE(SUM(si.quantity),0) q, COALESCE(SUM(si.subtotal),0) r')->first();

            $this->assertSame((int) $history->q + (int) $pos->q, (int) $r[2], "Units for {$sku}");
            $this->assertSame(self::cents((float) $history->r + (float) $pos->r), self::cents($r[3]), "Revenue for {$sku}");
        }

        // The second sheet is either the slow movers or, when nothing sells
        // under the rate, the slowest sellers -- same columns either way.
        $second = array_values(array_diff(array_keys($book), ['Top Selling Products']));
        $this->assertCount(1, $second);
        $this->assertContains($second[0], ['Slow Moving Products', 'Slowest-Selling Products']);
        $this->assertSame(['SKU', 'Product', 'Units Sold', 'Stock on Hand'], $book[$second[0]][0]);
    }

    public function test_the_analytics_pdf_is_a_real_pdf(): void
    {
        $this->assertPdf($this->download('/reports/analytics/export?format=pdf&month='.now()->subMonth()->format('Y-m')));
    }

    public function test_an_export_without_a_format_is_refused_not_guessed(): void
    {
        foreach (['sales', 'inventory', 'analytics'] as $report) {
            $this->actingAs($this->admin())->getJson("/reports/{$report}/export")->assertStatus(422);
            $this->actingAs($this->admin())->getJson("/reports/{$report}/export?format=csv")->assertStatus(422);
        }
    }
}
