<?php

namespace Tests\Feature\Reports;

use App\Exports\SalesLineItemsSheet;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\SalesLineItems;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The Sales Report's "Transaction Items" sheet (2026-10-15, at the user's
 * request): one line per sale line in the transaction file's columns, from
 * BOTH records -- till sales as real lines, the imported record as one line
 * per product per day, priced at the current selling price so the lines add up
 * to the report's Total Sales. The whole report page is MySQL-only, so this
 * pins the builder and the file writer directly; ReportExportTest checks the
 * real download against MySQL.
 */
class SalesLineItemsTest extends TestCase
{
    use RefreshDatabase;

    private Product $mask;

    private Product $tablet;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $supplies = Category::firstOrCreate(['name' => 'Medical Supplies & Devices']);
        $this->mask = Product::create(['name' => '3D MASK DISPOSABLE X10', 'sku' => '4801812140441', 'category_id' => $supplies->id,
            'unit' => 'PCS', 'selling_price' => '1.05', 'cost_price' => '0.90', 'reorder_level' => 5]);
        $medicine = Category::firstOrCreate(['name' => 'Medicine / Pharmaceutical']);
        $this->tablet = Product::create(['name' => '=CMD TABLET', 'sku' => '4800000000017', 'category_id' => $medicine->id,
            'unit' => 'BOX', 'selling_price' => '10.00', 'cost_price' => null, 'reorder_level' => 5]);
        $this->cashier = User::factory()->create();

        DB::table('sales_history')->insert([
            ['product_sku' => $this->mask->sku, 'sale_date' => '2026-07-01', 'quantity_sold' => 2],
            ['product_sku' => $this->tablet->sku, 'sale_date' => '2026-07-02', 'quantity_sold' => 3],
            ['product_sku' => 'NO-SUCH-PRODUCT', 'sale_date' => '2026-07-02', 'quantity_sold' => 9],
        ]);

        // One till sale, the mask drawn from two batches (FEFO): ONE line.
        $sale = DB::table('sales')->insertGetId(['transaction_no' => 'TXN-20260703-0001', 'user_id' => $this->cashier->id,
            'total_amount' => 5.25, 'amount_paid' => 10, 'change_due' => 4.75, 'payment_voided' => false,
            'payment_method' => 'cash', 'created_at' => '2026-07-03 14:05:09', 'updated_at' => '2026-07-03 14:05:09']);
        DB::table('sale_items')->insert([
            ['sale_id' => $sale, 'product_id' => $this->mask->id, 'quantity' => 3, 'price' => 1.05, 'subtotal' => 3.15],
            ['sale_id' => $sale, 'product_id' => $this->mask->id, 'quantity' => 2, 'price' => 1.05, 'subtotal' => 2.10],
        ]);

        // A voided sale is never a line, as it is never revenue.
        $void = DB::table('sales')->insertGetId(['transaction_no' => 'TXN-20260703-0002', 'user_id' => $this->cashier->id,
            'total_amount' => 10, 'amount_paid' => 10, 'change_due' => 0, 'payment_voided' => true,
            'payment_method' => 'cash', 'created_at' => '2026-07-03 15:00:00', 'updated_at' => '2026-07-03 15:00:00']);
        DB::table('sale_items')->insert(['sale_id' => $void, 'product_id' => $this->tablet->id, 'quantity' => 1, 'price' => 10, 'subtotal' => 10]);
    }

    private function lines(?int $category = null, ?string $sku = null, ?int $cashier = null, int $cap = SalesLineItems::MAX_LINES): array
    {
        return SalesLineItems::between('2026-07-01', '2026-07-31', $category, $sku, $cashier, $cap);
    }

    public function test_both_records_become_lines_in_the_files_columns_oldest_first(): void
    {
        $r = $this->lines();

        $this->assertSame(3, $r['total']);
        $this->assertSame([
            ['Imported', '07/01/2026', 'PRD-'.str_pad((string) $this->mask->id, 5, '0', STR_PAD_LEFT), '3D MASK DISPOSABLE X10',
                '4801812140441', 'Medical Supplies & Devices', 'PCS', 2, 1.05, 0.9, 2.1, 1.8, 0.3],
            ['Imported', '07/02/2026', 'PRD-'.str_pad((string) $this->tablet->id, 5, '0', STR_PAD_LEFT), '=CMD TABLET',
                '4800000000017', 'Medicine / Pharmaceutical', 'BOX', 3, 10.0, null, 30.0, null, null],
            ['TXN-20260703-0001', '07/03/2026 02:05:09 PM', 'PRD-'.str_pad((string) $this->mask->id, 5, '0', STR_PAD_LEFT), '3D MASK DISPOSABLE X10',
                '4801812140441', 'Medical Supplies & Devices', 'PCS', 5, 1.05, 0.9, 5.25, 4.5, 0.75],
        ], $r['lines']);
    }

    public function test_the_filters_narrow_the_lines_like_the_report(): void
    {
        $this->assertSame(['Imported'], array_column($this->lines(sku: $this->tablet->sku)['lines'], 0));
        $this->assertCount(2, $this->lines(category: $this->mask->category_id)['lines']);

        // A cashier narrows to their till sales; the imported record has no cashier.
        $this->assertSame(['TXN-20260703-0001'], array_column($this->lines(cashier: $this->cashier->id)['lines'], 0));
    }

    public function test_over_the_cap_the_latest_lines_are_kept_and_the_sheet_says_so(): void
    {
        $r = $this->lines(cap: 2);

        $this->assertSame(3, $r['total']);
        $this->assertSame(2, $r['shown']);
        $this->assertSame(['07/02/2026', '07/03/2026 02:05:09 PM'], array_column($r['lines'], 1));

        $rows = iterator_to_array((new SalesLineItemsSheet($r))->rows(), false);
        $this->assertSame('TOTAL', $rows[2][0]);
        $this->assertSame(35.25, $rows[2][10]);
        $this->assertStringContainsString('Showing the latest 2 of 3 lines', end($rows)[0]);
    }

    public function test_the_file_keeps_the_sku_exact_and_never_writes_a_formula(): void
    {
        $sheet = new SalesLineItemsSheet($this->lines());
        $writer = new XlsxWriter;
        $writer->addSheet($sheet->title(), $sheet->headings(), $sheet->rows(), $sheet->kinds(), $sheet->widths());
        $path = $writer->save();

        $book = IOFactory::load($path);
        unlink($path);
        $ws = $book->getSheetByName('Transaction Items');
        $this->assertNotNull($ws);

        $this->assertSame(SalesLineItems::HEADINGS, array_slice($ws->rangeToArray('A1:M1')[0], 0, 13));
        // Text cells are inline strings, which PhpSpreadsheet reads back as rich text.
        $text = fn (string $ref) => (string) $ws->getCell($ref)->getValue();
        $this->assertSame('4801812140441', $text('E2'));
        $this->assertSame('=CMD TABLET', $text('D3'));
        $this->assertNotSame('f', $ws->getCell('D3')->getDataType());
        $this->assertEquals(5.25, $ws->getCell('K4')->getValue());
        $this->assertSame('TOTAL', $text('A5'));
        $this->assertEquals(37.35, $ws->getCell('K5')->getValue());
    }
}
