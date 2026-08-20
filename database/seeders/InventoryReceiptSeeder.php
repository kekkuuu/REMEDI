<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class InventoryReceiptSeeder extends Seeder
{
    /**
     * Populates inventory_receipts (product_sku, qty, received_at) straight
     * from the transaction history CSV, in the flat shape that
     * resources/python/generate_forecasts.py expects when run with
     * --source=mysql. This is what makes `php artisan forecast:generate
     * --source=mysql` (and therefore /forecasts) work with real history.
     */
    public function run(): void
    {
        $path = base_path('Transaction_Records_Seed.csv');

        if (! file_exists($path)) {
            $this->command->error("File not found: {$path}");
            return;
        }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $rows = [];
        $now = now();
        $count = 0;
        $skipped = 0;

        foreach ($csv->getRecords() as $record) {
            $sku = trim($record['SKU / Barcode'] ?? '');
            $qty = (int) ($record['Qty Received'] ?? 0);
            $date = $this->parseDate(trim($record['Date'] ?? ''));

            if ($sku === '' || $qty <= 0 || ! $date) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'product_sku' => $sku,
                'qty'         => $qty,
                'received_at' => $date,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            $count++;

            if (count($rows) === 1000) {
                DB::table('inventory_receipts')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            DB::table('inventory_receipts')->insert($rows);
        }

        $this->command->info("Inventory receipts seeded: {$count} (skipped: {$skipped})");
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
