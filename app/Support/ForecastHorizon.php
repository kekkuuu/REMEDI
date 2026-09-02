<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Where a forecast starts being something you can act on.
 *
 * The generated horizon OPENS on the current month: both Python scripts train
 * up to the last COMPLETE month, so the first row they write is a nowcast of
 * the month already in progress. That row is worth plotting — reading the
 * model's estimate against the partial actual is informative — but it is not
 * something anyone can order against, because most of the month has happened.
 *
 * So every "next month" figure in the app counts from the month after this one,
 * and this class is the single place that says so. It previously lived in three
 * places at once: `scopeNextMonthOnly()` on two different models (both dead
 * code, both correct) and two hand-rolled copies of the same Carbon expression
 * in the service and the detail view. Three definitions of one boundary is two
 * too many — the scopes could not be reused because they filter a QUERY, while
 * both live call sites filter an already-loaded collection.
 *
 * `addMonthNoOverflow()`, not `addMonth()`: on the 31st, `addMonth()` rolls a
 * short month over into the one after it (31 Jan + 1 month = 3 March), which
 * would silently skip a month for anyone loading the page on a month end.
 */
final class ForecastHorizon
{
    /**
     * Start of the first month a forecast can still be acted on.
     */
    public static function firstActionableMonth(): Carbon
    {
        return Carbon::now()->addMonthNoOverflow()->startOfMonth();
    }

    /**
     * The same boundary as a `Y-m` key, for comparing against month strings.
     *
     * `Y-m` sorts correctly as a plain string (zero-padded, most-significant
     * first), so callers can use `>=` on it without parsing anything back.
     */
    public static function firstActionableMonthKey(): string
    {
        return self::firstActionableMonth()->format('Y-m');
    }
}
