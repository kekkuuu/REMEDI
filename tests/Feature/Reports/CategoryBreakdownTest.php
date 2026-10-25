<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\DashboardController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboards' "Inventory Overview" ring is captioned "Total products" and
 * each row links to Inventory for its category, which lists every active
 * product. It counted only products holding stock (2,530 of 2,637 on
 * 2026-10-25). Called through the snapshot directly: the dashboard page
 * itself runs MySQL-only aggregates this suite cannot.
 */
class CategoryBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function product(Category $category, string $name, int $qty): Product
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.substr(md5($name), 0, 10),
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => '10.00',
            'reorder_level' => 1,
        ]);

        if ($qty > 0) {
            ProductBatch::create([
                'batch_number' => 'B-'.$product->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'qty_received' => $qty,
                'expiry_date' => now()->addYear()->toDateString(),
                'received_date' => now()->subDay()->toDateString(),
            ]);
        }

        return $product;
    }

    public function test_every_product_counts_including_the_out_of_stock_ones(): void
    {
        $care = Category::create(['name' => 'Personal Care']);
        $this->product($care, 'Soap', 12);
        $this->product($care, 'Shampoo', 0);
        $this->product($care, 'Lotion', 3);

        $snapshot = (new \ReflectionMethod(DashboardController::class, 'computeStockSnapshot'))->invoke(null);
        $row = collect($snapshot['categoryBreakdown'])->firstWhere('name', 'Personal Care');

        $this->assertSame(3, $row['products'], 'the empty Shampoo is still a product in the category');
        $this->assertSame(15, $row['stock'], 'units are what is on the shelf');
        $this->assertSame($snapshot['scalars']['totalProducts'], collect($snapshot['categoryBreakdown'])->sum('products'));
    }
}
