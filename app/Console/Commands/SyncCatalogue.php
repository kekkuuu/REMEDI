<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SalesHistory;
use App\Services\AlertService;
use App\Services\SalesForecastService;
use App\Support\ForecastCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bring this database's CATALOGUE in line with a JSON file exported from
 * another copy (2026-09-30: the local database, after the 2022-2026
 * transaction file replaced the record, synced to the live one).
 *
 * The file names what to change, by SKU and batch number -- never by id, so
 * it cannot hit the wrong row on a database whose ids drifted:
 *
 *   archive_products  [sku...]                  archived with their batches
 *   restore_products  [sku...]                  restored with the batches archived alongside
 *   archive_batches   [{sku, batch_number}...]  e.g. duplicate opening stock
 *   prices            {sku: selling_price}
 *   costs             {sku: cost_price}
 *
 * What it deliberately does NOT touch: stock quantities (this database's own
 * sales moved them), users, sales, the audit trail, stock movements. Sales
 * history is a separate step (sales-history:import).
 *
 * Dry run unless --write. Rows already in the wanted state are skipped, so a
 * second run changes nothing.
 */
class SyncCatalogue extends Command
{
    protected $signature = 'catalogue:sync
        {--file= : Path to the JSON exported from the source database}
        {--write : Apply the changes (otherwise a dry run)}';

    protected $description = 'Align products (archived state, prices, costs) and batches with an exported catalogue';

    public function handle(): int
    {
        $path = (string) $this->option('file');
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
            $this->error('Pass --file=<readable JSON export>.');

            return self::FAILURE;
        }

        $products = Product::withTrashed()->get()->keyBy('sku');

        $toArchive = collect($data['archive_products'] ?? [])->map(fn ($s) => $products->get($s))
            ->filter(fn ($p) => $p && ! $p->trashed())->values();
        $toRestore = collect($data['restore_products'] ?? [])->map(fn ($s) => $products->get($s))
            ->filter(fn ($p) => $p && $p->trashed())->values();

        $batchesToArchive = collect($data['archive_batches'] ?? [])->map(function ($b) use ($products) {
            $p = $products->get($b['sku'] ?? '');

            return $p ? ProductBatch::where('product_id', $p->id)->where('batch_number', $b['batch_number'] ?? '')->first() : null;
        })->filter()->values();

        $prices = collect($data['prices'] ?? [])->filter(function ($price, $sku) use ($products) {
            $p = $products->get($sku);

            return $p && abs((float) $p->selling_price - (float) $price) >= 0.005;
        });
        $costs = collect($data['costs'] ?? [])->filter(function ($cost, $sku) use ($products) {
            $p = $products->get($sku);

            return $p && ($p->cost_price === null || abs((float) $p->cost_price - (float) $cost) >= 0.005);
        });

        $missing = collect(array_merge($data['archive_products'] ?? [], $data['restore_products'] ?? [], array_keys($data['prices'] ?? []), array_keys($data['costs'] ?? [])))
            ->unique()->reject(fn ($s) => $products->has($s));

        $this->table(['Change', 'Rows'], [
            ['Archive products (with their batches)', $toArchive->count()],
            ['Restore products (with their batches)', $toRestore->count()],
            ['Archive single batches', $batchesToArchive->count()],
            ['Selling prices', $prices->count()],
            ['Cost prices', $costs->count()],
            ['SKUs in the file not in this database (skipped)', $missing->count()],
        ]);

        if (! $this->option('write')) {
            $this->info('Dry run. Nothing written. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        $now = now();

        DB::transaction(function () use ($toArchive, $toRestore, $batchesToArchive, $prices, $costs, $products, $now) {
            foreach ($toArchive as $p) {
                // Same stamp on product and batches, which is what restore
                // uses to hand back exactly the batches archived with it.
                ProductBatch::where('product_id', $p->id)->update(['archived_at' => $now]);
                Product::whereKey($p->id)->update(['archived_at' => $now]);
            }

            foreach ($toRestore as $p) {
                ProductBatch::onlyTrashed()->where('product_id', $p->id)->where('archived_at', $p->archived_at)->update(['archived_at' => null]);
                Product::withTrashed()->whereKey($p->id)->update(['archived_at' => null]);
            }

            foreach ($batchesToArchive as $b) {
                ProductBatch::whereKey($b->id)->update(['archived_at' => $now]);
            }

            foreach ($prices as $sku => $price) {
                DB::table('products')->where('id', $products[$sku]->id)->update(['selling_price' => round((float) $price, 2), 'updated_at' => $now]);
            }

            foreach ($costs as $sku => $cost) {
                DB::table('products')->where('id', $products[$sku]->id)->update(['cost_price' => round((float) $cost, 2), 'updated_at' => $now]);
            }
        });

        // Bulk updates fire no model events, so do what the hooks would have:
        // a price rewrites every revenue figure, and archiving moves alerts.
        SalesHistory::forgetCaches();
        Cache::forget(SalesForecastService::cacheKey());
        ForecastCache::bump();
        AlertService::forget();
        Cache::memo()->forget('sidebar_categories');

        AuditTrail::log('Updated', sprintf(
            'Catalogue synced from %s: %d products archived, %d restored, %d batches archived, %d prices and %d costs set',
            basename((string) $this->option('file')), $toArchive->count(), $toRestore->count(), $batchesToArchive->count(), $prices->count(), $costs->count()
        ));

        $this->info('Applied. Active products now '.Product::count().', active batches '.ProductBatch::count().'.');

        return self::SUCCESS;
    }
}
