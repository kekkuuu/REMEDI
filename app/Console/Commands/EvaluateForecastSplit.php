<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class EvaluateForecastSplit extends Command
{
    /**
     * php artisan forecast:evaluate-split --python=python
     *
     * Scores the demand model on a chronological train/test split (80/20 by
     * default): each product's first 80% of months train the model, the last
     * 20% test it, in time order. The work is resources/python/
     * evaluate_train_test_split.py, which reuses generate_forecasts.py's own
     * loader and model so it evaluates exactly what forecast:generate runs.
     *
     * An evaluation only -- it prints a summary, writes a per-product CSV and
     * the overall figures to resources/data/forecast_split_80_20.json (shown
     * on the Forecasting page; commit it to publish a new run), and changes
     * nothing in the database. The live forecasts still train on
     * every month, and the Forecasting page's accuracy still comes from the
     * 3-month holdout in forecast:generate.
     */
    protected $signature = 'forecast:evaluate-split
        {--ratio=0.8 : Share of each product\'s months used for training (0.5 to 0.95)}
        {--workers=1 : Parallel worker processes (1 = sequential, 0 = all cores but one)}
        {--python=python3 : Python executable to use}';

    protected $description = 'Evaluate the demand model on a chronological 80/20 train/test split';

    public function handle(): int
    {
        $ratio = $this->option('ratio');

        if (! is_numeric($ratio) || (float) $ratio < 0.5 || (float) $ratio > 0.95) {
            $this->error('--ratio must be a number between 0.5 and 0.95 (e.g. 0.8 for 80/20).');

            return self::FAILURE;
        }

        $trainPct = (int) round((float) $ratio * 100);
        $outputPath = storage_path("app/forecasts/train_test_split_{$trainPct}_".(100 - $trainPct).'.csv');

        $args = [
            $this->option('python'), resource_path('python/evaluate_train_test_split.py'),
            '--env-path', base_path('.env'),
            '--output', $outputPath,
            // The overall figures, committed with the code so the Forecasting
            // page shows them on every host (App\Support\ForecastSplit).
            '--summary', \App\Support\ForecastSplit::path($trainPct),
            '--train-ratio', (string) (float) $ratio,
            '--workers', (string) (int) $this->option('workers'),
            ...(config('forecast.include_pos') ? ['--include-pos'] : []),
        ];

        $process = new Process($args, resource_path('python'));
        $process->setTimeout(10800); // 3 hours -- see GenerateDemandForecast
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Evaluation failed. See output above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
