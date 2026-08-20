<?php

namespace App\Http\Controllers;

use App\Services\SalesForecastService;
use Illuminate\Http\Request;

class SalesForecastController extends Controller
{
    public function __construct(private SalesForecastService $forecasts)
    {
    }

    /**
     * GET /sales-forecast
     * Store-wide sales trend: units + revenue, actual vs. forecast, plus
     * top products. Per-product SKU-level forecast detail lives on the
     * Demand Forecasting page instead, to avoid two pages showing the
     * same per-product forecast built from the same sales data.
     */
    public function index(Request $request)
    {
        $trend = $this->forecasts->overallMonthlyTrend();

        return view('sales_forecast.index', compact('trend'));
    }
}
