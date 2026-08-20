<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use League\Csv\Reader;

class ProductBatchSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOpeningStock();
        $this->seedTransactionHistory();
    }

    /**
     * One "opening" batch per product, built from the current Stock and
     * Nearest Expiry columns in the inventory master file. This is what
     * makes Product::total_stock / is_low_stock / nearest_expiry correct
     * as soon as the database is seeded.
     */
    private function seedOpeningStock(): void
    {
        $path = database_path('data/inventory_seeder.csv');

        if (! file_exists($path)) {
            $this->command->error("File not found: {$path}");
            return;
        }

        $productIdBySku = Product::pluck('id', 'sku');

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $rows = [];
        $now = now();
        $count = 0;
        $skipped = 0;

        foreach ($csv->getRecords() as $record) {
            $sku = trim($record['SKU / Barcode'] ?? '');
            $productId = $productIdBySku[$sku] ?? null;

            if (! $productId) {
                $skipped++;
                continue;
            }

            // The master export carries Stock = 0 for products that were out
            // on export day. Seeding that verbatim leaves them unsellable at
            // the till, so empty rows open at Product::openingStockFloor().
            // See migration 2026_08_17_000004, which fixes already-seeded data.
            $stock = (int) $record['Stock'];

            if ($stock <= 0) {
                $stock = \App\Models\Product::openingStockFloor();
            }
            $expiry = $this->parseDate(trim($record['Nearest Expiry'] ?? ''));

            $rows[] = [
                'product_id'    => $productId,
                'batch_number'  => 'OPENING-' . $sku,
                'quantity'      => $stock,       // live/current stock
                'qty_received'  => $stock,
                'unit_cost'     => null,          // not tracked at this granularity in the master file
                'dr_no'         => null,
                'expiry_date'   => $expiry,
                'received_date' => $now->toDateString(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $count++;

            if (count($rows) === 500) {
                ProductBatch::insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            ProductBatch::insert($rows);
        }

        $this->command->info("Opening stock batches seeded: {$count} (skipped: {$skipped} — SKU not found in products)");
    }

    /**
     * Historical receiving/purchase records, imported as zero-quantity
     * batches (via qty_received/unit_cost/dr_no) so they contribute
     * purchase-cost and DR history without double-counting against the
     * opening stock quantity seeded above.
     */
    private function seedTransactionHistory(): void
    {
        $path = database_path('data/transaction_history_seed.csv');

        if (! file_exists($path)) {
            $this->command->error("File not found: {$path}");
            return;
        }

        $productIdBySku = Product::pluck('id', 'sku');

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $rows = [];
        $now = now();
        $count = 0;
        $skipped = 0;

        foreach ($csv->getRecords() as $record) {
            $sku = trim($record['SKU / Barcode'] ?? '');
            $productId = $productIdBySku[$sku] ?? null;

            if (! $productId) {
                $skipped++;
                continue;
            }

            $receivedDate = $this->parseDate(trim($record['Date'] ?? ''));
            $drNo = trim($record['DR No'] ?? '');
            $batchNumber = $drNo !== '' ? $drNo : ('TXN-' . trim($record['Transaction ID'] ?? uniqid()));

            $rows[] = [
                'product_id'    => $productId,
                'batch_number'  => $batchNumber,
                'quantity'      => 0, // historical only — does not affect live stock
                'qty_received'  => (int) $record['Qty Received'],
                'unit_cost'     => (float) $record['Unit Cost'],
                'dr_no'         => $drNo !== '' ? $drNo : null,
                'expiry_date'   => null,
                'received_date' => $receivedDate ?? $now->toDateString(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
            $count++;

            if (count($rows) === 1000) {
                ProductBatch::insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            ProductBatch::insert($rows);
        }

        $this->command->info("Transaction history batches seeded: {$count} (skipped: {$skipped} — SKU not found in products)");
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
