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
     * Seeds sales_history from the record the system actually runs on:
     * database/data/all_transac_record.csv,
     * one row per product per day (product_sku, sale_date, quantity_sold),
     * July 2022 to June 2026, the same file imported locally and on the live
     * site. It replaced Sales_Records_4Year.csv (2026-10-22, at the user's
     * request), an older generated record nothing else read any more, so a
     * fresh install now starts on the same data the forecasts were measured on.
     *
     * The record is SYNTHETIC: generated over the pharmacy's real catalogue
     * (the source zip carries its generate_dataset.py), not exported from the
     * till. Rows are still summed per (product_sku, sale_date) before insert,
     * because sales_history is unique on that pair.
     *
     * NOTE: this seeder only INSERTS. Re-running it over a populated table
     * violates that unique constraint -- truncate sales_history first, or use
     * `php artisan sales-history:import --file=... --write`, which archives the
     * old rows and replaces them.
     */
    public const FILE = 'data/all_transac_record.csv';

    public function run(): void
    {
        $path = database_path(self::FILE);

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

            $sku = trim($record['product_sku'] ?? '');
            $qty = (int) ($record['quantity_sold'] ?? 0);
            $date = $this->parseDate(trim($record['sale_date'] ?? ''));

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

        $this->command->info("Sales history seeded: {$count} product/date rows from {$read} lines ({$skipped} skipped — missing SKU/date/qty).");
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
