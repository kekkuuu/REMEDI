<?php

namespace App\Support;

/**
 * The overall result of the chronological train/test evaluation
 * (php artisan forecast:evaluate-split), shown on the Forecasting page.
 *
 * It lives in a JSON file committed with the code rather than in the
 * database: the evaluation is a measurement of the model, not shop data, it
 * takes a long time to run, and a file means every host (local, Vercel,
 * Railway) shows the same run without anything being written to the live
 * database. Re-run the command and commit the file to publish a new one.
 */
final class ForecastSplit
{
    public static function path(int $trainPct = 80): string
    {
        return resource_path('data/forecast_split_'.$trainPct.'_'.(100 - $trainPct).'.json');
    }

    /** forecast:evaluate-rolling's output: the walk-forward test across the whole record. */
    public static function rollingPath(): string
    {
        return resource_path('data/forecast_rolling.json');
    }

    /** The decoded walk-forward summary, or null when it has not been run. */
    public static function rolling(): ?array
    {
        $path = self::rollingPath();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) && isset($data['storewide']['mape'], $data['per_product']) ? $data : null;
    }

    /** The decoded summary, or null when the evaluation has not been run. */
    public static function summary(int $trainPct = 80): ?array
    {
        $path = self::path($trainPct);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['metrics']['model']) ? $data : null;
    }
}
