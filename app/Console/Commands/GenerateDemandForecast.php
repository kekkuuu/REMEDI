<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class GenerateDemandForecast extends Command
{
    /**
     * php artisan forecast:generate --source=mysql
     * php artisan forecast:generate --source=csv --csv-path=/path/to.csv --xls-path=/path/to.xls
     *
     * Forecasts are per-SKU demand (units customers are expected to buy),
     * modeled from actual sales history -- not from supplier receiving/
     * restocking data, which reflects order-pattern noise rather than
     * customer demand.
     */
    protected $signature = 'forecast:generate
        {--source=mysql : "mysql" to read live sales_history data, "csv" to bootstrap from receiving-report exports}
        {--csv-path= : Path to a receiving-report CSV (only used with --source=csv)}
        {--xls-path= : Path to a receiving-report XLS (only used with --source=csv)}
        {--horizon=6 : Months ahead to forecast}
        {--workers=0 : Parallel worker processes for model fitting (0 = auto, all cores but one; 1 = sequential)}
        {--python=python3 : Python executable to use}';

    protected $description = 'Regenerate SARIMA demand forecasts for every product';

    public function handle(): int
    {
        $source = $this->option('source');
        $horizon = (int) $this->option('horizon');
        $python = $this->option('python');

        $scriptPath = resource_path('python/generate_forecasts.py');
        $outputPath = storage_path('app/forecasts/all_products_forecast.csv');

        $args = [
            $python, $scriptPath,
            '--source', $source,
            '--output', $outputPath,
            '--horizon', (string) $horizon,
            '--workers', (string) (int) $this->option('workers'),
            '--env-path', base_path('.env'),
        ];

        if ($source === 'csv') {
            if (! $this->option('csv-path') && ! $this->option('xls-path')) {
                $this->error('--csv-path and/or --xls-path required when --source=csv');

                return self::FAILURE;
            }
            if ($this->option('csv-path')) {
                $args[] = '--csv-path';
                $args[] = $this->option('csv-path');
            }
            if ($this->option('xls-path')) {
                $args[] = '--xls-path';
                $args[] = $this->option('xls-path');
            }
        }

        $this->info('Running SARIMA forecasting engine (this can take a few minutes for large catalogs)...');

        $process = new Process($args, resource_path('python'));
        $process->setTimeout(1800); // 30 min ceiling for large catalogs
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Forecast generation failed. See output above.');

            return self::FAILURE;
        }

        if (! file_exists($outputPath)) {
            $this->error("Expected output file not found: {$outputPath}");

            return self::FAILURE;
        }

        $this->info('Importing forecasts into the database...');
        $imported = $this->importCsv($outputPath);
        $this->info("Imported {$imported} forecast rows.");

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
                'forecast_value' => $record['forecast_value'],
                'lower_ci' => $record['lower_ci'],
                'upper_ci' => $record['upper_ci'],
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
        $removed = DB::table('demand_forecasts')->where('generated_at', '<', $now)->delete();

        if ($removed > 0) {
            $this->info("Removed {$removed} forecast rows left over from earlier runs.");
        }

        return $total;
    }

    private function upsertBatch(array $batch): void
    {
        DB::table('demand_forecasts')->upsert(
            $batch,
            ['product_sku', 'forecast_date'],
            ['forecast_value', 'lower_ci', 'upper_ci', 'generated_at', 'updated_at']
        );
    }
}
