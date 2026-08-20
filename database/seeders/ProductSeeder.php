<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductClassifier;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/inventory_seeder.csv');

        if (! file_exists($path)) {
            $this->command->error("File not found: {$path}");

            return;
        }

        $categoryMap = Category::pluck('id', 'name');

        // The master file's Category column is unreliable -- it reads like a
        // substring match over the product name, which is how deodorants ended
        // up under Medicine / Pharmaceutical and ice cream under Personal Care.
        // Prefer what the name itself says; fall back to the file's column only
        // when no rule is confident. See App\Services\ProductClassifier.
        $classifiedCategoryId = function (string $productName) use (&$categoryMap): ?int {
            $target = ProductClassifier::classify($productName);

            if ($target === null) {
                return null;
            }

            if (! $categoryMap->has($target)) {
                $categoryMap[$target] = Category::create(['name' => $target])->id;
            }

            return $categoryMap[$target];
        };

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $count = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);

            // Name first, then the file's own column -- normalized the same way
            // CategorySeeder normalized it when creating the rows, so aliased
            // names still resolve to a category id.
            $categoryId = $classifiedCategoryId($data['Product Name'])
                ?? $categoryMap[Category::normalizeName($data['Category'])]
                ?? null;

            if (! $categoryId) {
                $this->command->warn("Skipping '{$data['Product Name']}': unknown category '{$data['Category']}'");
                $skipped++;

                continue;
            }

            $sku = trim($data['SKU / Barcode']);
            $stock = (int) $data['Stock'];

            // reorder_level: no source column for this, so derive a sensible
            // placeholder scaled to typical stock level (clamped 5-100)
            $reorderLevel = max(5, min(100, (int) round($stock / 10)));

            Product::updateOrCreate(
                ['sku' => $sku], // safe to re-run
                [
                    'name' => $data['Product Name'],
                    'barcode' => $sku, // source file combines SKU/Barcode into one field
                    'category_id' => $categoryId,
                    'unit' => $data['Unit'],
                    'selling_price' => $data['Selling Price'],
                    'reorder_level' => $reorderLevel,
                ]
            );
            $count++;
        }
        fclose($handle);

        $this->command->info("Products seeded: {$count} (skipped: {$skipped})");
    }
}
