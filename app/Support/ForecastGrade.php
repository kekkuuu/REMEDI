<?php

namespace App\Support;

use App\Models\ForecastAccuracy;

/**
 * Turns a forecast's error into a verdict a buyer can act on.
 *
 * Three bands, because three is what a person can hold in their head while
 * deciding whether to trust a number: Normal, Acceptable, Not acceptable.
 *
 * The thresholds are the conventional MAPE reading (under 20% good, 20-50%
 * reasonable, over 50% poor), but they are applied to whichever measure is
 * actually DEFINED for the product, which matters here:
 *
 *  - MAPE divides by the actual, so it does not exist for a product that sold
 *    nothing in the scoring window — and that is 20%+ of this catalogue.
 *  - sMAPE stays defined at zero, so it is the fallback. Its scale differs
 *    (0-200% rather than 0-infinity), so it gets its own thresholds rather than
 *    being read against MAPE's.
 *
 * A product with no score at all is NOT graded. "Unmeasured" is not a pass, and
 * showing it as one would be the single most misleading thing this class could
 * do.
 */
final class ForecastGrade
{
    public const NORMAL = 'normal';

    public const ACCEPTABLE = 'acceptable';

    public const NOT_ACCEPTABLE = 'not_acceptable';

    public const UNRATED = 'unrated';

    /** Conventional MAPE bands. */
    private const MAPE_NORMAL = 20.0;

    private const MAPE_ACCEPTABLE = 50.0;

    /**
     * sMAPE bands, set higher than MAPE's on purpose.
     *
     * sMAPE is bounded at 200% and sits structurally higher on intermittent
     * demand — predicting 1 against an actual of 0 scores 200% however good the
     * model is. Reading it against MAPE's thresholds would mark almost every
     * sporadic product "not acceptable" for having sold nothing.
     */
    private const SMAPE_NORMAL = 40.0;

    private const SMAPE_ACCEPTABLE = 90.0;

    /**
     * @return array{grade:string, label:string, colour:string, basis:?string, value:?float, note:string}
     */
    public static function for(?ForecastAccuracy $accuracy): array
    {
        if (! $accuracy) {
            return self::verdict(
                self::UNRATED,
                basis: null,
                value: null,
                note: 'Not scored — too little history to hold months back and still fit a model.'
            );
        }

        if ($accuracy->mape !== null) {
            $value = (float) $accuracy->mape;

            $grade = match (true) {
                $value <= self::MAPE_NORMAL => self::NORMAL,
                $value <= self::MAPE_ACCEPTABLE => self::ACCEPTABLE,
                default => self::NOT_ACCEPTABLE,
            };

            return self::verdict($grade, 'MAPE', $value, self::noteFor($grade, 'MAPE'));
        }

        if ($accuracy->smape !== null) {
            $value = (float) $accuracy->smape;

            $grade = match (true) {
                $value <= self::SMAPE_NORMAL => self::NORMAL,
                $value <= self::SMAPE_ACCEPTABLE => self::ACCEPTABLE,
                default => self::NOT_ACCEPTABLE,
            };

            return self::verdict($grade, 'sMAPE', $value, self::noteFor($grade, 'sMAPE'));
        }

        return self::verdict(
            self::UNRATED,
            basis: null,
            value: null,
            note: 'Not rated — nothing sold in the scoring window, so there is no percentage error to judge.'
        );
    }

    private static function noteFor(string $grade, string $basis): string
    {
        return match ($grade) {
            self::NORMAL => "Within the usual range for {$basis}. Safe to plan against.",
            self::ACCEPTABLE => "Usable, but check it against the product's own history before a large order.",
            default => 'Too far out to order against on its own — treat it as a hint, not a number.',
        };
    }

    /**
     * @return array{grade:string, label:string, colour:string, basis:?string, value:?float, note:string}
     */
    private static function verdict(string $grade, ?string $basis, ?float $value, string $note): array
    {
        // Colours reuse the severity hues the rest of the app already assigns:
        // green healthy, amber worth a look, red problem, slate = no opinion.
        [$label, $colour] = match ($grade) {
            self::NORMAL => ['Normal', '#16a34a'],
            self::ACCEPTABLE => ['Acceptable', '#d97706'],
            self::NOT_ACCEPTABLE => ['Not acceptable', '#dc2626'],
            default => ['Not rated', '#94a3b8'],
        };

        return [
            'grade' => $grade,
            'label' => $label,
            'colour' => $colour,
            'basis' => $basis,
            'value' => $value,
            'note' => $note,
        ];
    }
}
