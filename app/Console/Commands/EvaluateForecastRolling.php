<?php

namespace App\Console\Commands;

use App\Support\ForecastSplit;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class EvaluateForecastRolling extends Command
{
    /**
     * php artisan forecast:evaluate-rolling --python=python --workers=0
     *
     * Walks the demand model forward across the whole record (2026-10-02, at
     * the user's request): every month is forecast one month ahead from the
     * months before it only, for the whole store and for every product, then
     * compared with what actually sold. resources/python/evaluate_rolling.py
     * does the work with generate_forecasts.py's own loader and model.
     *
     * An evaluation only -- it writes resources/data/forecast_rolling.json
     * (shown on the Forecasting page; commit it to publish a run) and changes
     * nothing in the database. ~100k model fits: run it with --workers=0 on a
     * machine with the RAM for it, never on the Railway container.
     */
    protected $signature = 'forecast:evaluate-rolling
        {--workers=1 : Parallel worker processes (1 = sequential, 0 = all cores but one)}
        {--python=python3 : Python executable to use}';

    protected $description = 'Walk-forward (rolling) one-month-ahead evaluation of the demand model across the whole record';

    public function handle(): int
    {
        $args = [
            $this->option('python'), resource_path('python/evaluate_rolling.py'),
            '--env-path', base_path('.env'),
            '--output', ForecastSplit::rollingPath(),
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
