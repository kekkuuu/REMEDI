<?php

namespace Tests\Feature\Reports;

use App\Models\SalesHistory;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Sales Report's Daily / Weekly / Monthly / Yearly buttons GROUP one date
 * range (2026-09-28, at the user's request) -- they used to pick a range
 * counted from today, and on a Monday "today" and "this week" were the same
 * empty day. The report page itself is MySQL-only, so this pins the bucketing
 * it is built on: SalesHistory::bucketDaily() / bucketKey().
 */
class SalesGroupingTest extends TestCase
{
    private function history()
    {
        return collect([
            ['date' => '2025-12-29', 'units' => 1, 'revenue' => 10.00], // Mon, week of Dec 29 2025
            ['date' => '2026-01-02', 'units' => 2, 'revenue' => 20.00], // Fri, same week, next YEAR
            ['date' => '2026-01-05', 'units' => 3, 'revenue' => 30.00], // Mon, next week
            ['date' => '2026-02-10', 'units' => 4, 'revenue' => 40.00],
        ]);
    }

    private function pos()
    {
        // The till on a day the history also covers, and on one it does not.
        return collect([
            ['date' => '2026-01-05', 'units' => 1, 'revenue' => 5.50],
            ['date' => '2026-02-11', 'units' => 1, 'revenue' => 4.50],
        ]);
    }

    public function test_each_grouping_buckets_the_same_days(): void
    {
        $by = fn ($g) => SalesHistory::bucketDaily($this->history(), $this->pos(), '2025-12-01', '2026-02-28', $g)['rows']->pluck('revenue', 'key')->all();

        $this->assertSame(['2025-12-29' => 10.0, '2026-01-02' => 20.0, '2026-01-05' => 35.5, '2026-02-10' => 40.0, '2026-02-11' => 4.5], $by('day'));
        // Weeks start on Monday and cross the year boundary as one week.
        $this->assertSame(['2025-12-29' => 30.0, '2026-01-05' => 35.5, '2026-02-09' => 44.5], $by('week'));
        $this->assertSame(['2025-12' => 10.0, '2026-01' => 55.5, '2026-02' => 44.5], $by('month'));
        $this->assertSame(['2025' => 10.0, '2026' => 100.0], $by('year'));
    }

    public function test_every_grouping_adds_up_to_the_same_total_and_trading_days(): void
    {
        foreach (SalesHistory::GROUPS as $group) {
            $trend = SalesHistory::bucketDaily($this->history(), $this->pos(), '2025-12-01', '2026-02-28', $group);

            $this->assertSame(110.0, round($trend['rows']->sum('revenue'), 2), $group);
            $this->assertSame(12, $trend['rows']->sum('units'), $group);
            // Five distinct days had a sale, however they are grouped.
            $this->assertSame(5, $trend['active_days'], $group);
            $this->assertSame($group, $trend['granularity']);
        }
    }

    public function test_labels_say_what_each_bucket_is(): void
    {
        $rows = fn ($g) => SalesHistory::bucketDaily($this->history(), collect(), '2025-12-01', '2026-02-28', $g)['rows']->pluck('label')->all();

        // The range crosses a year, so day and week labels carry it.
        $this->assertSame('Dec 29, 2025', $rows('day')[0]);
        $this->assertSame('Wk of Dec 29, 2025', $rows('week')[0]);
        $this->assertSame(['Dec 2025', 'Jan 2026', 'Feb 2026'], $rows('month'));
        $this->assertSame(['2025', '2026'], $rows('year'));
    }

    public function test_the_bucket_key_is_the_one_used_for_the_tills_totals(): void
    {
        $sunday = Carbon::parse('2026-09-27 18:30');

        $this->assertSame('2026-09-21', SalesHistory::bucketKey($sunday, 'week'));
        $this->assertSame('2026-09-27', SalesHistory::bucketKey($sunday, 'day'));
        $this->assertSame('2026-09', SalesHistory::bucketKey($sunday, 'month'));
        $this->assertSame('2026', SalesHistory::bucketKey($sunday, 'year'));
    }

    /**
     * A cashier's report is their till sales alone (2026-09-28): no imported
     * rows, which carry no cashier -- so Total Sales and the trend agree.
     */
    public function test_a_cashier_trend_is_their_till_sales_alone(): void
    {
        $trend = SalesHistory::bucketDaily(collect(), $this->pos(), '2025-12-01', '2026-02-28', 'month');

        $this->assertSame(['2026-01' => 5.5, '2026-02' => 4.5], $trend['rows']->pluck('revenue', 'key')->all());
        $this->assertSame(2, $trend['active_days']);
    }

    /**
     * The graph's axis is every bucket of the range (2026-09-28), so a filter
     * that leaves only two months with sales still draws the whole range --
     * it used to collapse to two bars and look as if the graph had gone.
     */
    public function test_the_graph_axis_covers_every_bucket_of_the_range(): void
    {
        $months = SalesHistory::bucketAxis('2025-11-15', '2026-02-03', 'month');
        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], array_map('strval', array_keys($months)));
        $this->assertSame('Nov 2025', $months['2025-11']);

        $weeks = SalesHistory::bucketAxis('2026-09-02', '2026-09-27', 'week');
        $this->assertSame(['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21'], array_keys($weeks));

        $this->assertCount(27, SalesHistory::bucketAxis('2026-09-01', '2026-09-27', 'day'));
        $this->assertSame(['2022', '2023', '2024', '2025', '2026'], array_map('strval', array_keys(SalesHistory::bucketAxis('2022-01-02', '2026-09-27', 'year'))));

        // Every key the trend can produce is on the axis.
        $trend = SalesHistory::bucketDaily($this->history(), $this->pos(), '2025-12-01', '2026-02-28', 'week');
        $axis = array_map('strval', array_keys(SalesHistory::bucketAxis('2025-12-01', '2026-02-28', 'week')));
        foreach ($trend['rows'] as $row) {
            $this->assertContains($row['key'], $axis);
        }
    }

    public function test_an_unknown_grouping_falls_back_to_the_automatic_one(): void
    {
        $short = SalesHistory::bucketDaily($this->history(), collect(), '2026-01-01', '2026-01-31', 'fortnight');
        $long = SalesHistory::bucketDaily($this->history(), collect(), '2025-01-01', '2026-02-28', 'fortnight');

        $this->assertSame('day', $short['granularity']);
        $this->assertSame('month', $long['granularity']);
    }
}
