<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SalesHistory;
use App\Services\AlertService;
use App\Services\SalesForecastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Replace `sales_history` from a line-level transaction export.
 *
 * The export is one row per LINE (transaction, product, quantity, price,
 * discount, payment type); `sales_history` is one row per product per DAY,
 * units only -- revenue is always derived as
 * `quantity_sold * products.selling_price` (see CLAUDE.md, "Two sales
 * tables"). So the import AGGREGATES: the prices, discounts, customers and
 * payment types in the file are deliberately not stored, because nothing
 * downstream reads them and widening the table would mean re-deriving every
 * revenue aggregate in the app.
 *
 * Products are matched on NAME, not on the file's own Product_ID -- that id
 * (`PRD-00689`) is a different namespace from `products.sku`, which is a
 * barcode. Verified before writing this: all 2,603 names in the export match
 * the catalogue exactly.
 *
 * THE OLD ROWS ARE COPIED, NOT DROPPED. `--write` first archives every
 * existing row into `sales_history_archive` (created on demand), so the
 * four-year record this replaces is still in the database afterwards and can
 * be restored with a single INSERT ... SELECT. That is the whole reason this
 * command exists rather than a truncate and a seeder run.
 *
 * Dry run unless `--write` is passed, same as every other destructive command
 * here (pos:backfill, products:classify).
 *
 * TWO FORMATS, detected from the header (2026-09-27):
 *  - the line-level export (Product_Name / Transaction_Date DD/MM/YYYY /
 *    Quantity), matched on NAME -- its Product_ID is a different namespace;
 *  - a DAILY file (product_sku / sale_date Y-m-d / quantity_sold, plus
 *    product_name), matched on SKU first and name only as a fallback. That is
 *    database/data/sales_history_daily_red_pharmacy_2022-2026.csv, built from the 56
 *    "Sales by Product" monthly files (2022-01-01 .. 2026-08-15), whose
 *    barcodes all match products.sku exactly.
 */
class ImportSalesHistoryCsv extends Command
{
    protected $signature = 'sales-history:import
        {--file= : Path to the transaction CSV}
        {--write : Actually replace sales_history (otherwise this is a dry run)}';

    protected $description = 'Replace sales_history from a line-level transaction CSV, archiving the existing rows first';

    public function handle(): int
    {
        $path = (string) $this->option('file');

        if ($path === '' || ! is_readable($path)) {
            $this->error('Pass a readable --file=<path to csv>.');

            return self::FAILURE;
        }

        // name (upper, trimmed) => sku. Built once; the file has ~115k rows
        // and a query per row would dominate the runtime.
        $skuByName = [];
        foreach (Product::withTrashed()->get(['name', 'sku']) as $p) {
            $skuByName[mb_strtoupper(trim((string) $p->name))] = $p->sku;
        }
        $this->info('Catalogue loaded: '.count($skuByName).' products.');

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            $this->error('The file is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        // Strip a UTF-8 BOM off the first header cell, or the first column
        // name never matches and every row looks malformed.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $idx = array_flip(array_map('trim', $header));

        // The daily file names its columns differently and is already one row
        // per (product, day); map it onto the same three roles.
        $daily = isset($idx['product_sku'], $idx['sale_date'], $idx['quantity_sold']);

        if ($daily) {
            $idx['Product_Name'] = $idx['product_name'] ?? $idx['product_sku'];
            $idx['Transaction_Date'] = $idx['sale_date'];
            $idx['Quantity'] = $idx['quantity_sold'];
            $skus = array_flip(array_values($skuByName));
            $this->info('Daily format: matching on product_sku, name as fallback.');
        }

        foreach (['Product_Name', 'Transaction_Date', 'Quantity'] as $needed) {
            if (! isset($idx[$needed])) {
                $this->error("The file has no `{$needed}` column.");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $totals = [];          // "sku|Y-m-d" => summed quantity
        $rows = 0;
        $unmatched = [];
        $badDates = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || count($row) < count($header)) {
                continue;
            }

            $rows++;
            $name = mb_strtoupper(trim((string) ($row[$idx['Product_Name']] ?? '')));
            $sku = null;

            if ($daily) {
                $candidate = trim((string) ($row[$idx['product_sku']] ?? ''));
                $sku = isset($skus[$candidate]) ? $candidate : null;
            }

            $sku ??= $skuByName[$name] ?? null;

            if ($sku === null) {
                $unmatched[$name] = ($unmatched[$name] ?? 0) + 1;

                continue;
            }

            $date = $daily
                ? $this->parseIsoDate((string) ($row[$idx['Transaction_Date']] ?? ''))
                : $this->parseDate((string) ($row[$idx['Transaction_Date']] ?? ''));

            if ($date === null) {
                $badDates++;

                continue;
            }

            $qty = (int) round((float) ($row[$idx['Quantity']] ?? 0));

            if ($qty <= 0) {
                continue;
            }

            $key = $sku.'|'.$date;
            $totals[$key] = ($totals[$key] ?? 0) + $qty;
        }

        fclose($handle);

        $dates = array_map(fn ($k) => explode('|', $k)[1], array_keys($totals));
        sort($dates);

        $this->newLine();
        $this->line('Read           : '.number_format($rows).' line rows');
        $this->line('Aggregated to  : '.number_format(count($totals)).' (product, day) rows');
        $this->line('Units          : '.number_format(array_sum($totals)));
        $this->line('Date range     : '.($dates[0] ?? '-').' .. '.(end($dates) ?: '-'));
        $this->line('Unmatched names: '.count($unmatched));
        $this->line('Unparseable dates: '.$badDates);

        foreach (array_slice($unmatched, 0, 10, true) as $name => $n) {
            $this->warn("  unmatched: {$name} ({$n} rows)");
        }

        $existing = DB::table('sales_history')->count();
        $this->line('Existing rows  : '.number_format($existing).' (these get archived, not dropped)');

        if (! $this->option('write')) {
            $this->newLine();
            $this->info('Dry run. Nothing written. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        if ($totals === []) {
            $this->error('Nothing to import -- refusing to wipe sales_history for an empty result.');

            return self::FAILURE;
        }

        // 1. Archive. Done OUTSIDE the transaction below and checked, because
        //    losing a record is the one thing this must not do.
        //
        //    A NEW, dated table every run -- never truncate an existing one.
        //    The first version reused `sales_history_archive` and TRUNCATED it,
        //    so a second import would have destroyed the record the first one
        //    saved (the generated four-year history the user asked to keep).
        //    Restore any of them with
        //    INSERT INTO sales_history SELECT * FROM <archive table>.
        $archive = 'sales_history_archive_'.now()->format('Ymd_His');
        DB::statement("CREATE TABLE `{$archive}` LIKE sales_history");
        DB::statement("INSERT INTO `{$archive}` SELECT * FROM sales_history");
        $archived = DB::table($archive)->count();

        if ($archived !== $existing) {
            $this->error("Archive mismatch: {$archived} archived vs {$existing} existing. Aborting before any delete.");

            return self::FAILURE;
        }

        $this->info('Archived '.number_format($archived)." rows to {$archive}.");

        // 2. Replace.
        $now = now();
        DB::transaction(function () use ($totals, $now) {
            DB::table('sales_history')->delete();

            $batch = [];
            foreach ($totals as $key => $qty) {
                [$sku, $date] = explode('|', $key);
                $batch[] = [
                    'product_sku' => $sku,
                    'sale_date' => $date,
                    'quantity_sold' => $qty,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 2000) {
                    DB::table('sales_history')->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('sales_history')->insert($batch);
            }
        });

        $this->info('Imported '.number_format(DB::table('sales_history')->count()).' rows.');

        // 3. Every revenue aggregate in the app is cached and keyed on this
        //    stamp; without the bump the dashboard and both reports keep
        //    serving figures from the record that was just replaced.
        //    forgetCaches(), not bumpCacheVersion(): the latter only retires
        //    the POS-dependent keys, so the history-only aggregates -- the
        //    expensive ones -- kept describing the replaced record for up to
        //    their 24 h TTL (found 2026-09-30, before syncing the live DB).
        SalesHistory::forgetCaches();
        Cache::forget(SalesForecastService::cacheKey());
        AlertService::forget();
        $this->info('Cache versions bumped.');

        $this->newLine();
        $this->warn('Forecasts still describe the OLD record -- re-run forecast:generate and sales-forecast:generate.');

        return self::SUCCESS;
    }

    /** The daily file's Y-m-d, strictly -- a date that does not round-trip is refused. */
    private function parseIsoDate(string $raw): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', trim($raw));

        return $d && $d->format('Y-m-d') === trim($raw) ? $d->format('Y-m-d') : null;
    }

    /**
     * The export writes DD/MM/YYYY (verified: it opens 01/07/2022 and the
     * file is labelled July 2022 onward). Guessing wrong here silently swaps
     * day and month for every date below the 13th, which would look like real
     * data rather than an error, so the format is pinned rather than sniffed.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $raw);

            if ($d !== false) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
