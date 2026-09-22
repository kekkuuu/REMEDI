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
     * Revenue Today (2026-09-22, replacing "Last 7 Days") is PROFIT --
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
}
