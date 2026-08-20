<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemandForecastController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalesForecastController;
use App\Http\Controllers\SuggestController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// NOTE: do not run `php artisan route:cache` on this app. Caching the route
// collection drops GET from this route's method list -- `/` then answers 405
// with `allow: HEAD, POST, PUT, PATCH, DELETE, OPTIONS` -- which locks every
// user out at the front door. config:cache and view:cache are both fine.
Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('auth')->group(function () {

    // Dashboard - shared, role-aware
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ===== Shared routes (Admin AND Staff) =====
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/lookup', [PosController::class, 'lookupBySku'])->name('pos.lookup');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
    Route::get('/pos/receipt/{sale}', [PosController::class, 'receipt'])->name('pos.receipt');

    // Typeahead sources for the search boxes (see SuggestController). Shared
    // by every list page, so they live outside the role-scoped groups below;
    // each only ever returns data the signed-in user could already browse.
    Route::get('/suggest/products', [SuggestController::class, 'products'])->name('suggest.products');
    Route::get('/suggest/sales', [SuggestController::class, 'sales'])->name('suggest.sales');

    // Polled by the topbar notification bell (see layouts/app.blade.php). Shared
    // because every alert it returns links to an Inventory filter both roles
    // can already open.
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

    // ===== ADMIN-ONLY routes =====
    Route::middleware('role:admin')->group(function () {

        // User management (+ register acts as "Add User" form)
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store']);

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Products & batches
        Route::resource('products', ProductController::class);
        Route::post('/products/{product}/batches', [ProductController::class, 'addBatch'])->name('products.batches.store');
        Route::put('/batches/{batch}', [ProductController::class, 'updateBatch'])->name('batches.update');
        Route::delete('/batches/{batch}', [ProductController::class, 'destroyBatch'])->name('batches.destroy');
        Route::patch('/batches/{batch}/return', [ProductController::class, 'markBatchReturned'])->name('batches.return');

        // Categories
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
        Route::get('/reports/analytics', [ReportController::class, 'analytics'])->name('reports.analytics');

        // Forecasting
        Route::get('/forecast', [DemandForecastController::class, 'index'])->name('forecast.index');
        Route::get('/forecast/{product}', [DemandForecastController::class, 'show'])->name('forecast.show');
        Route::get('/sales-forecast', [SalesForecastController::class, 'index'])->name('sales-forecast.index');

        // Audit Trail
        Route::get('/audit', [AuditTrailController::class, 'index'])->name('audit.index');
        Route::get('/suggest/users', [SuggestController::class, 'users'])->name('suggest.users');
        Route::get('/suggest/audit', [SuggestController::class, 'audit'])->name('suggest.audit');
        Route::get('/audit/export', [AuditTrailController::class, 'export'])->name('audit.export');
    });
});

require __DIR__.'/auth.php';
