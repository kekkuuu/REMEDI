<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The product master arrived with two overlapping beverage categories --
 * "Beverages" and "Water & Beverages" -- which showed up as two separate
 * sub-links under Inventory in the sidebar. They describe the same shelf,
 * so fold "Beverages" into "Water & Beverages" (the broader name, and the
 * one holding most of the stock).
 *
 * Database\Seeders\CategorySeeder and ProductSeeder normalize the same
 * alias via Category::normalizeName(), so a fresh `migrate --seed` off the
 * original CSV won't recreate the split.
 */
return new class extends Migration
{
    public function up(): void
    {
        $target = Category::where('name', Category::CANONICAL_BEVERAGES)->first();
        $source = Category::where('name', 'Beverages')->first();

        // Nothing named "Beverages" left to merge (fresh install seeded
        // through normalizeName(), or this already ran).
        if (! $source) {
            return;
        }

        // No "Water & Beverages" row to merge into: just rename in place
        // rather than dropping the products' only category.
        if (! $target) {
            $source->update(['name' => Category::CANONICAL_BEVERAGES]);
            $this->forgetSidebarCache();

            return;
        }

        DB::transaction(function () use ($source, $target) {
            DB::table('products')
                ->where('category_id', $source->id)
                ->update(['category_id' => $target->id]);

            $source->delete();
        });

        // The sidebar category list is cached for 6 hours (see
        // AppServiceProvider); drop it so the merged list shows immediately.
        $this->forgetSidebarCache();
    }

    public function down(): void
    {
        // Irreversible: once the two categories are merged there is no
        // record of which products originally sat under "Beverages", so
        // splitting them back apart would be guesswork.
    }

    /**
     * Best-effort cache-bust, tolerant of the cache STORE not existing yet.
     *
     * On CACHE_DRIVER=database (Railway; see docs/DEPLOY-RAILWAY.md) this
     * migration runs before 2026_09_02_000001_create_sessions_and_cache_tables
     * creates the `cache` table, so on a brand-new install Cache::forget()
     * throws a real QueryException -- "Base table ... 'cache' doesn't exist"
     * -- rather than silently no-opping the way it does on the local
     * CACHE_DRIVER=file, where this never surfaced. Caught rather than fixed
     * by reordering the migrations: the six-hour staleness this skips is
     * harmless and self-heals (AppServiceProvider also clears this key on
     * every Category/Product write), but two migrations disagreeing about
     * which one gets to run first is a much worse thing to introduce.
     * Verified against Railway's first deploy, where this was uncaught.
     */
    private function forgetSidebarCache(): void
    {
        try {
            Cache::forget('sidebar_categories');
        } catch (\Throwable) {
        }
    }
};
