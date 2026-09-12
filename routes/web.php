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
use App\Http\Controllers\SuggestController;
use App\Http\Controllers\UserController;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
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
Route::middleware(['auth', 'active'])->group(function () {

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
        // except('show'): there is no product DETAIL page -- the edit screen is
        // where stock, batches and the return actions live -- but the resource
        // route registered products.show anyway, so /products/{id} reached a
        // method that does not exist and answered 500 (BadMethodCallException)
        // instead of 404. Nothing links there; a stale bookmark or a typed URL
        // was enough. Every other verb is implemented.
        Route::resource('products', ProductController::class)->except(['show']);
        Route::post('/products/{product}/batches', [ProductController::class, 'addBatch'])->name('products.batches.store');
        Route::put('/batches/{batch}', [ProductController::class, 'updateBatch'])->name('batches.update');
        Route::delete('/batches/{batch}', [ProductController::class, 'destroyBatch'])->name('batches.destroy');
        Route::patch('/batches/{batch}/return', [ProductController::class, 'markBatchReturned'])->name('batches.return');

        // TEMPORARY read-only diagnostic, second pass: the first pass found
        // 2,637 of ~2,638 products carrying a duplicated OPENING-<sku> batch.
        // This one pulls the shape of each duplicate pair (quantities, return
        // state, whether either row is referenced by a real sale) so a safe
        // cleanup rule can be picked -- sale_items.product_batch_id is
        // ON DELETE RESTRICT, so a row any sale drew from cannot simply be
        // deleted. Remove after use.
        Route::get('/diagnostics/duplicate-batches-detail', function () {
            $dupeGroups = ProductBatch::select('product_id', 'batch_number')
                ->groupBy('product_id', 'batch_number')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            $rows = ProductBatch::query()
                ->whereIn('product_id', $dupeGroups->pluck('product_id')->unique())
                ->get(['id', 'product_id', 'batch_number', 'quantity', 'qty_received', 'received_date', 'expiry_date', 'returned_at', 'dr_no']);

            $batchIdsWithSales = DB::table('sale_items')
                ->select('product_batch_id')
                ->distinct()
                ->pluck('product_batch_id')
                ->flip();

            $groups = $rows->groupBy(fn ($r) => $r->product_id.'|'.$r->batch_number)
                ->filter(fn ($g) => $g->count() > 1);

            $summary = [
                'groups' => $groups->count(),
                'group_sizes' => $groups->map->count()->countBy()->all(),
                'identical_quantity' => 0,
                'different_quantity' => 0,
                'any_row_has_sales' => 0,
                'all_rows_have_sales' => 0,
                'no_rows_have_sales' => 0,
                'any_row_returned' => 0,
                'all_rows_returned' => 0,
                'no_rows_returned' => 0,
                'identical_received_date' => 0,
                'identical_dr_no' => 0,
            ];

            $samples = [];
            $returnedGroups = [];
            $receivedDates = [];
            // Confirms (or disproves) the candidate cleanup rule: within each
            // pair, does the OLDER-received row ever carry the sales/return
            // history while the NEWER one is the untouched duplicate? If that
            // ever flips, "always delete the newer row" is not safe.
            $olderHasSalesOrReturn = 0;
            $newerHasSalesOrReturn = 0;
            $bothOrNeitherHaveSalesOrReturn = 0;

            foreach ($groups as $key => $g) {
                $quantities = $g->pluck('quantity')->unique();
                $summary[$quantities->count() === 1 ? 'identical_quantity' : 'different_quantity']++;

                $salesFlags = $g->map(fn ($r) => $batchIdsWithSales->has($r->id));
                $salesCount = $salesFlags->filter()->count();
                if ($salesCount > 0) {
                    $summary['any_row_has_sales']++;
                }
                if ($salesCount === $g->count()) {
                    $summary['all_rows_have_sales']++;
                }
                if ($salesCount === 0) {
                    $summary['no_rows_have_sales']++;
                }

                $returnedCount = $g->filter(fn ($r) => $r->returned_at)->count();
                if ($returnedCount > 0) {
                    $summary['any_row_returned']++;
                    $returnedGroups[] = [
                        'product_id' => $g->first()->product_id,
                        'batch_number' => $g->first()->batch_number,
                        'rows' => $g->sortBy('received_date')->map(fn ($r) => [
                            'id' => $r->id,
                            'quantity' => $r->quantity,
                            'received_date' => (string) $r->received_date,
                            'returned_at' => $r->returned_at ? (string) $r->returned_at : null,
                            'has_sales' => $batchIdsWithSales->has($r->id),
                        ])->values(),
                    ];
                }
                if ($returnedCount === $g->count()) {
                    $summary['all_rows_returned']++;
                }
                if ($returnedCount === 0) {
                    $summary['no_rows_returned']++;
                }

                foreach ($g->pluck('received_date')->map(fn ($d) => (string) $d)->unique() as $d) {
                    $receivedDates[$d] = ($receivedDates[$d] ?? 0) + 1;
                }
                if ($g->pluck('received_date')->map(fn ($d) => (string) $d)->unique()->count() === 1) {
                    $summary['identical_received_date']++;
                }
                if ($g->pluck('dr_no')->unique()->count() === 1) {
                    $summary['identical_dr_no']++;
                }

                $sorted = $g->sortBy('received_date')->values();
                $older = $sorted->first();
                $newer = $sorted->last();
                $olderFlag = $batchIdsWithSales->has($older->id) || $older->returned_at;
                $newerFlag = $batchIdsWithSales->has($newer->id) || $newer->returned_at;
                if ($olderFlag && ! $newerFlag) {
                    $olderHasSalesOrReturn++;
                } elseif ($newerFlag && ! $olderFlag) {
                    $newerHasSalesOrReturn++;
                } else {
                    $bothOrNeitherHaveSalesOrReturn++;
                }

                if (count($samples) < 10) {
                    $samples[] = [
                        'product_id' => $g->first()->product_id,
                        'batch_number' => $g->first()->batch_number,
                        'rows' => $g->map(fn ($r) => [
                            'id' => $r->id,
                            'quantity' => $r->quantity,
                            'qty_received' => $r->qty_received,
                            'received_date' => (string) $r->received_date,
                            'expiry_date' => (string) $r->expiry_date,
                            'returned_at' => $r->returned_at ? (string) $r->returned_at : null,
                            'dr_no' => $r->dr_no,
                            'has_sales' => $batchIdsWithSales->has($r->id),
                        ])->values(),
                    ];
                }
            }

            $summary['received_date_breakdown'] = $receivedDates;
            $summary['older_row_has_sales_or_return_only'] = $olderHasSalesOrReturn;
            $summary['newer_row_has_sales_or_return_only'] = $newerHasSalesOrReturn;
            $summary['both_or_neither_have_sales_or_return'] = $bothOrNeitherHaveSalesOrReturn;

            return response()->json(['summary' => $summary, 'returned_groups' => $returnedGroups, 'samples' => $samples]);
        });

        // Categories
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

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
