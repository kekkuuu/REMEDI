<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/inventory_seeder.csv');

        if (!file_exists($path)) {
            $this->command->error("File not found: {$path}");
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        // ['Product ID','Product Name','SKU / Barcode','Category','Unit','Selling Price','Stock','Nearest Expiry']

        $seen = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            // Folds the CSV's duplicate "Beverages" spelling onto
            // "Water & Beverages" so they seed as one category.
            $name = Category::normalizeName($data['Category']);

            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            Category::firstOrCreate(['name' => $name]);
        }
        fclose($handle);

        $this->command->info('Categories seeded: ' . count($seen));
    }
}
