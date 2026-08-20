<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\ProductClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportReceivingReports extends Command
{
    protected $signature = 'import:receiving-reports {files*}';

    protected $description = 'Import historical Inventory Receiving Reports as products + historical batch records (no live stock)';

    public function handle(): int
    {
        // The report has no category column -- it files everything under the
        // description in its INVENTORY column, which is also what becomes the
        // product name. ProductClassifier reads that description; anything it
        // cannot place lands in Uncategorized rather than being guessed into a
        // real category.
        $fallbackCategoryId = Category::firstOrCreate(['name' => 'Uncategorized'])->id;
        $categoryIds = Category::pluck('id', 'name');

        $categoryIdFor = function (string $name) use (&$categoryIds, $fallbackCategoryId): int {
            $target = ProductClassifier::classify($name);

            if ($target === null) {
                return $fallbackCategoryId;
            }

            if (! $categoryIds->has($target)) {
                $categoryIds[$target] = Category::create(['name' => $target])->id;
            }

            return $categoryIds[$target];
        };

        // Parse every file first, collect all rows in memory
        $parsedRows = [];
        foreach ($this->argument('files') as $path) {
            if (! file_exists($path)) {
                $this->error("File not found: {$path}");

                continue;
            }

            $this->info("Reading {$path}...");
            $sheet = IOFactory::load($path)->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            $colMap = null;

            foreach ($rows as $row) {
                $cell0 = trim((string) ($row[0] ?? ''));

                if ($cell0 === 'Supplier >>') {
                    $colMap = null;

                    continue;
                }

                if (strtoupper($cell0) === 'DATE') {
                    $colMap = [];
                    foreach ($row as $idx => $val) {
                        $name = strtoupper(trim((string) $val));
                        if ($name !== '') {
                            $colMap[$name] = $idx;
                        }
                    }

                    continue;
                }

                if ($colMap === null) {
                    continue;
                }

                $dateVal = trim((string) ($row[$colMap['DATE'] ?? 0] ?? ''));
                $qtyVal = isset($colMap['QTY RCV']) ? trim((string) ($row[$colMap['QTY RCV']] ?? '')) : '';

                if ($dateVal === '' || $qtyVal === '' || strtoupper($qtyVal) === 'TOTAL') {
                    continue;
                }

                if (! isset($colMap['INVENTORY'])) {
                    continue;
                }

                $productSku = trim((string) ($row[$colMap['INVENTORY']] ?? ''));
                if ($productSku === '') {
                    continue;
                }

                $qty = (int) str_replace(',', '', $qtyVal);
                if ($qty <= 0) {
                    continue;
                }

                $cost = isset($colMap['COST']) ? (float) str_replace(',', '', trim((string) ($row[$colMap['COST']] ?? '0'))) : 0.0;
                $drNo = isset($colMap['DR NO']) ? trim((string) ($row[$colMap['DR NO']] ?? '')) : '';

                $date = $this->parseDate($dateVal);
                if (! $date) {
                    continue;
                }

                $parsedRows[] = compact('productSku', 'qty', 'cost', 'drNo', 'date');
            }
        }

        if (empty($parsedRows)) {
            $this->error('No valid rows parsed from the given files.');

            return self::FAILURE;
        }

        $this->info(count($parsedRows).' rows parsed. Preparing products...');

        // Step 1: find which SKUs already exist, in ONE query
        $uniqueSkus = collect($parsedRows)->pluck('productSku')->unique()->values();
        $existingSkuToId = DB::table('products')
            ->whereIn('sku', $uniqueSkus)
            ->pluck('id', 'sku');

        // Step 2: bulk-insert products that don't exist yet
        $now = now();
        $newProductRows = $uniqueSkus
            ->diff($existingSkuToId->keys())
            ->map(fn ($sku) => [
                'name' => $sku,
                'sku' => $sku,
                'category_id' => $categoryIdFor($sku),
                'unit' => 'pcs',
                'selling_price' => 0,
                'reorder_level' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        foreach (array_chunk($newProductRows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        $productsCreated = count($newProductRows);

        // Re-fetch the full sku => id map now that new products exist
        $skuToId = DB::table('products')->whereIn('sku', $uniqueSkus)->pluck('id', 'sku');

        $this->info("{$productsCreated} new products created. Preparing batch records...");

        // Step 3: bulk-insert product_batches in chunks
        $batchRows = [];
        $batchesCreated = 0;

        foreach ($parsedRows as $r) {
            $productId = $skuToId[$r['productSku']] ?? null;
            if (! $productId) {
                continue; // shouldn't happen, but stay safe
            }

            $batchRows[] = [
                'product_id' => $productId,
                'batch_number' => $r['drNo'] !== '' ? $r['drNo'] : 'HIST-'.$r['date']->format('Ymd').'-'.Str::random(4),
                'quantity' => 0,
                'qty_received' => $r['qty'],
                'unit_cost' => $r['cost'],
                'dr_no' => $r['drNo'] ?: null,
                'expiry_date' => null,
                'received_date' => $r['date']->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $batchesCreated++;

            if (count($batchRows) >= 500) {
                DB::table('product_batches')->insert($batchRows);
                $batchRows = [];
            }
        }

        if (! empty($batchRows)) {
            DB::table('product_batches')->insert($batchRows);
        }

        $this->info("Done. New products: {$productsCreated}. History records created: {$batchesCreated}.");

        return self::SUCCESS;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            if (str_contains($value, '/')) {
                return Carbon::createFromFormat('m/d/Y', $value);
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
