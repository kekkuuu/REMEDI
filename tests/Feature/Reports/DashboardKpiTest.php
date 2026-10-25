<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\DashboardController;
use Tests\TestCase;

/**
 * The admin dashboard's KPI row replaced Expired and Need to Return (still
 * visible elsewhere -- the Inventory tab, the bell, the Medicine Returns
 * card) with Average Transaction Value (ATV) and Average Transaction Count
 * (ATC). Both are extracted as static, pure functions on DashboardController
 * so they can be tested directly: index() itself cannot be requested in this
 * suite, because the admin body always calls SalesHistory::monthlyRevenue()/
 * seasonalTrends(), both MySQL-only (STRAIGHT_JOIN) queries the in-memory
 * sqlite connection here cannot run -- the same reason
 * Reports\SalesReportPeriodTest never requests the Sales Report page itself.
 */
class DashboardKpiTest extends TestCase
{
    public function test_atv_is_todays_sales_over_todays_transactions(): void
    {
        $this->assertSame(200.0, DashboardController::computeAtv(400.0, 2));
    }

    public function test_atv_is_null_with_no_transactions_today_rather_than_a_misleading_zero(): void
    {
        $this->assertNull(DashboardController::computeAtv(0.0, 0));
    }

    public function test_atc_averages_the_trailing_seven_days_not_a_raw_count(): void
    {
        // 7 transactions over the trailing week -> 1.0/day, a different
        // figure from "7 transactions today" (which has its own sub-line).
        $this->assertSame(1.0, DashboardController::computeAtc(7));
    }

    public function test_atc_rounds_to_one_decimal(): void
    {
        $this->assertSame(1.4, DashboardController::computeAtc(10));
    }

    public function test_atc_is_zero_not_null_with_no_sales_this_week(): void
    {
        $this->assertSame(0.0, DashboardController::computeAtc(0));
    }

    /**
     * Profit Today (2026-09-22, replacing "Last 7 Days"; labelled Revenue Today until 2026-10-22) is PROFIT --
     * price minus cost, summed per line -- not raw revenue. Also a static,
     * pure function for the same reason ATV/ATC are: index() cannot be
     * requested here.
     */
    public function test_profit_is_price_minus_cost_times_quantity(): void
    {
        $lines = [
            (object) ['quantity' => 2, 'price' => 100.0, 'cost_price' => 60.0],
        ];

        $result = DashboardController::computeTodayProfit($lines);

        $this->assertSame(80.0, $result['profit']);
        $this->assertSame(0, $result['missing_cost_count']);
    }

    public function test_a_line_with_no_cost_set_is_excluded_not_treated_as_pure_profit(): void
    {
        $lines = [
            (object) ['quantity' => 2, 'price' => 100.0, 'cost_price' => 60.0],
            (object) ['quantity' => 5, 'price' => 50.0, 'cost_price' => null],
        ];

        $result = DashboardController::computeTodayProfit($lines);

        // Only the first line counts -- the second isn't scored at 0 cost
        // (which would inflate profit) or silently folded in as if it were
        // known.
        $this->assertSame(80.0, $result['profit']);
        $this->assertSame(1, $result['missing_cost_count']);
    }

    public function test_no_lines_is_zero_profit_not_null(): void
    {
        $result = DashboardController::computeTodayProfit([]);

        $this->assertSame(0.0, $result['profit']);
        $this->assertSame(0, $result['missing_cost_count']);
    }

    public function test_profit_rounds_to_two_decimals(): void
    {
        $lines = [
            (object) ['quantity' => 3, 'price' => 10.005, 'cost_price' => 9.995],
        ];

        $result = DashboardController::computeTodayProfit($lines);

        $this->assertSame(0.03, $result['profit']);
    }

    /**
     * The Monthly Sales bar chart's series has every month (2026-10-25): July
     * 2026 had no sale on record and was simply missing, so the bars read
     * Jun, Aug as if consecutive.
     */
    public function test_the_monthly_chart_fills_months_with_no_sales_at_zero(): void
    {
        $rows = DashboardController::continuousMonths([
            ['ym' => '2026-05', 'label' => 'May 2026', 'total' => 10.5],
            ['ym' => '2026-08', 'label' => 'Aug 2026', 'total' => 3.0],
            ['ym' => '2026-06', 'label' => 'Jun 2026', 'total' => 7.0],
        ]);

        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08'], array_column($rows, 'ym'));
        $this->assertSame([10.5, 7.0, 0.0, 3.0], array_column($rows, 'total'));
        $this->assertSame('Jul 2026', $rows[2]['label']);
        $this->assertSame([], DashboardController::continuousMonths([]));

        // Across a year boundary, and a single month stays a single month.
        $this->assertSame(['2025-12', '2026-01', '2026-02'], array_column(DashboardController::continuousMonths([
            ['ym' => '2026-02', 'label' => 'Feb 2026', 'total' => 1.0],
            ['ym' => '2025-12', 'label' => 'Dec 2025', 'total' => 1.0],
        ]), 'ym'));
        $this->assertCount(1, DashboardController::continuousMonths([['ym' => '2026-03', 'label' => 'Mar 2026', 'total' => 1.0]]));
    }
}
