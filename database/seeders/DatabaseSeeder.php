<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Default Admin account (firstOrCreate = safe to re-run, won't duplicate)
        User::firstOrCreate(
            ['email' => 'admin@remedi.com'],
            [
                'name'      => 'Admin',
                'password'  => bcrypt('password'),
                'role'      => 'admin',
                'is_active' => true,
            ]
        );
        // Default Staff account
        User::firstOrCreate(
            ['email' => 'staff@remedi.com'],
            [
                'name'      => 'Staff One',
                'password'  => bcrypt('password'),
                'role'      => 'staff',
                'is_active' => true,
            ]
        );
        $this->call([
            CategorySeeder::class,          // must run first (products.category_id depends on it)
            ProductSeeder::class,           // must run second (product_batches.product_id depends on it)
            ProductBatchSeeder::class,      // must run third
            InventoryReceiptSeeder::class,  // feeds the SARIMA forecast pipeline (php artisan forecast:generate)
            SalesHistorySeeder::class,      // must run last
        ]);
    }
}
