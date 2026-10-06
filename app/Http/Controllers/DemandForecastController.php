<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\DemandForecastService;
use App\Services\SalesForecastService;
use App\Support\ForecastCache;
use Illuminate\Http\Request;

class DemandForecastController extends Controller
{
    public function __construct(
        private DemandForecastService $forecasts,
        private SalesForecastService $salesForecasts,
    ) {}

    /**
     * GET /forecasts
     * Every product with its next-month and 6-month-total forecast.
     * Powers the "Quantity Forecasting" table in the Analytics Dashboard.
     * Supports ?search= (matches product name or SKU) and ?category=
     * (category id).
     */
    public function index(Request $request)
    {
        // searchParam(): `?search[]=x` is an array, and allProductsSummary() is
        // typed ?string -- an uncaught TypeError, a 500.
        $search = $this->searchParam($request);
        $categoryId = $request->query('category') ? (int) $request->query('category') : null;

        $forecasts = $this->forecasts->allProductsSummary($search, $categoryId);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('forecast._rows', compact('forecasts'))->render(),
                'pagination' => (string) $forecasts->links('vendor.pagination.custom'),
                'search' => $search,
                'category' => $categoryId,
                'empty' => $forecasts->isEmpty(),
            ]);
        }

        $categories = Category::orderBy('name')->get();

        // Below the AJAX branch on purpose: the top-10 chart is not filtered by
        // the search box, so re-sending it on every keystroke would be wasted
        // work. Same reasoning as the KPI cards on the sales list.
        //
        // Demand Forecasting and Sales Forecasting merged into one page
        // (2026-09-22): $topSales is the per-product twin to $topDemand, ranked
        // on forecast REVENUE rather than units, so the two "top 5" charts
        // answer different questions -- what to reorder vs. what earns --
        // rather than the same ranking shown twice.
        //
        // One cached bundle (2026-10-05): both read only what a forecast run
        // writes -- see App\Support\ForecastCache. The accuracy cards (holdout,
        // store-wide, 80/20, rolling) were removed from the page 2026-10-06 at
        // the user's request; forecast:generate still measures and stores them.
        // 'index_v2': the 'index' bundle cached by the previous code holds four
        // values in a different order, and destructuring it would put the
        // accuracy summary where the revenue chart's series belongs.
        [$topDemand, $topSales] = ForecastCache::remember('index_v2', fn () => [
            $this->forecasts->topDemandSeries(5),
            $this->salesForecasts->topSalesForecastSeries(5),
        ]);

        // $trend is the store-wide units/revenue chart that used to live alone
        // on /sales-forecast; cached by SalesForecastService itself.
        $trend = $this->salesForecasts->overallMonthlyTrend();

        return view('forecast.index', compact(
            'search', 'categoryId', 'categories', 'forecasts',
            'topDemand', 'topSales', 'trend'
        ));
    }

    /**
     * GET /forecast/{product}
     * Full forecast curve for one product: actual monthly sales leading
     * up to now, plus the forecasted months with confidence bands -- and,
     * since the two forecasting pages merged, the same product's sales
     * (units + revenue) forecast beside it, so a click on one product
     * surfaces both instead of sending the two forecasts to different pages.
     */
    public function show(Request $request, string $product)
    {
        $data = $this->forecasts->forProduct($product);

        if ($data['forecast']->isEmpty()) {
            abort(404, 'No forecast available for this product yet.');
        }

        $data['salesForecast'] = $this->salesForecasts->forProduct($product);

        if ($request->wantsJson()) {
            return response()->json(['product' => $product, 'data' => $data]);
        }

        return view('forecast.show', $data);
    }
}
