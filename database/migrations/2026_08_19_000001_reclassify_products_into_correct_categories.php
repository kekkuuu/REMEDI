<?php

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-files the seeded catalogue into the category each product's name says it
 * belongs in, and adds the three categories a pharmacy needs that the supplier
 * master never had (Medical Supplies & Devices, Baby Care, Household).
 *
 * The master file's Category column behaves like a substring match over the
 * product name, so it filed 170 deodorants and shampoos under Medicine /
 * Pharmaceutical, ice cream ("CREAM CUPS") under Personal Care, condoms
 * ("TRUST CONDOM CHOCOLATE") under Snacks, and hair wax ("GATSBY WAX
 * WATERGLOSS") under Water & Beverages -- while leaving contraceptive pills,
 * corticosteroid creams, IV fluids and salbutamol inhalers in General
 * Merchandise.
 *
 * That is not just untidy. Product::$is_medicine keys off the category name
 * and drives the 90-120 day supplier return window, so every one of those
 * misfiled toiletries was being held to a drug's return schedule and every
 * misfiled drug was not.
 *
 * The rules live in App\Services\ProductClassifier, which
 * Database\Seeders\ProductSeeder and the import:receiving-reports command both
 * call, so a fresh `migrate --seed` or a reimport off the original CSV lands
 * in the right categories without needing this migration to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categoryIds = Category::pluck('id', 'name');

        foreach (ProductClassifier::NEW_CATEGORIES as $name) {
            if (! $categoryIds->has($name)) {
                $categoryIds[$name] = Category::create(['name' => $name])->id;
            }
        }

        // Group by destination so this is one UPDATE per category rather than
        // ~1,100 single-row writes.
        $byTarget = [];

        Product::with('category')
            ->get(['id', 'name', 'category_id'])
            ->each(function ($product) use (&$byTarget) {
                $target = ProductClassifier::classify($product->name);

                // NULL means no rule was confident: leave the product alone
                // rather than guessing it into General Merchandise.
                if ($target === null || $product->category?->name === $target) {
                    return;
                }

                $byTarget[$target][] = $product->id;
            });

        DB::transaction(function () use ($byTarget, $categoryIds) {
            foreach ($byTarget as $target => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('products')
                        ->whereIn('id', $chunk)
                        ->update(['category_id' => $categoryIds[$target]]);
                }
            }
        });

        // The sidebar's category list is cached for 6 hours (AppServiceProvider);
        // without this the three new categories do not appear until it expires.
        Cache::forget('sidebar_categories');
    }

    public function down(): void
    {
        // Irreversible: the products' original categories were the supplier
        // file's, and nothing here records which product came from which. A
        // rollback would have to re-derive them, and the whole point of this
        // migration is that those values were wrong.
    }
};
