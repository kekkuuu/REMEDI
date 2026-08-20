<?php

namespace App\Console\Commands;

use App\Services\SalesForecastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class GenerateSalesForecast extends Command
{
    /**
     * php artisan sales-forecast:generate --source=mysql
     * php artisan sales-forecast:generate --source=csv --sales-csv=database/data/Sales_Records_4Year.csv --price-csv=database/data/inventory_seeder.csv
     *
     * The --source=csv example used to point at database/seed/, a directory of
     * scratch copies that has been removed; the file it named never existed.
     */
    protected $signature = 'sales-forecast:generate
        {--source=mysql : "mysql" to read live data, "csv" to bootstrap from a sales_history-shaped CSV}
        {--sales-csv= : Path to a sales_history CSV (only used with --source=csv)}
        {--price-csv= : Path to the product master CSV, for unit-price -> revenue conversion (only used with --source=csv)}
        {--horizon=6 : Months ahead to forecast}
        {--workers=0 : Parallel worker processes for model fitting (0 = auto, all cores but one; 1 = sequential)}
        {--python=python3 : Python executable to use}';

    protected $description = 'Regenerate unit + revenue sales forecasts for every product, from actual sales history';

    public function handle(): int
    {
        $source = $this->option('source');
        $horizon = (int) $this->option('horizon');
        $python = $this->option('python');

        $scriptPath = resource_path('python/generate_sales_forecast.py');
        $outputPath = storage_path('app/forecasts/all_products_sales_forecast.csv');

        $args = [
            $python, $scriptPath,
            '--source', $source,
            '--output', $outputPath,
            '--horizon', (string) $horizon,
            '--workers', (string) (int) $this->option('workers'),
            '--env-path', base_path('.env'),
        ];

        if ($source === 'csv') {
            if (! $this->option('sales-csv')) {
                $this->error('--sales-csv is required when --source=csv');

                return self::FAILURE;
            }
            $args[] = '--sales-csv';
            $args[] = $this->option('sales-csv');

            if ($this->option('price-csv')) {
                $args[] = '--price-csv';
                $args[] = $this->option('price-csv');
            }
        }

        $this->info('Running sales forecasting engine (this can take a few minutes for large catalogs)...');

        $process = new Process($args, resource_path('python'));
        $process->setTimeout(1800);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Sales forecast generation failed. See output above.');

            return self::FAILURE;
        }

        if (! file_exists($outputPath)) {
            $this->error("Expected output file not found: {$outputPath}");

            return self::FAILURE;
        }

        $this->info('Importing sales forecasts into the database...');
        $imported = $this->importCsv($outputPath);
        $this->info("Imported {$imported} sales forecast rows.");

        // The Sales Forecasting page caches its whole aggregate (see
        // SalesForecastService); without this the page would keep serving
        // the pre-import numbers until the TTL lapsed.
        Cache::forget(SalesForecastService::CACHE_KEY);

        return self::SUCCESS;
    }

    private function importCsv(string $path): int
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $now = now();
        $batch = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($header, $row);
            $batch[] = [
                'product_sku' => $record['product_sku'],
                'forecast_date' => $record['forecast_date'],
                // Belt-and-suspenders: the Python side already bounds these, but a
                // future edge case (bad fit, corrupted price, etc.) shouldn't be able
                // to take down an entire 500-row batch upsert with a decimal overflow.
                'forecast_units' => $this->clampDecimal($record['forecast_units'], 12),
                'lower_ci_units' => $this->clampDecimal($record['lower_ci_units'], 12),
                'upper_ci_units' => $this->clampDecimal($record['upper_ci_units'], 12),
                'forecast_revenue' => $this->clampDecimal($record['forecast_revenue'], 14),
                'lower_ci_revenue' => $this->clampDecimal($record['lower_ci_revenue'], 14),
                'upper_ci_revenue' => $this->clampDecimal($record['upper_ci_revenue'], 14),
                'method' => $record['method'],
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $total++;

            if (count($batch) >= 500) {
                $this->upsertBatch($batch);
                $batch = [];
            }
        }
        if (! empty($batch)) {
            $this->upsertBatch($batch);
        }
        fclose($handle);

        // Drop rows this run did not refresh.
        //
        // The upsert is keyed on (product_sku, forecast_date), so it only ever
        // overwrites dates the new run also produced. When the forecast window
        // moves -- which it does every time the history grows -- the old window's
        // rows are simply left behind. They then SHADOW the fresh ones:
        // DemandForecastService picks the first row from the current month
        // onward, and a stale row for this month sorts before next month's real
        // forecast. Deleting by generated_at keeps the table to exactly what the
        // latest run produced.
        $removed = DB::table('sales_forecasts')->where('generated_at', '<', $now)->delete();

        if ($removed > 0) {
            $this->info("Removed {$removed} forecast rows left over from earlier runs.");
        }

        return $total;
    }

    /**
     * Clamp a numeric CSV value into the safe range for a decimal($digits, 2)
     * column, so a stray non-converged forecast row can't throw a "1264 Out
     * of range" PDOException and abort the whole batch upsert.
     */
    private function clampDecimal(mixed $value, int $digits): float
    {
        $max = (10 ** ($digits - 2)) - 0.01;
        $float = is_numeric($value) ? (float) $value : 0.0;

        if (! is_finite($float)) {
            return 0.0;
        }

        return max(-$max, min($max, $float));
    }

    private function upsertBatch(array $batch): void
    {
        DB::table('sales_forecasts')->upsert(
            $batch,
            ['product_sku', 'forecast_date'],
            ['forecast_units', 'lower_ci_units', 'upper_ci_units', 'forecast_revenue', 'lower_ci_revenue', 'upper_ci_revenue', 'method', 'generated_at', 'updated_at']
        );
    }
}
