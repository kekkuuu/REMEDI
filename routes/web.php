<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BackupController;
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
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SuggestController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// NOTE: do not run `php artisan route:cache` on this app. Caching the route
// collection drops GET from this route's method list -- `/` then answers 405
// with `allow: HEAD, POST, PUT, PATCH, DELETE, OPTIONS` -- which locks every
// user out at the front door. config:cache and view:cache are both fine.
//
// `/` used to just redirect straight to `/login`; it now serves its own
// splash/landing/login page (resources/views/welcome.blade.php), whose
// embedded login form posts to the SAME route('login') endpoint as before --
// nothing about the actual auth flow changed. `guest` middleware (the same
// alias routes/auth.php already uses on GET/POST login) keeps the behaviour
// identical for a signed-in user: they're bounced to RouteServiceProvider::
// HOME rather than shown the marketing page again.
Route::middleware('guest')->get('/', function () {
    return view('welcome');
});

// `active` rides with `auth` on the whole group, not just the role-gated part.
// Deactivating an account has to end its live session on the next request, and
// most of what staff touch -- the POS included -- never passes through `role`.
// `must_change_password` rides along too -- an account on its admin-reset
// default password ("Forgot your password?", see UserController::
// resetPassword()) is funnelled to /profile, which sits in this same group,
// until it sets its own.
Route::middleware(['auth', 'active', 'must_change_password'])->group(function () {

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

    // The full list behind the bell's "View all notifications" link. Shared,
    // like /alerts: it shows staff only the inventory alerts they can already
    // reach, and the audit-derived rows only to admins.
    Route::get('/notifications', [AlertController::class, 'page'])->name('notifications.index');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

    // Void -- SHARED, not role:admin, as of 2026-09-22: staff may void a
    // sale THEY rang up (Sale::isVisibleTo() -- checked inside void() itself,
    // not just on the page that links here, per "a gated button is not a
    // gated endpoint") given the correct manager passcode (Setting::
    // checkVoidPasscode(), set by an admin at /settings). An admin needs
    // neither the scope check nor the passcode -- see SaleController::void().
    Route::patch('/sales/{sale}/void', [SaleController::class, 'void'])->name('sales.void');

    // ===== ADMIN-ONLY routes =====
    Route::middleware('role:admin')->group(function () {

        // Safeguard (was "Settings" until 2026-09-24) -- store-wide config,
        // currently just the POS void passcode above. BOTH routes sit behind
        // `password.confirm`: an admin re-types their LOGIN password before
        // this page opens, so a signed-in admin screen left unattended at the
        // counter is not enough to change the code that authorises voids.
        // The PUT carries it too -- a gated page is not a gated endpoint.
        // Confirmation lasts `auth.password_timeout` (3 hours) per session.
        Route::middleware('password.confirm')->group(function () {
            Route::get('/safeguard', [SettingsController::class, 'edit'])->name('safeguard.edit');
            Route::put('/safeguard/void-passcode', [SettingsController::class, 'updateVoidPasscode'])->name('safeguard.void-passcode.update');
        });
        // The old address keeps working for a bookmark.
        Route::redirect('/settings', '/safeguard');

        // User management (+ register acts as "Add User" form)
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store']);

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');
        // The admin side of "Forgot your password?" -- see routes/auth.php's
        // guest group and UserController::resetPassword().
        Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        // DELETE archives -- nothing is removed (see UserController::destroy).
        // The verb and route names are kept; what they DO is what changed.
        // Restore routes opt back in to trashed records with ->withTrashed(),
        // since route-model binding hides archived rows by default.
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed()->name('users.restore');

        // Products & batches
        // except('show'): there is no product DETAIL page -- the edit screen is
        // where stock, batches and the return actions live -- but the resource
        // route registered products.show anyway, so /products/{id} reached a
        // method that does not exist and answered 500 (BadMethodCallException)
        // instead of 404. Nothing links there; a stale bookmark or a typed URL
        // was enough. Every other verb is implemented.
        Route::resource('products', ProductController::class)->except(['show']);
        Route::post('/products/{product}/batches', [ProductController::class, 'addBatch'])->name('products.batches.store');
        Route::put('/batches/{batch}', [ProductController::class, 'updateBatch'])->name('batches.update');
        Route::patch('/products/{product}/restore', [ProductController::class, 'restore'])->withTrashed()->name('products.restore');
        Route::delete('/batches/{batch}', [ProductController::class, 'destroyBatch'])->name('batches.destroy');
        Route::patch('/batches/{batch}/restore', [ProductController::class, 'restoreBatch'])->withTrashed()->name('batches.restore');
        Route::patch('/batches/{batch}/return', [ProductController::class, 'markBatchReturned'])->name('batches.return');
        // The stock card: chronological stock_movements for one product, so a
        // discrepancy can be read off directly instead of pieced together from
        // batches + sale_items + the audit trail. See StockMovement.
        Route::get('/products/{product}/stock-card', [ProductController::class, 'stockCard'])->name('products.stock-card');

        // Categories
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::patch('/categories/{category}/restore', [CategoryController::class, 'restore'])->withTrashed()->name('categories.restore');

        // Reports
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
        Route::get('/reports/sales/export', [ReportController::class, 'exportSales'])->name('reports.sales.export');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
        Route::get('/reports/inventory/export', [ReportController::class, 'exportInventory'])->name('reports.inventory.export');
        Route::get('/reports/analytics', [ReportController::class, 'analytics'])->name('reports.analytics');
        Route::get('/reports/analytics/export', [ReportController::class, 'exportAnalytics'])->name('reports.analytics.export');

        // Forecasting
        Route::get('/forecast', [DemandForecastController::class, 'index'])->name('forecast.index');
        Route::get('/forecast/{product}', [DemandForecastController::class, 'show'])->name('forecast.show');
        Route::get('/sales-forecast', [SalesForecastController::class, 'index'])->name('sales-forecast.index');

        // Audit Trail
        Route::get('/audit', [AuditTrailController::class, 'index'])->name('audit.index');
        Route::get('/suggest/users', [SuggestController::class, 'users'])->name('suggest.users');
        Route::get('/suggest/audit', [SuggestController::class, 'audit'])->name('suggest.audit');
        Route::get('/audit/export', [AuditTrailController::class, 'export'])->name('audit.export');
        Route::get('/admin/backup', [BackupController::class, 'download'])->name('admin.backup');
    });
});

require __DIR__.'/auth.php';
