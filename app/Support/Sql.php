<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one definition of the SQL that differs between database servers.
 *
 * The app runs on MySQL or PostgreSQL (DB_CONNECTION), and the test suite on
 * SQLite. Every raw fragment that only one of them understands goes through
 * here, so a query reads the same whichever server answers it.
 */
final class Sql
{
    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    /** A date or timestamp column as 'YYYY-MM'. */
    public static function yearMonth(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /** A date or timestamp column as the first day of its month, 'YYYY-MM-01'. */
    public static function firstOfMonth(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM-01')",
            'sqlite' => "strftime('%Y-%m-01', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m-01')",
        };
    }

    /** The calendar month of a date column, 1-12. */
    public static function month(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "EXTRACT(MONTH FROM {$column})::int",
            'sqlite' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            default => "MONTH({$column})",
        };
    }

    /** The calendar year of a date column. */
    public static function year(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "EXTRACT(YEAR FROM {$column})::int",
            'sqlite' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
            default => "YEAR({$column})",
        };
    }

    /**
     * MySQL's join-order hint, which goes straight after SELECT. Load-bearing
     * there (see SalesHistory::monthlyRevenue); nothing else has an equivalent
     * or needs one, since PostgreSQL's planner chooses the order from statistics.
     */
    public static function straightJoin(): string
    {
        return self::driver() === 'mysql' ? 'STRAIGHT_JOIN ' : '';
    }

    /** The case-insensitive LIKE, for a raw fragment. Query builders use whereLike(). */
    public static function like(): string
    {
        return self::isPostgres() ? 'ILIKE' : 'LIKE';
    }
}
