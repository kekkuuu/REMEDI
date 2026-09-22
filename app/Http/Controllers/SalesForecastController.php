<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * GET /sales-forecast
 *
 * Merged into the Forecasting page (forecast.index) 2026-09-22, at the
 * user's request: this page and Demand Forecasting were built from the same
 * sales data and the same per-product forecast, so two nav entries pointed
 * at two views of one thing, and clicking a product on one page never
 * surfaced the other's forecast for it. The store-wide units/revenue trend
 * this controller used to render now lives at the top of forecast.index
 * (SalesForecastService::overallMonthlyTrend(), unchanged), and the per-
 * product view is on forecast.show alongside the demand forecast.
 *
 * The route stays registered, redirecting here, so an old bookmark or a
 * link still saved somewhere does not 404.
 */
class SalesForecastController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->route('forecast.index');
    }
}
