<?php

namespace Tests\Feature\Forecast;

use App\Support\ForecastBacktest;
use Tests\TestCase;

/**
 * The forecast charts' past months: the broken line's history, read from the file
 * forecast:evaluate-rolling writes. The pages themselves are checked in the browser.
 */
class ForecastBacktestTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'backtest');
        file_put_contents($this->file, json_encode([
            'months' => ['2024-01', '2024-02', '2024-03'],
            'products' => [
                // JSON keys that look like numbers come back as ints: SKUs here do.
                '4801000000001' => [0, [10, null, 30]],
                'ABC' => [1, [5, 7]],
            ],
            'store' => [
                'units' => ['2024-01' => 100, '2024-02' => 110],
                'revenue' => ['2024-01' => 1000.5, '2024-02' => 1100.25],
            ],
        ]));
        ForecastBacktest::useFile($this->file);
    }

    protected function tearDown(): void
    {
        ForecastBacktest::useFile(null);
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_a_product_reads_its_months_with_a_failed_month_left_out(): void
    {
        $this->assertSame(['2024-01' => 10, '2024-03' => 30], ForecastBacktest::forProduct('4801000000001'));
    }

    public function test_a_product_starting_later_keeps_its_months_lined_up(): void
    {
        $this->assertSame(['2024-02' => 5, '2024-03' => 7], ForecastBacktest::forProduct('ABC'));
    }

    public function test_a_product_with_no_row_has_no_history(): void
    {
        $this->assertSame([], ForecastBacktest::forProduct('NOPE'));
    }

    public function test_no_file_means_no_history_rather_than_an_error(): void
    {
        ForecastBacktest::useFile($this->file.'.missing');

        $this->assertSame([], ForecastBacktest::forProduct('ABC'));
        $this->assertSame(['units' => [], 'revenue' => []], ForecastBacktest::storeTrend());
    }

    /** Its own series from its first month, not the products added up, which would leave products out. */
    public function test_the_store_reads_its_own_series_rather_than_adding_up_the_products(): void
    {
        $trend = ForecastBacktest::storeTrend();

        $this->assertSame(['2024-01' => 100, '2024-02' => 110], $trend['units']);
        $this->assertSame(['2024-01' => 1000.5, '2024-02' => 1100.25], $trend['revenue']);
    }

    public function test_a_file_with_no_store_series_has_no_store_history(): void
    {
        file_put_contents($this->file, json_encode(['months' => ['2024-01'], 'products' => ['ABC' => [0, [5]]]]));
        ForecastBacktest::useFile($this->file);

        $this->assertSame(['units' => [], 'revenue' => []], ForecastBacktest::storeTrend());
    }

    public function test_the_committed_file_when_present_is_well_formed(): void
    {
        ForecastBacktest::useFile(null);
        $path = ForecastBacktest::path();

        if (! is_file($path)) {
            $this->markTestSkipped('No forecast_backtest.json yet.');
        }

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertSame(array_values(array_unique($data['months'])), $data['months']);
        $this->assertSame($data['months'], collect($data['months'])->sort()->values()->all());
        $this->assertLessThanOrEqual($data['record_end'], end($data['months']));
    }
}
