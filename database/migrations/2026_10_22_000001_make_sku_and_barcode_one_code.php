<?php

use App\Models\AuditTrail;
use App\Models\SalesHistory;
use App\Services\AlertService;
use App\Services\SalesForecastService;
use App\Support\ForecastCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One code per product: the SKU IS the barcode (2026-10-22, at the user's
 * request). The product forms now have one "SKU / Barcode" field and save it to
 * both columns; this brings the rows already saved into line.
 *
 * Where a product carries a PRINTED barcode different from its SKU (entered
 * through the Barcode field that existed for a day), the printed code wins --
 * the user's choice, so a scan of the box keeps finding it. That is a SKU
 * rename, so it re-points the tables keyed on products.sku exactly as
 * ProductController::update does (that list is copied here, as of today, so
 * this migration does not move if the controller's list later does), and each
 * rename is written to the audit trail with the old code, which is what undoing
 * it needs. A printed code that is already another product's SKU is left alone
 * rather than merged into that product.
 *
 * Every other product then gets barcode = SKU (on the seeded catalogue that is
 * already true for all but the few with none). Raw query builder throughout,
 * archived products included: a migration must not query soft-deleting models.
 * Re-runnable: a second pass finds nothing to do.
 */
return new class extends Migration
{
    private const SKU_KEYED_TABLES = [
        'sales_history',
        'demand_forecasts',
        'sales_forecasts',
        'inventory_receipts',
        'forecast_accuracy',
    ];

    public function up(): void
    {
        $renamed = 0;

        $differing = DB::table('products')
            ->whereNotNull('barcode')
            ->where('barcode', '<>', '')
            ->whereColumn('barcode', '<>', 'sku')
            ->orderBy('id')
            ->get(['id', 'name', 'sku', 'barcode']);

        foreach ($differing as $p) {
            if (mb_strlen($p->barcode) > 50 || DB::table('products')->where('sku', $p->barcode)->where('id', '<>', $p->id)->exists()) {
                // Too long for the SKU column, or already another product's SKU:
                // keep this SKU (the barcode follows it below) and say so.
                AuditTrail::log('Updated', "Printed barcode not used as SKU: {$p->name} — {$p->barcode} kept off, SKU stays {$p->sku}");

                continue;
            }

            DB::transaction(function () use ($p) {
                DB::table('products')->where('id', $p->id)->update(['sku' => $p->barcode]);

                foreach (self::SKU_KEYED_TABLES as $table) {
                    if (DB::getSchemaBuilder()->hasTable($table)) {
                        DB::table($table)->where('product_sku', $p->sku)->update(['product_sku' => $p->barcode]);
                    }
                }
            });

            AuditTrail::log('Updated', "SKU set to the printed barcode: {$p->name} — SKU {$p->sku} to {$p->barcode}");
            $renamed++;
        }

        // Everyone else: the barcode is the SKU. Skips a product whose SKU is
        // already another row's barcode (left by the skip above), which the
        // unique index on barcode would refuse.
        // (In PHP: MySQL refuses an UPDATE that reads its own table in a subquery.)
        $rows = DB::table('products')->get(['id', 'sku', 'barcode']);
        $barcodeOwner = $rows->filter(fn ($r) => $r->barcode !== null && $r->barcode !== '')
            ->pluck('id', 'barcode');

        foreach ($rows as $r) {
            if ($r->barcode === $r->sku) {
                continue;
            }
            $owner = $barcodeOwner[$r->sku] ?? null;
            if ($owner !== null && (int) $owner !== (int) $r->id) {
                continue;
            }
            DB::table('products')->where('id', $r->id)->update(['barcode' => $r->sku]);

            // Keep the map current, so a code this row just released is free
            // for a later row in the same pass (otherwise a re-run finishes it).
            if ($r->barcode !== null && $r->barcode !== '' && (int) ($barcodeOwner[$r->barcode] ?? 0) === (int) $r->id) {
                unset($barcodeOwner[$r->barcode]);
            }
            $barcodeOwner[$r->sku] = $r->id;
        }

        if ($renamed > 0) {
            // Revenue and forecasts join on sku, so every cached aggregate moved.
            SalesHistory::forgetCaches();
            Cache::forget(SalesForecastService::cacheKey());
            ForecastCache::bump();
            AlertService::forget();
        }
    }

    public function down(): void
    {
        // A rename's old code is in the audit trail; nothing is undone blindly.
    }
};
