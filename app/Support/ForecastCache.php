<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The Forecasting page's figures that only change when the forecasts do.
 *
 * Measured 2026-10-05 against the live site: /forecast took 1.1-1.2 s warm
 * (2.9 s cold) and ran 37 queries even with every other cache warm -- the
 * Model accuracy card alone was 12, both "Top 5" charts 8, and one of them,
 * the "N scorable products" count, an EXISTS over sales_history taking 280 ms
 * on its own. On Vercel every query is a round trip from Frankfurt to the
 * Railway database, so the count of queries, not their size, sets the time.
 * None of these figures move between forecast runs.
 *
 * The keys carry a version stamp, so retiring them is one write: bump() from
 * everything that changes what they read -- both forecast imports, a sales
 * history import or catalogue sync, a SKU rename, a price change, archiving or
 * restoring a product. They also carry the first actionable month, so the
 * page's horizon rolls over at midnight on the 1st without anyone bumping, and
 * a TTL, so a write path nobody thought of costs hours, not forever.
 */
final class ForecastCache
{
    public const VERSION_KEY = 'forecast_cache_version';

    public const TTL_HOURS = 6;

    public static function key(string $name): string
    {
        return 'forecast_'.$name
            .':v'.Cache::get(self::VERSION_KEY, '0')
            .':'.ForecastHorizon::firstActionableMonthKey();
    }

    public static function remember(string $name, Closure $build): mixed
    {
        return Cache::remember(self::key($name), now()->addHours(self::TTL_HOURS), $build);
    }

    /** A fresh stamp, not an increment: two writers at once still both retire the old keys. */
    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, now()->format('YmdHisv').bin2hex(random_bytes(2)));
    }
}
