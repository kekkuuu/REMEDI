<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\ReportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Sales Report's quick ranges: Daily, Weekly, Monthly, Yearly.
 *
 * Each is "the current one, up to today", resolved on the server from a single
 * definition (ReportController::periodRange) so the page and its Excel/PDF
 * exports cannot disagree about what "weekly" means.
 *
 * The report PAGE itself is not requested here: its aggregates are MySQL SQL
 * (STRAIGHT_JOIN, DATE_FORMAT), which the in-memory sqlite this suite runs on
 * cannot execute -- the reason no test in this suite renders it. So this pins
 * the range arithmetic, which is where a wrong answer would be silent, and the
 * validation that runs before any query.
 */
class SalesReportPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_quick_range_resolves_to_the_current_period_up_to_today(): void
    {
        // A Wednesday, mid-month, mid-year: every range is distinguishable from
        // every other, and Weekly collapses into neither Daily nor Monthly.
        Carbon::setTestNow('2026-09-16 10:00:00');

        $this->assertSame(['2026-09-16', '2026-09-16'], ReportController::periodRange('daily'));
        $this->assertSame(['2026-09-14', '2026-09-16'], ReportController::periodRange('weekly'), 'Monday to today');
        $this->assertSame(['2026-09-01', '2026-09-16'], ReportController::periodRange('monthly'));
        $this->assertSame(['2026-01-01', '2026-09-16'], ReportController::periodRange('yearly'));
    }

    public function test_weekly_on_a_monday_is_just_today_and_on_a_sunday_reaches_back_six_days(): void
    {
        Carbon::setTestNow('2026-09-14 08:00:00'); // Monday
        $this->assertSame(['2026-09-14', '2026-09-14'], ReportController::periodRange('weekly'));

        Carbon::setTestNow('2026-09-20 23:30:00'); // Sunday
        $this->assertSame(['2026-09-14', '2026-09-20'], ReportController::periodRange('weekly'));
    }

    public function test_the_first_of_the_month_and_the_first_of_the_year_are_one_day_ranges(): void
    {
        Carbon::setTestNow('2027-01-01 00:05:00');

        $this->assertSame(['2027-01-01', '2027-01-01'], ReportController::periodRange('monthly'));
        $this->assertSame(['2027-01-01', '2027-01-01'], ReportController::periodRange('yearly'));
    }

    public function test_the_four_periods_are_exactly_the_four_buttons(): void
    {
        $this->assertSame(['daily', 'weekly', 'monthly', 'yearly'], ReportController::PERIODS);
    }

    public function test_an_unknown_period_is_refused_rather_than_ignored(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/reports/sales?period=fortnightly')
            ->assertSessionHasErrors('period');
    }

    public function test_atv_is_pos_total_over_pos_transactions(): void
    {
        [$atv] = ReportController::atvAtc(3000.0, 4, '2026-09-01', '2026-09-07');

        $this->assertSame(750.0, $atv);
    }

    public function test_atv_is_null_with_no_transactions_rather_than_zero(): void
    {
        [$atv] = ReportController::atvAtc(0.0, 0, '2026-09-01', '2026-09-07');

        $this->assertNull($atv);
    }

    public function test_atc_is_transactions_per_day_across_the_whole_period_not_just_days_with_one(): void
    {
        // 7-day period (inclusive), only 4 transactions total -- ATC must
        // divide by all 7 days, not by however many of them had a sale.
        [, $atc] = ReportController::atvAtc(3000.0, 4, '2026-09-01', '2026-09-07');

        $this->assertSame(0.6, $atc);
    }

    public function test_atc_on_a_single_day_range_divides_by_one(): void
    {
        [, $atc] = ReportController::atvAtc(1000.0, 5, '2026-09-01', '2026-09-01');

        $this->assertSame(5.0, $atc);
    }
}
