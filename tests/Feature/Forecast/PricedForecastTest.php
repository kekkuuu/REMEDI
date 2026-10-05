<?php

namespace Tests\Feature\Forecast;

use App\Models\Category;
use App\Models\Product;
use App\Services\SalesForecastService;
use App\Support\ForecastCache;
use App\Support\ForecastHorizon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Forecasting page's revenue is the DEMAND forecast x the current selling
 * price -- what the terminal report prints (2026-10-05). It used to read
 * sales_forecasts, which a separate nightly run filled, so the page and the
 * report could disagree for a night or (before 5cb714f) for good.
 *
 * The store-wide trend and the detail card use MySQL's DATE_FORMAT, which this
 * sqlite suite cannot run; the Top 5 by revenue shares the same pricing and
 * can, so it pins the rule. Verify the rest against MySQL (compare_page_terminal.py).
 */
class PricedForecastTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, string $price): Product
    {
        $category = Category::firstOrCreate(['name' => 'Medicine / Pharmaceutical']);

        return Product::create([
            'name' => 'Item '.$sku,
            'sku' => $sku,
            'category_id' => $category->id,
            'unit' => 'PCS',
            'selling_price' => $price,
            'reorder_level' => 1,
        ]);
    }

    private function demand(string $sku, \DateTimeInterface $month, float $units): void
    {
        DB::table('demand_forecasts')->insert([
            'product_sku' => $sku,
            'forecast_date' => $month,
            'forecast_value' => $units,
            'lower_ci' => $units / 2,
            'upper_ci' => $units * 2,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_revenue_is_demand_units_times_the_current_price(): void
    {
        // A Carbon, stored as Eloquent writes dates -- sqlite compares dates as text.
        $month = ForecastHorizon::firstActionableMonth();

        // Many cheap units against a few dear ones: revenue, not units, ranks.
        $this->product('CHEAP', '1.04');
        $this->product('DEAR', '180.50');
        $this->demand('CHEAP', $month, 4976);   // PHP 5,175.04
        $this->demand('DEAR', $month, 101);     // PHP 18,230.50

        // A stale sales_forecasts row with a different figure must be ignored.
        DB::table('sales_forecasts')->insert([
            'product_sku' => 'CHEAP', 'forecast_date' => $month,
            'forecast_units' => 9999, 'forecast_revenue' => 999999,
            'method' => 'sarima',
            'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $top = app(SalesForecastService::class)->topSalesForecastSeries(5);

        $this->assertSame(['DEAR', 'CHEAP'], array_column($top['series'], 'sku'));
        $this->assertEqualsWithDelta(18230.50, $top['series'][0]['values'][0], 0.001);
        $this->assertEqualsWithDelta(5175.04, $top['series'][1]['values'][0], 0.001);
    }

    public function test_a_price_change_reprices_the_forecast(): void
    {
        // A Carbon, stored as Eloquent writes dates -- sqlite compares dates as text.
        $month = ForecastHorizon::firstActionableMonth();
        $product = $this->product('ITEM', '10.00');
        $this->demand('ITEM', $month, 7);

        $product->update(['selling_price' => '12.50']);

        $top = app(SalesForecastService::class)->topSalesForecastSeries(5);
        $this->assertEqualsWithDelta(87.50, $top['series'][0]['values'][0], 0.001);
    }

    public function test_bumping_retires_the_cached_bundle(): void
    {
        $first = ForecastCache::key('index');
        ForecastCache::bump();

        $this->assertNotSame($first, ForecastCache::key('index'));
    }
}
