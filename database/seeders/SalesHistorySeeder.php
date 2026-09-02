<?php

namespace Database\Seeders;

use App\Models\SalesHistory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class SalesHistorySeeder extends Seeder
{
    /**
     * Reads the raw Sales_Records_4Year.csv (one row per POS sale line —
     * Sale ID, Date, Product ID, SKU / Barcode, Product Name, Qty Sold,
     * Unit Price, Subtotal, Cashier, Payment Method) and aggregates it into
     * the (product_sku, sale_date, quantity_sold) shape the sales_history
     * table expects. Aggregation is required because multiple sale lines
     * can share the same product + date, and sales_history has a unique
     * constraint on that pair.
     */
    public function run(): void
    {
        // SYNTHETIC DEMO DATA, regenerated to look like a community pharmacy's
        // till: baskets of ~2 lines, quantities of 1-3 (occasionally a full
        // course), a Pareto popularity curve so slow movers really are slow, a
        // real weekday shape and the Philippine payday spike. It keeps the
        // deliberate 12-month seasonal cycle per product, which the original
        // 3-year file did not have (month-of-year explained 38% of a product's
        // variance against a 35% pure-noise baseline — i.e. nothing) and which
        // seasonal SARIMA needs to be worth running at all.
        //
        // NOTE: this seeder only INSERTS. Re-running it over a populated table
        // violates the (product_sku, sale_date) unique constraint — truncate
        // sales_history first.
        $path = database_path('data/Sales_Records_4Year.csv');

        if (! file_exists($path)) {
            $this->command->warn("File not found: {$path} — skipping sales history (not part of this import).");

            return;
        }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $aggregated = [];
        $skipped = 0;
        $read = 0;

        foreach ($csv->getRecords() as $record) {
            $read++;

            $sku = trim($record['SKU / Barcode'] ?? '');
            $qty = (int) ($record['Qty Sold'] ?? 0);
            $date = $this->parseDate(trim($record['Date'] ?? ''));

            if ($sku === '' || $qty <= 0 || ! $date) {
                $skipped++;

                continue;
            }

            $key = $sku.'|'.$date;
            $aggregated[$key] = ($aggregated[$key] ?? 0) + $qty;
        }

        $rows = [];
        $now = now();
        $count = 0;

        foreach ($aggregated as $key => $qty) {
            [$sku, $date] = explode('|', $key, 2);

            $rows[] = [
                'product_sku' => $sku,
                'sale_date' => $date,
                'quantity_sold' => $qty,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($rows) === 1000) {
                DB::table('sales_history')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('sales_history')->insert($rows);
        }

        // The Dashboard and Analytics report cache multi-second aggregates of
        // this table (see App\Models\SalesHistory); drop them so a reseed is
        // reflected immediately instead of after the TTL lapses.
        SalesHistory::forgetCaches();

        $this->command->info("Sales history seeded: {$count} product/date rows, aggregated from {$read} raw sale lines ({$skipped} skipped — missing SKU/date/qty).");
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('m/d/Y', $value)->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
