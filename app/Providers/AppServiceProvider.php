<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SalesHistory;
use App\Services\AlertService;
use App\Services\SalesForecastService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Both packages default to writing under storage_path() -- maatwebsite/
        // excel's XLSX writer spools to storage/framework/cache/laravel-excel
        // before streaming the download, and dompdf's font_cache defaults to
        // storage/fonts. That's the exact class of problem vercel.json already
        // works around for the framework's own caches (config/events/routes,
        // compiled views) by redirecting them to /tmp -- server.php's own
        // comment explains why: only /tmp is writable on that runtime. Neither
        // export package's config is published in this app, so there's nothing
        // for vercel.json's env block to point at; overriding here instead of
        // publishing ~350 lines of vendor config just to change one key each.
        // sys_get_temp_dir() is correct everywhere this app runs (XAMPP, the
        // Railway container, and Vercel, where it resolves to /tmp) and, unlike
        // a storage_path() subdirectory, is guaranteed to already exist.
        config([
            'excel.temporary_files.local_path' => sys_get_temp_dir(),
            'dompdf.font_cache' => sys_get_temp_dir(),
        ]);

        Paginator::defaultView('vendor.pagination.custom');
        Paginator::defaultSimpleView('vendor.pagination.custom');

        // Any batch or product mutation can open or close an alert, so drop the
        // bell's cached payload here rather than sprinkling forget() through
        // seven controller actions. saved covers create and update.
        //
        // This does NOT cover POS checkout: it deducts stock with decrement(),
        // which bypasses model events by design, so PosController::checkout
        // calls AlertService::forget() itself.
        foreach ([ProductBatch::class, Product::class] as $model) {
            $model::saved(fn () => AlertService::forget());
            $model::deleted(fn () => AlertService::forget());
        }

        // The sidebar's category list is `withCount('products')`, so a PRODUCT
        // write changes it just as much as a category write does — and only the
        // category writes were clearing it. Creating a product, deleting one, or
        // moving one between categories left the sidebar counts wrong for up to
        // the full 6-hour TTL. Verified: created a product in Household through
        // the normal form and the sidebar kept reading 7 against a real 8.
        //
        // Hooked here rather than in ProductController so it covers every write
        // path — the controller, `import:receiving-reports`, the seeders and
        // tinker — the same reasoning as the AlertService hooks above.
        foreach (['saved', 'deleted'] as $event) {
            Product::{$event}(fn () => Cache::forget('sidebar_categories'));
        }

        // Every revenue figure in the app is `sales_history.quantity_sold *
        // products.selling_price` — sales_history stores units only. So editing
        // a product's price rewrites HISTORICAL revenue everywhere, and those
        // aggregates are cached for 24h (monthlyRevenue, quarterlyRevenue, the
        // range-keyed trend/top-product keys) and 6h (the Sales Forecasting
        // trend). Nothing cleared them: only a reseed or a POS checkout did.
        //
        // Measured by doubling one product's price through the normal edit
        // form: August 2026 actually moved by ₱20,884.92 while the dashboard,
        // the reports and the forecast page all kept showing the old total.
        //
        // Deletion counts too. sales_history joins products on `sku` with no
        // foreign key, so removing a product silently drops its rows from every
        // revenue join — and the sale_items guard on destroy() does not cover a
        // product that has history but no POS line items.
        Product::saved(function (Product $product) {
            if ($product->wasChanged('selling_price')) {
                self::forgetRevenueCaches();
            }
        });

        Product::deleted(fn () => self::forgetRevenueCaches());

        // The sidebar (layouts.app, rendered on every authenticated page)
        // shows a category sub-link under "Inventory". Categories rarely
        // change, so cache the list instead of running this query on
        // literally every single page load in the app.
        //
        // Invalidated from two directions, because the payload depends on both:
        // CategoryController clears it when a category is created, renamed or
        // deleted, and the Product hook above clears it when a product is
        // created, deleted or moved — the counts are products_count.
        View::composer('layouts.app', function ($view) {
            $view->with('sidebarCategories', Cache::remember(
                'sidebar_categories',
                now()->addHours(6),
                fn () => Category::withCount('products')->orderBy('name')->get()
            ));

            // Notification bell. The count is the number of open alert KINDS
            // (low stock / expiring / expired / returns due / returns missed),
            // not a row count -- the badge is a "there are N things to look at"
            // cue, and 645 low-stock products is one thing to look at.
            //
            // Both the count and the list come from AlertService so the badge
            // and the dropdown it opens are the same data. The bell then polls
            // /alerts to keep them current without a page load, which is why
            // the TTL there is 30s rather than the 10 minutes this used to use.
            $alerts = app(AlertService::class)->payload();

            $view->with('topbarAlertCount', $alerts['count']);
            $view->with('topbarAlertItems', $alerts['items']);
            $view->with('topbarAlerts', $alerts['alerts']);

            // Audit-derived rows are admin-only; see AlertService::activity().
            $view->with('topbarActivity', auth()->user()?->isAdmin()
                ? app(AlertService::class)->activity()
                : []);
        });
    }

    /**
     * Retire everything that derives revenue from `products.selling_price`.
     *
     * SalesHistory::forgetCaches() drops the three fixed keys (monthly,
     * quarterly, recent demand) and bumps both version stamps, which retires the
     * range-keyed trend/top-product entries too. SalesForecastService keeps its
     * own separate key for the Sales Forecasting page's actual-revenue series.
     */
    private static function forgetRevenueCaches(): void
    {
        SalesHistory::forgetCaches();
        Cache::forget(SalesForecastService::CACHE_KEY);
    }
}
