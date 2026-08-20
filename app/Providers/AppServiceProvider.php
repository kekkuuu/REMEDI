<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\AlertService;
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

        // The sidebar (layouts.app, rendered on every authenticated page)
        // shows a category sub-link under "Inventory". Categories rarely
        // change, so cache the list instead of running this query on
        // literally every single page load in the app. CategoryController
        // clears this cache whenever a category is created, renamed, or
        // deleted, so the sidebar never shows stale data for long.
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
        });
    }
}
