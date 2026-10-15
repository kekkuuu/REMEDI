<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\DemandForecastService;
use App\Services\SalesForecastService;
use App\Support\ForecastCache;
use App\Support\ForecastSplit;
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
        // One cached bundle (2026-10-05): all three read only what a forecast run
        // writes -- see App\Support\ForecastCache. The Model accuracy card was
        // removed 2026-10-06 and put back 2026-10-13, both at the user's request
        // (the store-wide and rolling cards stay off the page; the 80/20 card
        // came back 2026-10-16, at the user's request -- see $split below).
        // 'index_v4': each earlier bundle key holds a different shape, and
        // destructuring one of those would put the wrong value in each slot
        // (v4: the accuracy summary gained holdout_label).
        [$topDemand, $topSales, $accuracy] = ForecastCache::remember('index_v4', fn () => [
            $this->forecasts->topDemandSeries(5),
            $this->salesForecasts->topSalesForecastSeries(5),
            $this->forecasts->accuracySummary(),
        ]);

        // The 80/20 chronological train/test evaluation's overall figures (back
        // 2026-10-16, at the user's request): a committed file written by
        // forecast:evaluate-split --summary, read through App\Support\ForecastSplit,
        // so every host shows the same run. No file, no card.
        $split = ForecastSplit::summary();

        // $trend is the store-wide units/revenue chart that used to live alone
        // on /sales-forecast; cached by SalesForecastService itself.
        $trend = $this->salesForecasts->overallMonthlyTrend();

        return view('forecast.index', compact(
            'search', 'categoryId', 'categories', 'forecasts',
            'topDemand', 'topSales', 'trend', 'accuracy', 'split'
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
