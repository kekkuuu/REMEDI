<?php

namespace App\Support;

/**
 * What the model forecast for the months already past: the broken line's history
 * on the forecast charts (2026-11-04, at the user's request: both lines over the
 * same months, solid for the actual and broken for the forecast).
 *
 * Each month is one month ahead, from a model trained ONLY on the months before
 * it (resources/python/evaluate_rolling.py), so it never saw the sale it is drawn
 * beside. The live forecast tables only hold the months ahead.
 *
 * It lives in a JSON file committed with the code, like ForecastSplit: it takes a
 * long time to compute, and only changes when the sales record is replaced.
 * Re-run php artisan forecast:evaluate-rolling and commit the file after that. No
 * file, no history on the broken line.
 *
 * A product's past revenue is its units at the CURRENT selling price, like every
 * other revenue figure here. The store's is its own series in the file, so a price
 * edit only reaches it at the next run.
 */
final class ForecastBacktest
{
    private static ?string $path = null;

    private static ?array $loaded = null;

    public static function path(): string
    {
        return self::$path ?? resource_path('data/forecast_backtest.json');
    }

    /** Read another file instead (tests); null goes back to the real one. */
    public static function useFile(?string $path): void
    {
        self::$path = $path;
        self::$loaded = null;
    }

    private static function data(): array
    {
        if (self::$loaded === null) {
            $path = self::path();
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            self::$loaded = is_array($data) && isset($data['months'], $data['products']) ? $data : ['months' => [], 'products' => []];
        }

        return self::$loaded;
    }

    /** @return array<string, int> forecast units per month ('Y-m'), for one SKU */
    public static function forProduct(string $sku): array
    {
        $data = self::data();
        $row = $data['products'][$sku] ?? null;

        if ($row === null) {
            return [];
        }

        [$first, $values] = $row;
        $out = [];
        foreach ($values as $i => $units) {
            if ($units !== null && isset($data['months'][$first + $i])) {
                $out[$data['months'][$first + $i]] = (int) $units;
            }
        }

        return $out;
    }

    /**
     * The whole store per month, forecast as ONE series rather than the products
     * added up: a product needs 13 months of sales first, so until April 2025 that
     * sum leaves out products the actual total counts (August 2023 read 52% low,
     * against 7.8% on the products it covered). That gap is about coverage, not the
     * model, and this series has every product from its first month.
     *
     * @return array{units: array<string, int>, revenue: array<string, float>}
     */
    public static function storeTrend(): array
    {
        $store = self::data()['store'] ?? [];

        return ['units' => $store['units'] ?? [], 'revenue' => $store['revenue'] ?? []];
    }
}
