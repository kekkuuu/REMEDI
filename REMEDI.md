# REMEDI.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

REMEDI — a pharmacy point-of-sale + inventory management system with SARIMA-based demand and
sales forecasting. Laravel 12 backend, Blade frontend, MySQL, plus a Python forecasting layer
invoked as a subprocess from Artisan commands. Runs under XAMPP on Windows (docroot `public/`).

## Commands

```bash
php artisan migrate --seed
```
Seeds a default admin (`admin@remedi.com` / `password`) and staff (`staff@remedi.com` / `password`),
then categories → products → batches → inventory receipts → sales history, in that dependency order.
The whole catalog comes from CSVs on disk, not factories — see "Seed data lives in CSVs" below.

```bash
php artisan test
```
Run a single test file or method:
```bash
php artisan test --filter=AuthenticationTest
```
**Note:** `phpunit.xml` has the sqlite in-memory lines commented out, so tests run against the
`DB_*` database configured in `.env`. The Breeze tests use `RefreshDatabase`, so running the suite
against your working `.env` **wipes the seeded 339k-row database**. Point `.env` at a throwaway
database first, or uncomment those two lines.

`tests/` is still the untouched Breeze/Laravel scaffold — there is no coverage of POS, inventory,
returns or forecasting, so a green suite proves nothing about this app's domain logic. Two of the
scaffold tests also contradict the app on purpose and fail: `Feature\ExampleTest` expects `200` from
`/` (which redirects to `login`), and `Feature\Auth\RegistrationTest` expects public registration
(`/register` is admin-only — see "Roles and routing"). Fix or delete them if you start writing real
tests; don't "fix" the app to satisfy them.

```bash
vendor/bin/pint
```

Forecast regeneration (see "Forecasting pipeline" below). On Windows the Python executable is
usually `python`, not the `python3` default baked into the command signature:
```bash
php artisan forecast:generate --source=mysql --python=python
```
```bash
php artisan sales-forecast:generate --source=mysql --python=python
```
Add `--workers=1` to either to force sequential fitting when debugging a model change; the default
(`0`) spreads products across all cores but one.
Python deps: `pip install -r resources/python/requirements.txt`.

Re-derive the Category column of a product master CSV (dry run without `--write`; the first write
leaves a `.bak` beside the file — there is no `.bak` in the tree today, so the next `--write` is
the one that creates it):
```bash
php artisan products:classify-csv database/data/inventory_seeder.csv --write
```

**Never run `php artisan route:cache` on this app.** Caching the route collection drops GET from the
`/` route's method list -- the front page then answers `405` with
`allow: HEAD, POST, PUT, PATCH, DELETE, OPTIONS`, locking everyone out at the login redirect.
`config:cache` and `view:cache` are both safe (`optimize:clear` undoes either).

Bootstrap products/batches from supplier receiving-report exports:
```bash
php artisan import:receiving-reports path/to/report.csv path/to/report.xlsx
```

`npm run dev` / `npm run build` exist but see "Frontend" — the Vite bundle is not actually loaded
by any view.

## Architecture

### Legacy skeleton on a modern framework
`composer.json` requires `laravel/framework: ^12.0`, but the app still uses the **Laravel 10-style
skeleton**: `bootstrap/app.php` binds `App\Http\Kernel` / `App\Console\Kernel`, middleware aliases
live in `app/Http/Kernel.php`, and the scheduler lives in `app/Console/Kernel.php::schedule()`.
Do not "modernize" to the Laravel 11+ `bootstrap/app.php` fluent style piecemeal — register new
middleware aliases and scheduled commands in the existing Kernel files.
`temp-laravel12-reference/` is a pristine Laravel 12 skeleton kept only for comparison; it is not
part of the app.

### Roles and routing
All routes are in `routes/web.php` behind `auth`. Two roles (`admin`, `staff`) on the `users`
table, enforced by the `role` middleware alias → `app/Http/Middleware/EnsureUserHasRole.php`,
which also force-logs-out deactivated (`is_active = false`) users.

- Shared (admin + staff): dashboard, POS, inventory, sales list.
- `role:admin` group: products/batches/categories CRUD, reports, both forecast pages, user
  management, audit trail. `/register` is admin-only — it is the "Add User" form, not public signup.

`DashboardController` branches on role to pick `admin.dashboard` vs `staff.dashboard`.

### Inventory model: products → batches
Stock is never stored on `products`. It lives on `product_batches` rows (quantity, unit_cost,
expiry_date, received_date, dr_no). `Product::$total_stock` sums them; `is_low_stock` compares that
sum to `reorder_level`. Most accessors on `Product` check `relationLoaded('batches')` and read from
memory when the relation is eager-loaded — preserve that pattern when adding accessors, since
`DashboardController` and `InventoryController` deliberately load batches once and attach slices via
`setRelation` to avoid N+1 queries.

**POS checkout deducts FEFO** (first-expiring-first-out): `PosController::checkout` walks batches
ordered by `expiry_date ASC` and creates one `SaleItem` per batch touched, so a single cart line can
produce multiple sale items. All of it runs inside a `DB::transaction`.

`checkout()` answers in two shapes. For AJAX/JSON it returns `{success, transaction_no,
receipt_html, receipt_url}` (or `422 {success:false, error}`), and the POS page shows that HTML in a
modal so the cashier never leaves the register. A plain form post still gets the old redirect to
`pos.receipt` — that path is the no-JavaScript fallback and the permalink used for reprints, so keep
both working. The receipt markup and CSS live in `pos/_receipt.blade.php` and
`pos/_receipt-styles.blade.php`, shared by the modal and the standalone page so a printed receipt is
identical either way. The modal prints through an offscreen iframe (it copies `#receipt-styles` into
it) rather than `window.print()`, because hiding the full-height sticky sidebar by CSS leaves it
laid out and pushes blank pages onto an 80mm roll. Printing fires automatically when the receipt
modal opens; the "Print Again" button is the retry path for a cancelled dialog or an offline printer.

**Gotcha in that iframe:** `document.write()` + `close()` completes the document *synchronously*, so
the iframe's `load` event has usually already fired by the time you could attach a listener —
waiting on `addEventListener('load')` alone never runs and silently disables printing entirely
(this was a real bug). `printReceipt()` checks `doc.readyState === 'complete'` first and only falls
back to the event, with a timeout backstop and a `printed` flag so it fires exactly once.

**Supplier return windows** (`ProductBatch::getReturnStatusAttribute`) are category-dependent:
- `Medicine / Pharmaceutical` products: 90–120 days before expiry = "Need to Return"; under 90 days
  or expired = "Fail to Return"; already flagged = "Successfully Returned".
- Every other category falls back to a plain 10-day-before-expiry rule
  (`Product::NON_PHARMA_RETURN_WINDOW_DAYS`) with no "fail" state.

Carbon 3 (required by Laravel 12) made `diffInDays()` return a *signed* value. Date-distance math in
these accessors passes `true` as the second argument deliberately — removing it silently inverts the
return-window logic.

### Payment is mandatory
The customer's payment must cover the total before a sale can be created — there is no bypass.
A supervisor passcode that could void the payment check used to exist (`config/pos.php`,
`POS_VOID_PASSCODE`, `POST /pos/void-passcode`, `PosController::verifyVoidPasscode`) and has been
removed entirely; don't reintroduce it without being asked.

`sales.payment_voided` and the receipt's VOIDED line are deliberately **kept**: sales taken before
the removal still carry `true`, and reprints/reports must not misrepresent them as normally paid.
Checkout now always writes `false`. Treat the column as read-only history.

### Audit trail
`AuditTrail::log($action, $details)` is a static helper that resolves the current user itself
(falling back to "System"). Call it from controllers for any state-changing operation — sales,
voids, user/product mutations.

### The sales history is SYNTHETIC, and deliberately seasonal

`database/data/Sales_Records_4Year.csv` — 48 months, **Sep 2022 → Aug 2026**, 469,829 sale lines
across 224,349 transactions, aggregating to **338,748 `sales_history` rows** (~49 MB). It is
**generated demo data**, not a real sales record.

**It was regenerated to look like a till, not a warehouse.** The previous version was measurably
not a shop:

| | before | now | why it matters |
|---|---:|---:|---|
| lines per transaction | **1.00** (208,931 rows / 208,931 Sale IDs) | 2.09 | every "sale" was a single item |
| units per line | **25.2**, max 5,464 | 4.05, max 30 | that is wholesale, not a customer |
| product-months with a sale | **99.8%** | 59.0% | there was no long tail at all |
| weekday index | **flat 1.00 every day** | Sat 1.22, Sun 0.74 | no weekly rhythm |
| SKUs moving per month | 2,632 of 2,637 | 1,524 of 2,584 | everything sold every month |
| monthly revenue | ₱2.21M | ₱1.53M | ₱1.2–1.9M reads as a medium pharmacy |
| payment mix | 60/20/20 | Cash 72 / GCash 20 / Card 8 | card is rare at a community till |

It also now carries a **payday spike** (the 15th/16th and month-end run ~15–25% above an ordinary
day, which is the strongest weekly signal in Philippine retail) and 53 products that never sell at
all — dead stock, which a real catalogue has and which the old file did not.

**Consequences to expect, not to "fix":**
- Slow movers no longer support a seasonal model, so the forecast chain genuinely falls through:
  **2,275 products get `sarima_ensemble`, 245 fall to Holt-Winters / ARIMA / moving average**, and
  117 products get no forecast at all (under the 3-month floor).
- **550 of 15,120 forecast rows are exactly 0.** That is the documented "intermittent, slow-moving
  demand the model floors at zero" case, which the old data was too dense to ever produce.

It replaced an earlier 3-year file (since deleted) for one measured reason: that file had **no
12-month seasonality at all**. Month-of-year explained a median of **38.1%** of a
product's variance against a **35.3% pure-noise baseline** for a series of that shape — i.e. nothing.
Its store-wide monthly totals climb from 21,936 (Jan 2024) to ~85,000 by late 2024 and then sit flat;
that ramp is products being introduced, not a season. **No amount of extra history makes a seasonal
model useful on a series with no seasonal signal**, so lengthening that file alone would have
achieved nothing.

The 5-year file gives each product a seasonal profile picked from its category and name — Philippine
retail-pharmacy seasons: hot/dry Mar–May, rainy Jun–Oct, cool + holidays Nov–Feb:

| profile | applies to | shape |
|---|---|---|
| `respiratory` | cough/cold/flu/antihistamine/fever names | peaks Jul–Sep and Dec–Jan |
| `gi` | loperamide, ORS, antacids | peaks Jun–Aug (rainy) |
| `vitamins` | Vitamins & Supplements | peaks Dec–Feb |
| `summer` | Personal Care, Beverages, Snacks | peaks Mar–May, plus a Dec bump |
| `flat` | Baby Care, Household, Medical Supplies, Milk | ±2%, Dec bump only |

plus 4%/yr growth and lognormal month-to-month noise. Seasonality now measures **41.9%** against a
~20% noise baseline at 59 points.

**Read forecast accuracy on this data with that in mind.** The seasonal pattern the model finds is
one this generator planted, so the metrics below show that the pipeline works end to end — they are
NOT evidence of how it would perform against a real pharmacy's sales. Base levels are carried over
from each product's real observed volume, so relative catalogue sizes are preserved.

### Forecasting pipeline
Two independent pipelines, both PHP → Python subprocess → CSV → upsert into MySQL:

| Concern | Command | Python script | Table | Service / Controller |
|---|---|---|---|---|
| Demand ("how much to buy") | `forecast:generate` | `resources/python/generate_forecasts.py` | `demand_forecasts` | `DemandForecastService`, `/forecast` |
| Sales units + revenue | `sales-forecast:generate` | `resources/python/generate_sales_forecast.py` | `sales_forecasts` | `SalesForecastService`, `/sales-forecast` |

Both commands shell out via `Symfony\Component\Process\Process` (30-minute timeout), write a CSV to
`storage/app/forecasts/`, then batch-upsert it keyed on `(product_sku, forecast_date)` **and delete
any row the run did not refresh** (`generated_at < $now`). That delete matters: the upsert only
overwrites dates the new run also produced, so when the forecast window moves — which it does every
time history grows — the previous window's rows are left behind and then *shadow* the fresh ones,
because the summary picks the first row from the current month onward. Regenerating after the
history was extended left 3,247 such rows.
`forecast:generate` runs nightly at 02:00 via `app/Console/Kernel.php`.

**Parallelism:** both scripts fit products across a `ProcessPoolExecutor` (`--workers`, default
`0` = all cores but one; `1` = sequential, useful when debugging a model change). Processes, not
threads — the cost is CPU-bound inside statsmodels' optimizer, so the GIL would serialize threads.
Each script pins `OMP_NUM_THREADS` and friends to `1` **before importing numpy**; without that,
per-fit BLAS threading multiplied by N workers oversubscribes the CPU and runs slower than
sequential. Results are order-preserved (`pool.map`), so output stays byte-identical to a
sequential run — measured on this catalog: 2,637 products, 317s → 31s, same forecast values.
A per-product failure is caught in the worker and reported in a summary rather than killing the run.

Important details:
- **Forecasts are keyed by `product_sku`, not `product_id`** — every join to `products` goes through
  `products.sku`.
- **Both forecast pages shade an 80% confidence band**, built the same way: a `Lower bound` dataset
  with no stroke, then an upper-bound dataset with `fill: '-1'` — order matters, the fill points back
  one index. Each bound anchors to the last actual month so the band opens out of the actual line
  rather than appearing a month later, and both bounds are filtered out of the tooltip and legend so
  they do not read as two extra unlabelled series. `forecast/show.blade.php` uses per-product
  `lower_ci`/`upper_ci`; `sales_forecast/index.blade.php` sums `lower_ci_units`/`upper_ci_units` and
  the revenue pair across products. The view reads those keys as `?? collect()` because
  `overallMonthlyTrend()` is cached for 6h — a payload cached before they existed must render an
  unshaded chart, not a 500.
- The Python scripts read DB credentials by **parsing `.env` directly** (`--env-path`), not through
  Laravel config. Changing DB config in a way that only affects Laravel will silently break them.
- Model selection is by history length **and by whether the fit survives a plausibility check**:
  ≥36 months → **seasonal ensemble**, then airline SARIMA alone, ≥24 → non-seasonal ARIMA(1,1,1),
  ≥12 → damped exponential smoothing, ≥3 → trailing moving average. Products below the floor get no
  forecast rows.

  **The primary is a median of three seasonal models, not one SARIMA.** `_ensemble_rows` takes the
  element-wise median of the airline SARIMA `(0,1,1)(0,1,1,12)`, seasonal Holt-Winters, and a
  seasonal naive (same month in the last two years, averaged). The three fail *differently* — SARIMA
  overswings on an outlier month, Holt-Winters lags a turning point, the naive is unbiased but noisy
  — so the median discards whichever one disagrees most. A member whose own fit fails `_plausible`
  gets **no vote**, and if none survive the chain falls through to the plain airline SARIMA behind it.

  Measured on the **full catalogue** (2,637 products; every model scores every product, because a
  rejected fit falls through the same chain — score only the products a model accepts and it
  flatters itself by dropping the hard ones):

  | holdout | metric | airline alone | median ensemble |
  |---|---|---:|---:|
  | 6mo | MAE | 10.89 | **10.27** (−5.7%) |
  | 6mo | RMSE | 18.94 | **17.88** (−5.6%) |
  | 6mo | MAPE | 147.4% | **133.8%** (−9.2%) |
  | 6mo | sMAPE | 105.6% | **104.4%** (−1.1%) |

  Re-measured on the regenerated data (2,014 products with enough history, 12,084 product-months).
  **The percentage errors are enormous now and that is correct**: a realistic long tail means many
  holdout months are genuinely 0 or 1 unit, and MAPE divides by those. Read MAE and RMSE here; the
  percentages are only useful as a relative comparison between models on the same data. The previous
  figures in this table (MAE 8.17 → 7.78, MAPE ~20%) were measured on the old file where every
  product sold every month — flattering, and gone.

  The ranking is unchanged, which is why the shipped chain is unchanged: the ensemble still beats the
  airline SARIMA on every metric, and plain Holt-Winters still edges it on MAE (10.10) while serving
  fewer products (1,539 of 2,014 against the ensemble's 2,014 — a model that rejects the hard
  products and falls through is not winning, it is abstaining).

  6 months is the production horizon (`--horizon` default). **No single model beat this on MAE, RMSE
  and MAPE at once** — the search covered `(0,1,2)`, `(0,1,3)`, `(1,1,2)`, `(2,1,1)`, `(1,0,1)`,
  `(0,1,2)(0,1,2,12)`, D=0 seasonal terms, a constant trend, and sqrt/log1p transforms. Plain
  Holt-Winters edges it on MAE (7.78 vs 7.82 before the `use_brute` change) and loses on RMSE;
  airline remains the best *pure* SARIMA order tried, which is why it stays as the fallback.

  **Watch the runtime when touching the Holt-Winters member.** It now runs for every product rather
  than as a rare fallback. `ExponentialSmoothing.fit()` defaults to `use_brute=True`, a grid search
  for starting values that measured 218ms/product against 114ms without it — with it the nightly run
  went 23s → **276s**; `fit(use_brute=False)` brings it back to **31s** while changing forecasts by a
  mean of 0.027 units (~0.3% of the model's own MAE).

  **2,275 of 2,520 forecast products are served by the ensemble** (`method=sarima_ensemble`); the
  rest fall through to seasonal Holt-Winters (65), plain Holt-Winters (64), ARIMA (54) or a moving
  average (62), because a realistic long tail leaves slow movers without the 36 months of continuous
  history a seasonal model needs. A run that reports 100% `sarima_ensemble` means the history is too
  dense to be realistic, not that the model got better. Note `demand_forecasts` has **no
  `method`/`confidence` column** — only `sales_forecasts` stores it — so the confidence map in
  `main()` matters for the sales side and the CSV, not the demand table.

  **Seasonal differencing needs THREE cycles, not two.** `seasonal_order=(1,1,0,12)` burns a whole
  year of observations before it can estimate anything, so at 24-32 months it is badly
  over-parameterised: the fit oscillates and roughly half its output comes out negative. Measured on
  this catalogue (183 products, 3-month holdout):

  Measured, 3-month holdout. On the old 3-year history (300 products):

  | model | MAE | RMSE | MAPE | sMAPE | negative month |
  |---|---:|---:|---:|---:|---:|
  | seasonal SARIMA at ≥24mo (old) | 5686.65 | 110129.80 | 34792.7% | 136.1% | **49%** |
  | gated chain | **17.39** | **51.41** | **123.2%** | **105.2%** | **0%** |

  **The seasonal ORDER matters more than anything else here.** On the 48-month seasonal history
  (90 products, 3-month holdout):

  | model | MAE | RMSE | MAPE | sMAPE |
  |---|---:|---:|---:|---:|
  | Holt-Winters seasonal | **9.50** | **31.62** | 18.4% | **18.1%** |
  | **airline SARIMA (0,1,1)(0,1,1,12)** — now the fallback, see the ensemble above | 9.58 | 32.55 | 20.0% | 20.3% |
  | (1,1,1)(0,1,1,12) | 9.73 | 33.32 | 20.5% | 19.6% |
  | log1p + airline | 9.84 | 34.13 | 19.9% | 19.9% |
  | seasonal naive, avg of last 2 same-months | 10.21 | 32.85 | 18.3% | 18.5% |
  | **(1,1,1)(1,1,0,12)** — the old order | **12.14** | **38.58** | **22.0%** | **21.0%** |

  The old seasonal term was an **AR**; this data wants a seasonal **MA**. Swapping
  `(1,1,0,12)` → `(0,1,1,12)` — the classic "airline" model — cut MAE 21% on its own.

  Fixing the seasonal order took the chain **MAE 12.14 → 9.58 (−21%), RMSE 38.58 → 32.55 (−16%),
  MAPE 22.0% → 19.1% (−13%), sMAPE 21.0% → 18.3% (−13%)** on that 90-product sample. The median
  ensemble described above then took it down again, measured on the whole catalogue.

  Holt-Winters seasonal measured marginally *better* than the airline SARIMA on its own (MAE 9.50 vs
  9.58 — under 1%, well inside noise on 90 products), and still does at full scale. It is not
  shipped as the primary because SARIMA is this project's stated method; the ensemble keeps SARIMA
  as one of the three votes and as the only member that estimates an interval, which is where
  `lower_ci`/`upper_ci` come from.

  MAPE is undefined wherever actual sales are 0, so it is computed over non-zero months only and
  sMAPE is reported beside it. On the 3-year data 178 of 750 holdout months were zero, which is why
  its percentage errors are enormous.

- **A rejected fit falls through; it is never repaired.** `_plausible()` rejects non-finite values,
  *any* negative month, and anything above the historical ceiling, and the next simpler model is
  tried. The old code instead wrapped every value in `max(0.0, x)`, which laundered a diverged fit
  into a confident forecast of **0 units** — products selling 160/month with no empty months were
  displayed as zero. That was the "forecasting shows 0" bug, and it accounted for 419 of 2,539
  products (16.5%) on the demand side and 2,054 rows on the sales side. Both are now 0.
  **A zero must mean "the model expects no demand", never "the model fell over".**

- Method names are data: `main()` counts them with a `defaultdict` and maps confidence with `.get()`.
  Adding a method to the chain must not `KeyError` the write step after a 26-second run (it did).
- Training data is `sales_history` (actual customer demand), *not* `inventory_receipts` (supplier
  restocking), which reflects order-pattern noise. Don't "fix" this by switching the source.
- `DemandForecastService::allProductsSummary` deliberately does **not** hard-filter on
  `forecast_date >= today`: when the source data is stale (seed data, or a missed nightly run) every
  forecast row is in the past and a strict filter would blank the whole page. It falls back to the
  earliest generated forecast date instead — but that fallback decides only **which products appear**,
  never **which row is shown for a product**.

  Each product's forecast window starts the month after *its own* last month of sales history, so the
  windows do not line up: a product that stopped selling in mid-2025 has a window that closed long
  ago, while most run into 2027. Taking `->first()` off that global cutoff therefore showed whichever
  row was oldest — a year-old figure for hundreds of products, and a stale 0.4 units renders as a flat
  `0`. **That was the "the forecast shows 0" bug: a real number from the wrong month.** The summary
  now takes the first row from the current month onward per product, falls back to that product's
  most recent row only when its whole window is in the past, and flags it `is_stale` so the view can
  label it "as of <month>" instead of passing it off as next month's.

  A genuine forecast of 0 is *not* a bug: it means intermittent, slow-moving demand the model floors
  at zero, and the view prints `<1` rather than `0` for values between 0 and 0.5 so a small forecast
  is not mistaken for no forecast. On the regenerated data that case is real again — **550 of 15,120
  rows forecast exactly 0** — because the long tail contains products that genuinely sell a handful
  of times a year. (An earlier note here said zeros no longer arose; that was true only of the old
  file, where every product sold every month.)

### Two sales tables — pick the right one
This trips people up, and has already caused one real bug:

- **`sales` / `sale_items`** — live POS checkouts written by `PosController::checkout`. On a typical
  install this holds only the transactions rung up on that machine (here: 22 rows, one month).
- **`sales_history`** — the imported sales record (here: 339k rows, 48 months, Sep 2022→Aug 2026).
  Written **only** by `SalesHistorySeeder`. The POS never touches it.

Anything showing a *trend over time* must read `sales_history`, or it will plot a near-empty chart
while years of data sit unused. The Dashboard's "Total Sales per Month", "Seasonal Trends" and
"High/Low Demand" cards, plus the Analytics report's seasonal chart, all read it via
`App\Models\SalesHistory`. Today's takings / transaction counts correctly read `sales`.

`sales_history` stores units only, so revenue is always `quantity_sold * products.selling_price`.
`SalesHistory` sets `$table` explicitly — Eloquent would otherwise resolve it to `sales_histories`,
which does not exist.

Its aggregates scan 339k rows, so they're cached for 24h and `SalesHistorySeeder` calls
`SalesHistory::forgetCaches()` after reseeding. Rank top/bottom sellers with `ORDER BY ... LIMIT` in
SQL, not by fetching every product and sorting in PHP — that measured 2.1s vs 0.85s and cached ~1,600
rows to display eight.

**Two query traps live in this table, and both only became visible once the history was made
realistic (205k → 339k rows). Do not undo either.**

1. **`STRAIGHT_JOIN` on every `sales_history` → `products` aggregate.** Left alone, MySQL drives the
   join from `products` (2.7k rows) and does one index lookup per row into the
   `(product_sku, sale_date)` unique index. That index has a **1022-byte key** — `product_sku` is
   `VARCHAR(255)` utf8mb4 — and the plan falls off a cliff as the table grows. Measured three runs
   each at 339k rows: **53.6s unhinted vs 3.9s forced**. The hint rides on `selectRaw`, whose content
   lands immediately after `SELECT`, which is where MySQL wants the keyword.
2. **There is now an index leading with `sale_date`** (`sales_history_date_sku_qty_index`, migration
   `2026_08_20_000001`). Without it, any `whereBetween('sale_date', …)` aggregate had no way in and
   scanned the whole `product_sku` index — `recentDemand` measured **45.6s** to find a window holding
   7,850 rows. With it: **92ms**. An earlier note in this file rejected a `sale_date` index because
   "those aggregates group by `DATE_FORMAT(sale_date, …)`, which no index can satisfy" — true of
   `monthlyRevenue` and `trendBetween`, false of `recentDemand`, `unitsSoldBetween` and
   `topProductsBetween`, which filter on a plain range and group by SKU. That rejection generalised
   from the wrong query.

Cold timings after both fixes: `monthlyRevenue` 3.5s, `recentDemand` 92ms, `seasonalTrends` 3ms
(derived from the cached monthly series), `overallMonthlyTrend` 6.3s, Analytics ~16s.

### Performance notes (measured, don't re-litigate)
The Dashboard and Sales Forecasting pages were the two slow ones, for completely different reasons.

**Dashboard — PHP-bound, not SQL-bound** (~130ms of SQL in a ~1.4s page). It hydrates every
in-stock batch (~2,500) and every product (~2,600), then derives status in memory. The dominant
cost was Carbon: `return_status` was recomputed on every read, and the dashboard read it three
times per medicine batch via `is_returned`/`needs_return`/`failed_return`. `ProductBatch` now
memoizes `is_expired` and `return_status` per instance (`$derivedMemo`), and the controller tallies
with one `countBy` pass instead of three filters. 581ms → 193ms on that section; page ~1.48s → ~0.88s.
If you add a derived attribute here, memoize it the same way — and note `return_status` memoizes via
`array_key_exists` because `null` is a meaningful value, while the bool ones can use `??=`.

**Inventory status filters — narrowed in SQL, decided in PHP.** Low stock / expiring / expired /
return states are computed accessors, so they cannot be a WHERE clause. They can still be *bounded*
by one: any product the expiry-driven filters can match must have an in-stock batch expiring within
**120 days** — the outer edge of the medicine return window, with the 90-day "expiring" horizon and
the 10-day non-pharma rule both inside it, and no lower bound so expired batches still qualify.
`InventoryController` applies that `whereHas` for `expiring`/`expired`/`need_to_return`/
`fail_to_return`, which cuts the PHP pass from 2,637 products to 208. Verified identical result
sets on all four (86/72/77/73 products). Measured: the "Need to Return" tab went 476ms → 43ms of
controller time, ~2.9s → ~0.93s end to end.

The controller also points each batch back at its parent via `setRelation('product', $p)`:
`ProductBatch::$return_days` and `$is_returnable` both reach for `$this->product` to pick a return
window, and without it every rendered row lazy-loaded one query per batch.

**Sales Forecasting — SQL-bound**, aggregating 339k `sales_history` rows (~6.3s cold, ~5ms warm).
`overallMonthlyTrend()` is cached for 6h (`SalesForecastService::CACHE_KEY`) — safe because
`sales_history` is written **only** by the seeder/import, never by the POS, which records into
`sales`/`sale_items`. `GenerateSalesForecast` forgets that key after importing. Warm: ~5ms.

Two things were tried and settled by measurement:
- Top products now group by the indexed `product_sku` and resolve names for just the top 5, instead
  of joining every history row to `products` and grouping by name: 797ms → 404ms, then → **106ms** with the
  covering index added in `2026_08_17_000003`.
- Merging the **actual** monthly units + revenue queries into one `LEFT JOIN` pass is **slower**
  (1339ms vs 375+766ms) because the units total needs no join at all. They are deliberately kept
  separate; don't "optimize" them back together. **This does not apply to the forecast side**: those
  aggregates all come from `sales_forecasts` with no join and the same `GROUP BY`, so units, revenue
  and the four confidence bounds are one `$forecastAgg` query — merging there removed a duplicate
  scan rather than adding a join.
- A `(sale_date, quantity_sold)` index was once trialled and rejected here because *these* aggregates
  group by `DATE_FORMAT(sale_date, ...)`, which no index can satisfy. **That is still true of this
  page's queries, but the conclusion was over-generalised** — the range-filtered aggregates
  elsewhere (`recentDemand`, `unitsSoldBetween`, `topProductsBetween`) do benefit, and now have
  `sales_history_date_sku_qty_index`. See the two query traps under "Two sales tables".

### Loading skeletons
Two levels, both defined in `layouts/app.blade.php`:
- **Page skeleton** — full sidebar navigations (see "Navigation skeleton" below).
- **List skeleton** — `REMEDI.showListSkeleton(wrapper, {rows, grid})`, called before every AJAX list
  refresh on POS/inventory/products/sales/forecast. Those pages replace `#results-wrapper` over
  fetch; without it the stale rows sat on screen until the response landed, so typing in a search box
  looked inert. It reserves the wrapper's current height first so the page doesn't jump, and
  `clearListSkeleton()` releases that once the real rows arrive.

### Dashboard panels scroll, they don't truncate

The Expiring Soon and Returns panels rendered `take(8)` / `take(5)` and dropped the rest on the
floor, while their own headers counted the full set — with 15 medicines expiring, 16 other, 27
batches due for return and 50 non-pharma ones, most of what the card advertised was unreachable
without leaving for the Inventory page. The Expiring Soon lists now render every row inside
`.expiry-scroll`. **Cap the height, not the row count.**

**The two Returns panels are the deliberate exception, and they are a summary, not a truncation.**
They moved into `.inv-returns-col`, a third column beside the two expiring lists (see "Inventory tab
layout"), which is too narrow for a batch list. Each now shows the doughnut, the legend with its
counts, and a `.returns-more` footer link — "27 batches inside the return window" — pointing at the
Inventory filter that lists exactly those batches. Nothing is hidden: the counts are still on the
card, and the batch identities live one click away on a page that can show them properly. That is
different from `take(5)`, which silently dropped rows a header was still counting. If you put an
inline list back here, widen the column first.

Note when measuring these in a browser: the admin dashboard has two `.dash-section` tabs (Sales /
Inventory) and the Inventory one is `display:none` until selected, so every element inside it reports
zero height until you click that tab.

### Sidebar scroll and the category submenu

`.sidebar nav` is the scroller (`.sidebar` itself is `overflow:hidden`). With the Inventory submenu
expanded its content is **1113px inside a 499px window** — more than half the menu is out of view.
Every page is a full server render, so the nav came back at `scrollTop: 0` each time: scroll down to
a category near the bottom, click it, and the menu jumped back to the top, away from the item you
just picked. The position is now kept in `sessionStorage` (per-tab — a scroll offset should not
outlive its window), written on scroll and again on click, since a click navigates before the
debounce fires.

When there is no stored offset (fresh tab, or a link opened directly) it scrolls the `.active` item
into view instead. Two things that made earlier attempts silently do nothing:
- **Measure with `getBoundingClientRect()` against the scroller, not `offsetTop`** — `offsetTop`
  resolves against `.sidebar`, not `.sidebar nav`, so it isn't "distance down the scrollable
  content".
- **Do not defer it to `requestAnimationFrame`.** rAF does not run in a page that isn't compositing
  (background tab, hidden pane), so the adjustment never happens. `getBoundingClientRect()` flushes
  layout by itself, so a synchronous read already accounts for the submenu height applied just
  above. (The same caveat applies to the existing double-rAF that removes `nav-no-anim`.)

The submenu's open height is set from `scrollHeight` in JS. The CSS `max-height: 640px` is only the
no-JS fallback: the category list is **data**, it grew from 7 entries to 10, and at 618px of content
it was one category away from silently clipping the last ones off the bottom.

### Inventory filter tabs are AJAX

The tabs (All / Low Stock / Expiring / Need to Return / ...) are real `<a href>` links, so they
still work without JS, stay shareable and open in a new tab on middle-click. A plain left click is
intercepted and routed through the same `runInventorySearch()` fetch the search box uses.

They were full page loads: the sidebar, KPI cards and header were re-rendered for a change that
only affects the rows, and the browser dropped the scroll position — click a tab half way down the
list and you were thrown back to the top. The handler keeps the same guards as the sidebar
navigation handler (modified/middle clicks fall through), moves the `.active` class itself, and
`popstate` re-syncs it so Back doesn't leave the highlight on a filter you navigated away from.

### Search: live filtering, no dropdown
`REMEDI.attachSuggest` debounces input and fires a `suggest:live` event; each page listens and
refreshes its own table/grid. There is deliberately **no suggestion dropdown** — it was built, then
removed by request, so results appear only in the page's own list. `SuggestController` and the
`/suggest/*` routes remain as the JSON source if a picker is ever wanted again.

**Products has no Filter button.** The search box refreshes the list on a debounced keystroke and the
category select on `change`, so the button only ever re-ran a search that had already run. The
`<form>` stays a real GET form and the page still works without JS: it has exactly one field that
blocks implicit submission (the text input — a `<select>` does not), so Enter submits natively even
with no submit button present. Inventory and Sales still have theirs; they were not part of that
request.

**Audit filters live, and filter by date.** `admin/audit/_rows.blade.php` was split out so the
search box (debounced), the action/role selects, the two date inputs and the Today/7/30-day presets
all swap just the table over fetch — including its pagination, because this page draws its own pager
rather than using `$paginator->links()`. `AuditTrailController::index` returns
`['html' => …, 'total' => …]` for AJAX; `total` feeds the "N entries" counter beside the filters.
Date bounds use `whereDate` on both ends, so a same-day filter includes that whole day rather than
stopping at 00:00. Search now also covers `action` and `ip_address`, not just username/details.

The plain GET page (users) has no live listener on purpose: auto-submitting on every typing
pause reloads the page mid-word. Enter submits them natively.

### Real-time search suggestions
`SuggestController` (+ `/suggest/{products,sales,users,audit}`) backs a shared typeahead. Every
endpoint returns the same shape — `{results:[{label, meta, value, ...}]}` — so one front-end
component drives all of them. Product results also carry `id/sku/price/stock`.

Attach by putting `data-suggest-url` on any input; `REMEDI.attachSuggest` wires it automatically on
DOMContentLoaded (wraps the field in `.suggest-wrap`, builds the panel, handles debounce, abort of
in-flight requests, arrow keys, Enter, Escape, click-outside, and match highlighting).

Choosing a suggestion fires a `suggest:choose` event carrying the row — each page decides what that
means: **POS adds it straight to the cart** (the payload has id/price/stock, and that's the action a
cashier wants), the other AJAX lists re-run their search, and the plain GET pages (users, audit)
submit their form. Queries under 2 characters return nothing on purpose.

### POS on tablet/phone
The register switches to a quick-service layout below 1024px: one full-width wall of large product
tiles (3 across on tablet, 2 on phone, 1 below 340px) with the cart **docked to the bottom of the
viewport** as a collapsed summary bar — item count, running total, tap to slide the full cart up.
`.pos-products` carries bottom padding so the docked bar never covers the last row of tiles.

**Why the classes matter:** the layout was `style="grid-template-columns: 2fr 1fr"` inline, which a
media query cannot override — on a 375px screen it still computed to 474px + 650px and put the cart
~750px off-screen. Anything that needs to reflow must be a class (`.pos-layout`, `.pos-grid`,
`.pos-cart`), never an inline grid.

### Report aggregate caching
Analytics measured 8.8s uncached at 205k rows and ~16s at 339k, all of it SQL over `sales_history` — long enough
that clicking through from the reports hub visibly sat on the old page. `topProductsBetween`,
`unitsSoldBetween` and `trendBetween` are cached per (start, end).

Range keys can't be enumerated to invalidate, so they carry a **version stamp**. There are two,
deliberately:
- `sales_cache_version` — bumped by `bumpHistoryVersion()` (reseed/import). Retires everything.
- `pos_cache_version` — bumped by `bumpCacheVersion()`, called from `PosController::checkout`.
  Retires only POS-dependent keys.

A checkout must not retire the history-only aggregates: they cost ~5s to rebuild and a sale doesn't
change them. `trendBetween` goes further — it caches only the history half and merges the (tiny) POS
delta fresh on every call, so a sale costs ~300ms instead of 3.7s.

Measured: cold 6.6s / warm 276ms / after a POS sale 307ms.

### Reports: screen copy vs print copy
Each report renders the data **twice** — `#no-print` for the screen and `#print-area` for paper.
`#print-area` is unpaginated by design (the whole dataset, so it prints complete), so it is
`display: none` on screen and `display: block` only inside `@media print`. Leaving it visible made
the Inventory report ~167 viewports tall: 119,320px of duplicate rows against 1,282px of real page.
The screen copy shows the same data in a capped `.list-scroll` box with a sticky header.

### Categories are derived from the product name, not the supplier file

The master file's `Category` column behaves like a naive substring match over the product name, so
it cannot be trusted: it filed 170 deodorants and shampoos under Medicine / Pharmaceutical, ice
cream ("CREAM CUPS") under Personal Care, a condom ("TRUST CONDOM CHOCOLATE") under Snacks, hair wax
("GATSBY WAX WATERGLOSS") under Water & Beverages and baby soap ("J&J BABY SOAP MILK") under Milk &
Dairy -- while leaving contraceptive pills, corticosteroid creams, IV fluids and salbutamol inhalers
in General Merchandise.

`App\Services\ProductClassifier::classify()` re-derives the category from the name instead. Three
things about it matter:

- **The rule table is ORDERED, first match wins**, because the signals collide constantly:
  "MYRA E 400IU" is a vitamin but "MYRA LOTION" is a toiletry; "CREAM CONES" is dessert but
  "BETNOVATE CREAM" is a corticosteroid. Narrow buckets (devices, supplements, baby, household)
  resolve before broad ones (personal care, then medicine). Reordering the table changes the output.
- **A stated dose or an oral dosage form wins over any cosmetic pattern** (rule 2b), which is what
  keeps "NIZORAL 20MG/ML SHAMPOO" a medicine and stops ' FC ' ("facial cleanser" everywhere else in
  this catalogue) from throwing out "MYREVIT-C PLUS FC TAB". Vitamins are checked *before* that rule
  because they carry the same IU/MG strengths and the same tablet and syrup forms.
- **No match returns `null`, meaning "leave this product alone"** -- never a guess into General
  Merchandise. 170 products are unplaceable (mostly brand-only drug names like `TELMIGEN 80`) and
  stay wherever they are.

`ProductSeeder` and `import:receiving-reports` both call it, so a reseed or reimport off the
original CSV lands in the right categories rather than undoing the work. Migration
`2026_08_19_000001` applied it to the seeded data (1,164 products moved) and created the three
categories the master file never had: **Medical Supplies & Devices**, **Baby Care**, **Household**.

Preview any rule change before committing it -- the command is a dry run unless `--apply` is passed:

```bash
php artisan products:classify --category="Medicine / Pharmaceutical"
```

This is not cosmetic tidying. `Product::$is_medicine` keys off the category name and drives the
90-120 day supplier return window, so every misfiled toiletry was being held to a drug's return
schedule and every misfiled drug was not. The dashboard's split between "Medicine Returns" and
"Other Product Returns" only tells the truth if the categories do.

When adding patterns, check both directions on real data (`--samples=400` prints the names), and
watch the words that mean two things: CREAM, POWDER, MILK, WATER, MASK, COLOR and CHOCOLATE each
belong to two different aisles in this catalogue.

### What counts as medicine
`Product::$is_medicine` drives the 90-120 day supplier-return window, so it must mean *actual
pharmaceutical stock*. Category alone isn't enough: the supplier master files ~170 toiletries and
cosmetics under "Medicine / Pharmaceutical", and a body spray was inheriting the drug return window
and showing up as "Need to Return".

`is_medicine` = in that category AND not `is_personal_care_item`, which matches two lists on the
product name (`NON_PHARMA_NAME_PATTERNS` for forms like DEO SPRAY / COLOGNE, `NON_PHARMA_BRANDS` for
houses like REXONA / NIVEA / SILKA that sell nothing medicinal).

**A stated dosage always wins.** `DOSAGE_PATTERN` (MG/MCG/IU/%) short-circuits both lists, because
"NIZORAL 20MG/ML SHAMPOO" is a medicated antifungal that the word SHAMPOO would otherwise have
thrown out. Deliberately does NOT match a bare `ML` — "135ML" is a bottle size, not a dose.

This name-pattern rule now sits on top of correctly-filed categories rather than compensating for
bad ones (see "Categories are derived from the product name" above): reclassification moved every
personal-care item out of Medicine, so `is_personal_care_item` currently matches nothing inside that
category and acts as a safety net for data that arrives before the classifier runs.

When adding patterns, spot-check both directions — that cosmetics are caught AND that therapeutic
lines (BETADINE, SALBUTAMOL, EFFICASCENT, ALCOPLUS...) still classify as medicine.

### Status colours are fixed by a legend

The design supplies five status colours; **do not invent new ones or re-map these**:

| status | colour | classes |
|---|---|---|
| Low Stock | yellow | `.badge-danger`, `.filter-low_stock` |
| Expiring Soon | orange | `.badge-warning`, `.badge-expiry-soon`, `.filter-expiring` |
| Expired | red | `.badge-critical`, `.badge-expiry-expired`, `.filter-expired` |
| Need to Return | blue | `.badge-return-due`, `.filter-need_to_return` |
| Returned | green | `.badge-return-done`, `.filter-returned` |

Stock and expiry share one **yellow → orange → red** severity ramp; the supplier-return states sit
outside it in **blue/green**, so "act on this return" is never mistaken for "this is about to go
off" — the same separation the old rose/amber split existed to enforce, now expressed in the
legend's hues. **Fail to Return is not in the legend**: it keeps a deep red (`.badge-return-late`),
because it means what Expired means — too late to act — but darker so the two stay separable.

A tab wears the same colour as the badge it filters for, so the Inventory tab strip and the Status
column always agree. Dashboard doughnut slices and KPI accents use the same hues (see `C` in
`admin/dashboard.blade.php`), which is why blue is reserved for Need to Return and the money KPIs
use teal.

### Two status scales — expiry vs supplier return
These are different thresholds and must never share a colour family:

- **Expiry** (`ProductBatch::$expiry_severity`) — expired / critical (<=7d) / soon (<=30d) /
  watch (<=90d). Rendered with `.badge-expiry-*`, the yellow→orange→red ramp above.
  The dashboards' "Expiring Soon" list uses **30 days** (`EXPIRY_SOON_DAYS`), deliberately NOT 90:
  90 is the return window's lower bound, so the old 3-month cutoff made a batch "expiring soon" at
  the exact moment it stopped being returnable. The 90-day view lives on as the Inventory "Expiring"
  filter.
- **Supplier return** (`return_status`) — Need to Return / Fail to Return / Successfully Returned.
  Rendered with `.badge-return-*`, in **blue / deep red / green**.

**"Fail to Return" gets no button.** The window has been missed, the supplier will not take the
stock back, and offering the action implies otherwise — those batches are a write-off to dispose of,
not to return. `is_returnable` is true for medicine only while *inside* the window. Non-pharma has no
"fail" state, so an expired non-pharma batch still offers the action.

`ProductBatch::$return_status` implements the **pharma 90/120 rule only** — its docblock asks each
consumer to make the category check itself. `ProductBatch::$is_returnable` is that check, in one
place: it applies the 90-120 day window for medicine and the plain
`NON_PHARMA_RETURN_WINDOW_DAYS` expiry rule for everything else. **Use it for any "Mark Returned"
control.** Both views used to gate the button on `$product->is_medicine` and render nothing
otherwise, so a non-pharma product could show a "Need to Return" badge with no way to act on it
while every "Fail to Return" row (pharma by definition) had a button — which read as the button
being attached to the wrong status.

The return window sits *above* the expiry horizon: a medicine batch is only returnable at 90-120
days before expiry, so by the time it counts as "expiring soon" (<=90d) it has already fallen out of
that window. The admin dashboard previously styled its return counts with the expiry classes, which
made the two read as one warning. Measured cross-family hue gap is now 35 degrees; keep it there.

Both dashboards share these classes, so a colour means the same thing on either screen.

### Responsive layout
The app has three breakpoints, all defined in `layouts/app.blade.php` (there were none before):
**≤1024px** narrows the sidebar to 216px and tightens padding; **≤767px** turns the sidebar into an
off-canvas drawer with a `.sidebar-scrim` backdrop; **≤420px** drops the KPI grid to one column.

On mobile the drawer always boots closed regardless of the saved `remedi_sidebar_hidden`
preference, and toggling it there deliberately does **not** write to localStorage — a phone's
transient drawer state must not overwrite the desktop preference. It closes on scrim tap, Escape,
and on tapping any nav link (otherwise it covers the page you just asked for).

Three layout primitives matter when adding UI:
- **`.table-scroll`** — wrap every wide table. Report cards use `overflow:hidden` for their rounded
  corners, which *clips* a wide table on a phone instead of scrolling it, leaving right-hand columns
  unreachable.
- **`.chart-box`** — wrap every Chart.js canvas and set `maintainAspectRatio: false`. With
  `responsive: true` and no height box, Chart.js sizes a doughnut to its container's **width**, so
  one in a half-width card rendered ~460px tall.
- **`minmax(0, 1fr)`, never a bare `1fr`** on any grid holding a table, a long batch number, or a
  no-wrap header. An `fr` track's implicit minimum is `min-content`, and a grid *item*'s default
  `min-width` is `auto`, so neither can shrink below its content. **And when you add the `minmax(0,…)`,
  check what is fixed-width inside it** — freeing the track to shrink just moves the overflow to the
  first child that cannot, which is how a 250px doughnut started pushing the staff Inventory band
  past the viewport at 1280px the moment its track was allowed to narrow. `width: 100%; max-width:
  250px` on the ring, and a floor on the legend beside it, fixed that half. This has bitten several
  times:
  `.inv-bottom` resolved to **430/536/536px** instead of the ratio asked for, and `.dash-lower` let
  the Recent Sales card run to **604px on a 375px screen** — 229px off-screen and unreachable, with
  `.table-scroll` sitting at 560px believing it had the room, so it never scrolled. Add `min-width:
  0` to the items too when the child is the thing that won't shrink. Symptom to watch for: a
  `.table-scroll` whose `scrollWidth === clientWidth` on a narrow screen is not "fits", it is
  "escaped".

**Size charts in the stylesheet, not with an inline `height`.** An inline style beats any rule, so a
`<div class="chart-box" style="height:170px">` cannot be re-sized to balance the card next to it —
which is exactly what Sales Summary and Stock Status needed. Both now take their height from
`.dash-lower-side .chart-box` / `.stock-status-grid > .chart-box`. Same reasoning as the POS layout
note above: anything that may need to reflow belongs in a class.

### Table row hover: paint the cells, not the row

`.remedi-table tbody tr:hover > td` — **not** `tr:hover`. A background set on a `<tr>` in these
`border-collapse` tables does not render at all (verified: a `<td>` takes the colour, the `<tr>`
ignores it even with `!important`), so the obvious row-level rule looks correct and shows nothing.
That is why hovering a row used to tint only the pinned Actions column — the pre-existing
`tr:hover td.col-actions` rule was cell-level and worked, while the row-level fill it was written
against silently did nothing.

Background only, no `transform`: a row that lifts drags the whole table's baseline with it. Cards
follow the opposite rule — only `a.card` (a card that is actually a link) reacts to the pointer;
a plain `.card` is a container and stays put.

### Confirmations are a centred modal, not `window.confirm()`

Log out uses `#logoutModal` (`.remedi-modal` in `layouts/app.blade.php`): a fixed, flex-centred
dialog with `role="dialog"`, `aria-modal`, focus moved to the confirm button, Escape and
backdrop-click to cancel, a two-control focus trap, and focus restored to the trigger. It sits
**outside** `.main-content` so the backdrop covers the sidebar too — a dialog you can click behind is
not one.

Confirming POSTs over `fetch` with the form's own CSRF token and follows the redirect
(`res.url`), rather than letting the form navigate. The `<form>` stays a real POST form and the JS
only intercepts `submit`, so **without JS it still logs you out** — unconfirmed, but not a dead
button. A failed fetch falls back to `form.submit()` rather than stranding the user on a disabled
dialog.

The browser dialog it replaced put the question in the top-left chrome, nowhere near the footer
button that raised it.

### Inventory: SKU is its own column, and the selected filter glows

`inventory/_rows.blade.php` shows **Product ID | SKU | Product Name | …**. SKU used to be a
parenthetical after the name; it is the barcode staff read off a box and the key every forecast joins
on, so it needs to be scannable down a column. `.col-sku` gives it tabular figures in a monospace
face — in the proportional body face the digits do not line up row to row, which is exactly when a
transposed pair slips past. Adding the column moved the empty-state `colspan`, which is
role-dependent (9 admin / 8 staff).

The filter tabs are AJAX, so clicking one swaps the whole table without a page load; the solid fill
alone was a quiet signal for that. `.filter-btn.active` now carries a colour-matched halo and a
one-shot `filter-glow-pop`. Each tab sets `--glow` as an **rgb triplet** (`--glow: 220 38 38`) so one
rule can do `rgb(var(--glow) / .28)` for every tab — keep new tabs' `--glow` equal to their fill, or
the halo and the badge it filters for stop agreeing. The animation is dropped under
`prefers-reduced-motion`: the fill already says which tab is selected, so the pulse is decoration.

### Buttons and back links
`.btn` variants live in the layout: primary/success/danger/info/warning are solid, `.btn-secondary` is outlined
(solid grey competed with the primary action next to it). Action buttons lift 2px on hover with a
colour-matched glow and settle on `:active`; `.btn-sm` is the toolbar size.

Row actions are colour-coded by meaning: **info** (blue) = view/edit/manage, **warning** (amber) =
reversible restriction such as deactivating a user, **danger** (red) = destructive, **success**
(green) = confirm. Don't leave a row action on plain `.btn-secondary` — it reads as inert.

Sidebar navigation shows the page skeleton, but links dressed as buttons are excluded
(`.btn`, `.btn-back`, `[data-no-skeleton]`): a button is an action, not a page change, so it
shouldn't blank the content out.

Every "back" control is `<div class="page-back"><a class="btn-back">` — its own row, top-left,
before anything else. Three different treatments existed before (a grey slab, a 34px icon square, a
plain text link) in varying positions. Don't reintroduce a page-specific one.

### No zero-stock products
`Product::MIN_STOCK_FLOOR` / `openingStockFloor()` guarantee every catalogued product has sellable
stock. The supplier master export ships `Stock = 0` for anything out on export day; seeding that
verbatim left 86 of 2,637 products unsellable at the till and skewed the inventory report.
`ProductBatchSeeder` raises empty rows to the floor, and migration `2026_08_17_000004` fixed the
already-seeded data. A zero-quantity batch on a product that still has stock elsewhere is a normal
depleted lot and is left alone.

### Seed data lives in CSVs, not factories

`DatabaseSeeder` creates the two default users inline, then hands off to five CSV importers. The
files are real supplier/POS exports and are read by path, so a missing one is a silent-ish failure:
each seeder prints `File not found: ...` and returns, leaving that table empty while the rest of the
seed "succeeds".

| Seeder | Reads |
|---|---|
| `CategorySeeder`, `ProductSeeder`, `ProductBatchSeeder` (opening stock) | `database/data/inventory_seeder.csv` |
| `ProductBatchSeeder::seedTransactionHistory` | `database/data/transaction_history_seed.csv` |
| `InventoryReceiptSeeder` | `Transaction_Records_Seed.csv` (repo **root**, via `base_path()`) |
| `SalesHistorySeeder` | `database/data/Sales_Records_4Year.csv` (~49 MB, 470k sale lines) |

`transaction_history_seed.csv` is **not present** in this checkout, so that pass always skips — the
purchase-history batches (zero-quantity rows carrying `qty_received`/`unit_cost`/`dr_no`) never get
created. That is the current state, not a bug to chase; those rows are deliberately zero-quantity so
they contribute DR/cost history without double-counting opening stock.

### Dashboard proportions

The two charts (Total Sales per Month, Seasonal Trends) sit **side by side** in `.dash-charts`, each
in a 230px `.chart-box`. They used to be stacked full-width with a `height` attribute on the canvas,
which Chart.js overrides — so with 48 months of history the monthly chart alone filled the viewport
and everything below it fell off the fold. Both now set `maintainAspectRatio: false`, the rule every
`.chart-box` needs.

**Seasonal Trends is a LINE chart, one series per year** (`$seasonalByYear`, pivoted in the Blade
from `$monthlySales` — no extra query). Newest year solid and darkest, older years lighter and
dashed; `spanGaps: true` so a part-finished year's line stops rather than diving to zero in the
months it has no data for. It used to be a single bar series of all-time monthly averages, which
flattened away the year-on-year comparison the panel exists for.

**The Low Demand chart forces headroom on its axis** (`suggestedMax`). Slow movers are routinely all
the same tiny number — five products that sold 1 unit each — and Chart.js scales the axis to the data
max, so every bar rendered pinned at 100% and the panel read as five best-sellers. The axis is now at
least `max(2, ceil(max × 1.4))`, which puts a value of 1 at half width. `recentDemand()`'s `$lowN`
also went 3 → 5 so the two demand panels are the same length; a three-row list beside a five-row one
reads as though the data ran out.

Every canvas on this page now sets `maintainAspectRatio: false` and sits in a `.chart-box`. A
`height` attribute on a `responsive: true` canvas is ignored — that is what made the demand charts
render 292px tall inside a 210px box.

The year filter **defaults to the most recent year, not "All years"**: 48 bars crammed edge to edge
is not a readable card, and this panel exists to answer "how did this year go". "All years" is still
in the dropdown.

Measured at 1600px after the fix: page height 3344px → **2107px**, KPI row six across in one row.
The old `.dash-eyebrow` / `.dash-title` / `.dash-timestamp` block was removed — it duplicated the
greeting bar directly beneath it, so the page carried two headings and two clocks.

### Inventory tab layout

Two bands, both on the admin Inventory tab (staff mirrors the first — see the staff section below):

`.inv-top` puts Inventory Overview beside a stacked `.inv-side` (Expiry Overview, then `.inv-pair` =
Lowest Stock + Stock Status), collapsing to one column under 1200px. Both rings carry a caption in
the hole via the local `centreText` plugin — registered per chart, not globally, so only the rings
that ask for one get one. Stock Status shows the same four buckets as the Expiry Overview bar: the
bar answers "what share is fine", the ring sits next to the counts so a glance gives both.

`.inv-bottom` is the band below it: the two Expiring Soon lists with `.inv-returns-col` (Medicine
Returns + Other Product Returns, stacked) as a narrower third column. It was two separate 2-up rows,
which pushed returns below the fold and left each row half-balanced. Three things make it hold:

- **Every track is `minmax(0, …)`, never a bare `1fr`.** An `fr` track's implicit minimum is
  min-content, and these cards carry long batch numbers plus a no-wrap header meta line — as `1fr 1fr
  0.85fr` the tracks resolved to **430/536/536px** instead of the ratio asked for.
- **The returns panels are summaries** (doughnut + legend + a link out), not lists — see "Dashboard
  panels scroll, they don't truncate".
- **`.inv-bottom > .card .expiry-scroll` is 456px, not the shared 320px.** The stacked returns column
  comes to ~548px; matching the expiring lists to it makes all three columns exactly equal and just
  shows more rows. Re-measure this if you change either returns card's height.

Below 1400px the returns column spans the full row as a horizontal pair; below 900px everything
stacks. **Quick Actions and Alerts are not part of either band** — they stay in `.dash-actions-row`
above the tab switcher.

### Inventory by Category charts PRODUCTS, not units

One category holds ~83% of every unit in the building (Medicine), so a ring of stock quantities
rendered as a single solid arc with slivers — it carried no information at all. It charts **product
counts** instead, which split 53 / 22 / 6 / 4 / 1 % and are what the panel is actually asking about.
The unit totals stay in the legend beside each row, and the tooltip gives both. If you switch it back
to units, the ring goes blank again.

High Demand uses the **same gradient green as Total Sales per Month** (`C.blue`, the brand emerald)
rather than its own `C.green` — two "this is doing well" charts in two different greens read as two
unrelated scales. Low Demand stays amber as the deliberate contrast.

Related colour choices on that tab: the Lowest Stock bars are **amber against a grey reorder rule**,
not red — those rows are low, not expired, and solid red read as an alarm on every one of them. The
two returns rings use lighter tints of the legend hues with a white arc gap; the deep maroon "Fail to
Return" slice previously swamped both charts.

### The two dashboard tabs own their own panels

`admin/dashboard.blade.php` splits into three includes:

- `_dashboard-sales-lower` — Recent Sales Transactions + Sales Summary. Lives **inside** the Sales
  section. It used to sit outside the switcher, so selecting "Inventory" still left a table of POS
  receipts and a revenue ring on screen.
- `_dashboard-actions` — Quick Actions + Alerts, deliberately **outside** both sections: neither is
  about sales or stock specifically, and burying them behind a tab only makes them harder to reach.
- the Inventory section itself, which now contains nothing sales-derived.

Both tabs use the same `var(--brand)` fill when active; they are mutually exclusive, so a second
shade only made the pair look inconsistent.

**Fixes applied to the admin dashboard do NOT reach staff.** The two views duplicate their charts and
styles rather than sharing them, so every one of these had to be made twice — the staff copies were
still on the old behaviour a round later:

- category ring charting **units** (one solid arc) instead of products
- the palette starting on indigo, so a category was a different colour per account
- Lowest Stock bars in crimson rather than amber
- no centre caption in the ring
- `.staff-body` stretching a short card to its neighbour's height, leaving dead space

When you change a dashboard chart, check whether the other dashboard has its own copy.

**Pair a short card with a tall one and you get a visible void.** This bit three panels in a row, so
the fixes are all the same shape: Quick Actions and Alerts are now **stacked full-width rows** rather
than two columns (Quick Actions is inherently one bar, Alerts inherently a list), Alerts lays out on
`repeat(auto-fit, minmax(260px, 1fr))` so five of them run across instead of down, and the staff
Expiring Soon card wraps **one** scroller around both its sections — two stacked 320px boxes made it
795px tall beside a 337px neighbour. Cap the height, never the row count: all 31 rows are still
there.

**Quick Actions sits directly under the KPI row on BOTH dashboards**, above the Sales/Inventory
switcher on admin. It used to be at the foot of a ~2,000px page — a long scroll to reach the two
panels that say what needs doing. The page footer stays last, outside the partial.

There is **no global search in the topbar**. One was added and removed: it duplicated the search each
list page already has, and those are the ones wired to the live-filter pattern.

**Quick Actions is a ROW on staff, a 2x2 block on admin.** The shared `.quick-actions` rule copied
across is a two-column grid, and being declared later in the staff `<style>` it silently overrode the
row layout that file already had — the staff bar rendered as the admin's block. The staff rules are
scoped to `.quick-card .quick-actions` so they win without touching admin. Watch for this whenever a
class is copied between the two dashboards: they share names but not stylesheets.

Staff also carries its own Expiry Overview and Alerts panels, from the same `$expiryOverview` /
counts the admin tab uses — the person on the floor is the one who acts on them.

`staff/dashboard.blade.php` mirrors the admin design — same `.dash-greeting` bar, `.dash-datepill`,
`.quick-action` tiles and `.dash-footer`. Those rules are **duplicated** in the staff `<style>` block
rather than shared: each dashboard carries its own, and staff does not load the admin view. Keep the
two in step when either changes. Its quick actions are limited to routes a staff account can reach
(no Add Product, no Reports).

### My Profile

Migration `2026_08_19_000002` added the columns this screen shows: `users.phone`, `department`,
`preferred_language`, `last_login_at`, and `audit_trails.ip_address`. They were placeholders before,
which meant the page displayed a phone number and department belonging to nobody — now they are real,
editable by the account holder, and a blank field says "Not set" rather than inventing one.

`last_login_at` is stamped in `AuthenticatedSessionController::store` with `saveQuietly()` — it is
bookkeeping, not a profile edit, so it must not fire model events. `AuditTrail::log()` stamps
`request()?->ip()`, null for anything the scheduler or an artisan command writes (no request, no
address). **`ip_address` had to be added to `AuditTrail::$fillable`**: `create()` silently drops
unrecognised keys, so the column filled with NULLs while `log()` looked perfectly correct.

**Staff may edit only their name and phone number.** Email, department and preferred language are
admin decisions. The inputs are locked in the view *and* the keys are absent from
`ProfileUpdateRequest::rules()` for a non-admin — a disabled input is a hint, not a control, and
anyone can post the field anyway. Verified: a staff request carrying `email`, `department` and
`preferred_language` validates down to `name, phone`.

**Role is rendered locked, and `ProfileUpdateRequest` does not accept it.** Both matter: this form
belongs to the account holder, so accepting a role here would let any account promote itself. Role
changes go through User Management, which is admin-only.

`profile/edit.blade.php` is a three-part layout: identity card, Personal Information form, then
Change Password / Account Activity / Delete Account.

### Dashboard panels and what they may claim

The admin dashboard carries a greeting bar, the KPI row, the Sales/Inventory tab sections, and a
lower strip (`admin/_dashboard-lower.blade.php`): Recent Sales Transactions, Quick Actions,
Alerts & Notifications, Sales Summary. The lower strip sits **outside** the tab switcher on purpose
— it is the "what do I do now" band and stays visible on either tab.

Three places where the design asked for data this app does not record. Each shows the truth instead
of a plausible-looking placeholder; if you add the underlying data, wire it here:

- **KPI deltas** (`<x-kpi-delta>`) render **only when a real baseline exists** — today vs yesterday
  and this week vs last week, both from POS timestamps. The stock counters (low stock, expiring,
  expired, returns) are never snapshotted, so there is no yesterday to compare against and the
  component renders nothing rather than a made-up "↑4.2%". The component returning empty is the
  designed behaviour, not a bug.
- **Recent Sales Transactions** has no Payment Method column and shows every buyer as "Walk-in
  Customer", because `sales` stores neither. Adding them means a migration plus capturing the value
  at checkout. It takes **15** rows, not 6: the panel scrolls inside a capped `.list-scroll` box with
  a sticky header, so extra rows cost no page height — and the cap is what makes it the same height
  as Sales Summary beside it, which was a 540px table against a 241px card.
- **Alerts & Notifications** (the dashboard card) carries no "10m ago" ages — nothing records when a
  condition first appeared.

**The topbar bell is a different thing from that card, and names items.** `AlertService::payload()`
returns two lists: `alerts`, the five kind-level rollups the dashboard card also shows, and `items`,
individual notifications — "NESTOGEN CLASSIC 135G · Expires Aug 21, 2026 · 1 day left", "ARM SLING
LARGE · 0 PCS left · reorder at 5". Three points to keep:

- **`PER_KIND = 3`, a fixed slice per kind, not one urgency-sorted list.** Low stock outnumbers
  everything else by an order of magnitude (645 vs 31), so a global sort buries the two expired
  batches that need pulling off the shelf today under a wall of low stock.
- **The badge counts the listed notifications** (currently 12), not the row totals. It used to count
  alert *kinds*; both readings exist for the same reason, which is that 645 is not a number of things
  to read. A badge that can only ever say "9+" tells you nothing either, so the per-kind totals live
  on the panel's footer links instead ("View all 645 low stock").
- Item bodies are built server-side and rendered with `textContent`, never `innerHTML` — they carry
  product names straight from the catalogue onto every authenticated page.

`SalesHistory::quarterlyRevenue()` feeds the Sales Summary ring off `monthlyRevenue()` (so it
inherits that cache). Its count is labelled **"Sales Records"**, not transactions: each
`sales_history` row is one product sold on one date, not a basket.

### Browser-tab identity

`APP_NAME` shipped as `Laravel`, so every tab read **"Laravel - Dashboard"**. The layout's
`config('app.name', 'REMEDI')` fallback never fired — the key was set, just set wrong — which is why
this survived a full re-theme. It is `REMEDI` in `.env` and `.env.example` now; run
`php artisan config:clear` if the config cache is warm.

The brand assets and `<meta name="theme-color">` are declared in **both**
`layouts/app.blade.php` and `layouts/guest.blade.php`. The guest one matters: the login screen is the
first page anyone sees, and it does not share the authenticated layout, so patching only
`app.blade.php` leaves the front door showing a blank globe.

**The icons had never actually loaded before, and the cause was not the markup.** `config/app.php`
shipped `'asset_url' => env('ASSET_URL', '/')`. A non-null `asset_url` is used verbatim as the root
of every `asset()` call, so `asset('favicon.svg')` returned `/favicon.svg` — an absolute path from
the **host** root. This app is served from a subdirectory (`/remedi.2/remedi/public`), so that
resolved to `http://localhost/favicon.svg` and 404'd. It is `env('ASSET_URL')` now, which is the
framework default and makes `asset()` build from the incoming request; that is correct both under a
subdirectory and at a domain root. Set `ASSET_URL` in `.env` only if assets move to a CDN.

Assets, all in `public/` and all generated from the supplied artwork:

| file | used by |
|---|---|
| `logo.png` | sidebar brand mark (transparent, 512x477) |
| `favicon.ico` | browser tab — 16/32/48/64/128/256 in one file |
| `icon-192.png`, `icon-512.png` | web manifest, Android install |
| `apple-touch-icon.png` | iOS home screen — **opaque**, iOS composites transparency onto black |
| `manifest.json` | install metadata |

Two things to keep in mind if you regenerate them:
- **The source art is not square** (1026x956 after trimming) and carries a baked-in glow halo in its
  alpha. Trim at `alpha >= 80` to drop the halo — at `>= 1` the bounding box is 400px wider and the
  glow shows as green haze on a light background. The sidebar `<img>` needs `object-fit: contain`
  for the same reason; a square box without it stretches the capsule.
- **`manifest.json`, not `site.webmanifest`.** Apache under XAMPP has no MIME type registered for
  `.webmanifest` and served it with none at all, which is enough for Chrome to reject the manifest
  and drop installability. It already knows `.json`.

### Theme: one palette, defined once

**There are TWO skeletons, and both must track the dashboard's shape.**

`layouts/guest.blade.php` carries its own full-screen `.auth-skeleton`, shown by adding
`is-authenticating` to `<body>` on submit. It has to exist separately because the guest layout has no
`.content-body`, so the app's navigation skeleton never applies there — and signing in is the slowest
transition in the app. Its handler is guarded on `form.checkValidity()` and `e.defaultPrevented`, so
a blocked or cancelled submit does not blank the fields the user still has to fill in.

Both skeletons mirror the dashboard: dark teal sidebar (`#0c3b33`, matching `--nav-bg`), a greeting
bar with a date pill, **six** 118px KPI tiles on `.kpi-grid`'s auto-fit track, tab pills, and two
side-by-side 280px charts. When the dashboard's proportions change, change them here too — a
skeleton that no longer matches is worse than none, because the page visibly jumps when it is
replaced.

The loading skeleton is **neutral grey on purpose**. A brand-tinted version was tried and read as a
coloured panel in its own right rather than a placeholder — a skeleton should recede, not compete
with the content it stands in for. Its shape mirrors the dashboard (greeting bar, six KPI tiles, two
side-by-side charts) so the real page lands in roughly the same places instead of jumping.

The **sidebar is a dark slab** (`--nav-bg` #0c3b33 and friends), separate tokens from `--ink` so the
nav can be retinted without touching body text. It was briefly white; the reference designs put a
dark rail beside the light content area, and the active item is a solid `--brand` pill on it.

The loading skeleton (`.sk-block`) shimmers in `--brand-soft` → `--brand-tint`, not the old slate
grey. It is the first thing shown after login and on every navigation, so a cold grey read as a
different, unstyled app for the second before the real page arrived.

`layouts/app.blade.php` opens with a `:root` block (`--brand`, `--brand-dark`, `--brand-darker`,
`--brand-tint`, `--brand-soft`, plus `--ink`, `--ink-soft`, `--line`, `--surface`). The app was
indigo on a dark slate sidebar and is now **emerald on white**; every button, focus ring, active nav
pill and pagination state refers to those variables, so a re-theme is a change to that block rather
than a hunt through ~40 hardcoded hex codes. Don't reintroduce raw brand hexes in page-level CSS.

The sidebar is white with a `--line` right border; the active item is a `--brand-tint` pill with
`--brand-darker` text and a `--brand` icon. KPI cards lead with a **42px circular icon chip** whose
disc is `color-mix(in srgb, var(--kpi-accent) 14%, #fff)` and whose glyph is the accent itself — one
variable per card drives both, with an `@supports not (color-mix…)` fallback so the chip never
renders as a transparent hole on older engines.

### Frontend
Blade + Alpine.js. **No view references `@vite`** — `layouts/app.blade.php` carries a large inline
`<style>` block, and Chart.js and Tabler icons come from CDNs. The Tailwind/PostCSS/Vite toolchain in
`package.json` is inherited from the Breeze scaffold and is currently inert. Add styles to the
existing inline block or a plain stylesheet; adding a Tailwind class will do nothing until Vite is
actually wired in.

**A Tabler class that isn't in the build renders as an empty box, silently.** The CDN is pinned to
`@tabler/icons-webfont@latest`, which does not carry every name in Tabler's catalogue: `ti-boxes`
was blank on the staff dashboard's "View Inventory" tile for exactly this reason, while `ti-box`
next to it worked. There is no error and the `<i>` still takes up space, so it reads as a design
gap rather than a missing glyph. To check a name before shipping it, read the pseudo-element:
`getComputedStyle(el, '::before').content` returns `none` for a class the build does not define and
a PUA character (e.g. `U+EA45`) for one it does.

**Navigation skeleton:** every page is a full server render, so between a sidebar click and the new
document the browser paints nothing — on the heavier pages that reads as a dead click. A delegated
handler in `layouts/app.blade.php` marks the clicked link `.is-loading` (highlight only — a spinner
on the nav item was tried and removed by request) and puts `.is-navigating` on `.content-body`,
which hides every child except `.page-skeleton`. `showNavigating()` reads `offsetHeight` to force a
paint, since the browser may otherwise skip repainting the outgoing document.

**A second click supersedes the first.** Click one nav item, then another before the first page
arrives, and the browser lands you on the second — a later navigation wins. `showNavigating()` clears
any existing `.is-loading` before marking the new item, so only the most recent click reads as
loading; it used to just add, leaving two items lit up with no way to tell which page was coming. It
also captures the outgoing `.active` set **only on the first click** of a navigation (guarded by
`navigating`): the first call already stripped those classes, so re-reading on the second click
stored an empty list and `clearNavigating()` had nothing to restore — which stranded the sidebar with
no highlight at all after a double click, and on every bfcache restore after one.

It also retitles the topbar `<h2>` to the destination's label and strips `.active` off the outgoing
tab — otherwise the whole chrome keeps insisting you're still on the page you just left (click
Dashboard from POS and the header still read "Point of Sale", with two tabs highlighted).
`clearNavigating()` restores the title and the `.active` classes, which matters on bfcache restore:
without it, hitting Back leaves the header naming the page you never reached. The incoming document
tears it all down; `pageshow`/`persisted` triggers the restore. The handler deliberately
ignores modified/middle clicks, `target=_blank`, `download`, `#`/`mailto:`, cross-origin hrefs, and
same-URL clicks — if you touch it, keep those guards.

**AJAX partial pattern:** list controllers (POS, inventory, products, sales, forecast) check
`$request->wantsJson() || $request->ajax()` and return
`['html' => view('x._rows', ...)->render(), 'pagination' => (string) $paginator->links()]`. The
`_rows.blade.php` / `_grid.blade.php` partials are the shared row markup between the full page render
and the AJAX refresh — edit the partial, not a copy inside `index.blade.php`.

`InventoryController` fetches all matching products, filters status in PHP (low stock / expiring /
expired / return states are computed accessors, not columns), then paginates manually with
`LengthAwarePaginator`. Category and text search *are* pushed down to SQL.

## Cruft — removed

The scratch copies, dead experiments and stray files this section used to list have been **deleted**
(68 MB, 8,672 files). Each was checked against every live source file first, and only the ones with
no reference at all were removed:

| removed | was |
|---|---|
| `temp-laravel12-reference/` | a whole pristine Laravel 12 skeleton (8,641 files, 64 MB) kept for comparison |
| `database/seed/` | scratch copies of seeders/migrations, incl. a literal `ProductSeeder (1).php` |
| `resources/views/resources/` | stale duplicate of `resources/views/reports/`, no `view()` call anywhere |
| `python_forecast/`, `forecast_service/` | earlier standalone forecasting experiments |
| `app/Console/Commands/GenerateSalesForecast.py` | stray copy of `resources/python/generate_sales_forecast.py` |
| `*-snippet.php` (Http/Console `Kernel`, `routes/web`) | paste-buffer leftovers |
| `test_arima_metrics.py` | standalone experiment; needs pmdarima/sklearn, which are not installed |
| root `requirements.txt`, `3.1.0` | both **zero bytes** — the real deps are `resources/python/requirements.txt` |
| root `Inventory_Master_Seed.csv` (+`.bak`) | duplicate of `database/data/inventory_seeder.csv` |
| three stray images at the repo root | incl. the logo source, now built into `public/logo.png` and the icons |
| `Sales_Records_3Year.csv` (+`.xlsx`), `Sales_Records_5Year.csv` | 43 MB of earlier drafts of the sales history, referenced only by this document |
| `Product_Master (1).csv` (+`.bak`), `inventory_seeder.csv.bak` | leftover product-master exports and the classifier's rollback copy |

**Kept, despite looking like cruft:**
- `Transaction_Records_Seed.csv` at the root — `InventoryReceiptSeeder` reads it via `base_path()`.
  Deleting it silently empties `inventory_receipts`.

**This repo is not under version control**, so a deletion here is final. Check for references before
removing anything — and note that a bare-substring grep is not enough: `3.1.0` "matches"
`package.json` (it is Tailwind's version), `GenerateSalesForecast.py` "matches" the PHP command class
of the same name, and `database/seed` matches the word "seed" almost everywhere.

`.claude/launch.json` defines a `dev` preview target that runs `npm run dev` on port 5173. That
serves nothing usable here, because no view loads the Vite bundle (see "Frontend"); the app is
served by XAMPP from `public/`, so preview that URL instead.
