#!/usr/bin/env python3
"""
Regenerate sales forecasts (units AND revenue) for every product, from the
sales_history data (actual point-of-sale demand) rather than
inventory_receipts (purchasing). This is the counterpart to
generate_forecasts.py: that script answers "how much should we buy?" from
purchase history; this one answers "how much will we sell, and for how
much revenue?" from actual sales history.

Two data sources:
  --source=csv   Bootstrap from a sales_history-shaped CSV
                 (columns: product_sku, sale_date, quantity_sold) plus a
                 product price CSV (--price-csv, e.g. inventory_seeder.csv
                 with a "SKU / Barcode" and "Selling Price" column) used to
                 convert unit forecasts into revenue forecasts.

  --source=mysql Read historical daily sales from the app's own database
                 (the `sales_history` table), joined against `products` for
                 the current selling_price (credentials read from
                 --env-path).

Output: a CSV with columns product_sku, forecast_date, forecast_units,
forecast_revenue, lower_ci_units, upper_ci_units, lower_ci_revenue,
upper_ci_revenue, method, confidence, generated_at -- matching the
sales_forecasts table.

Products are fitted in parallel across CPU cores (see --workers), using
worker processes rather than threads because the cost is CPU-bound inside
statsmodels' optimizer. Kept deliberately in step with generate_forecasts.py.
"""

import os

# Must happen before numpy is imported -- see the identical block in
# generate_forecasts.py for why single-threaded BLAS is required once the
# work is spread over worker processes.
for _blas_var in (
    "OMP_NUM_THREADS",
    "OPENBLAS_NUM_THREADS",
    "MKL_NUM_THREADS",
    "NUMEXPR_NUM_THREADS",
    "VECLIB_MAXIMUM_THREADS",
):
    os.environ.setdefault(_blas_var, "1")

import argparse
import time
import warnings
from concurrent.futures import ProcessPoolExecutor
from datetime import datetime

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

# Kept in step with generate_forecasts.py: every product is forecast with a
# SARIMA(p,d,q)(P,D,Q,s) model -- never a different model family
# (Holt-Winters, plain ARIMA-as-a-separate-method and a moving average were
# removed, at the user's explicit request, and are not coming back). The
# ORDER is picked per product from SARIMA_CANDIDATES by holdout accuracy
# (see _pick_sarima_order in forecast_series below) rather than forcing the
# same order onto every series -- SARIMA(0,1,1)(0,1,1,12), the "airline
# model" REMEDI.md measured as the best SINGLE specification, needs a full
# seasonal cycle to estimate its seasonal MA term at all, which roughly half
# this catalogue does not have. The last two candidates are the same
# equation with P=D=Q=0 and s dropped, i.e. plain ARIMA(p,d,q), offered
# because a short or irregular series can fail a seasonal fit outright.
# SEASONAL + NON-SEASONAL COMPETING, WITH A LEVEL GUARD, as of 2026-09-29 -- kept
# in step with generate_forecasts.py, which has the measurements and history.
SEASONAL_CANDIDATES = [
    ((0, 1, 1), (0, 1, 1, 12)),
    ((1, 1, 1), (0, 1, 1, 12)),
]
NONSEASONAL_CANDIDATES = [
    ((1, 1, 1), (0, 0, 0, 0)),
    ((0, 1, 1), (0, 0, 0, 0)),
]
SARIMA_CANDIDATES = SEASONAL_CANDIDATES + NONSEASONAL_CANDIDATES
SEASONAL_LEVEL_GUARD = 0.5
SARIMA_SELECTION_HOLDOUT = 3
# Rolling windows the order is chosen over -- keep in step with generate_forecasts.py.
SELECTION_FOLDS = 3
MIN_MONTHS_FOR_ANY_FORECAST = 3

TASK_CHUNK_SIZE = 8


def load_from_csv(sales_path: str, price_path: str | None) -> tuple[pd.DataFrame, dict]:
    df = pd.read_csv(sales_path, dtype={"product_sku": str})
    df["date"] = pd.to_datetime(df["sale_date"])
    df = df.rename(columns={"quantity_sold": "qty"})[["date", "product_sku", "qty"]]

    prices: dict[str, float] = {}
    if price_path:
        price_df = pd.read_csv(price_path, dtype={"SKU / Barcode": str})
        prices = dict(zip(price_df["SKU / Barcode"], price_df["Selling Price"]))
    return df, prices


def db_credentials(env_path: str | None) -> dict:
    """Database credentials from a .env FILE, the process ENVIRONMENT, or both.

    The file wins where it exists, so a local run against XAMPP is unchanged.
    Anything it does not define falls back to the environment, which is the
    only thing a container host provides: there is no .env inside the image,
    and baking one in would put the password in a layer.
    """
    env = {}

    if env_path and os.path.exists(env_path):
        with open(env_path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                env[key.strip()] = value.strip().strip('"').strip("'")

    for key in ("DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD", "DB_DATABASE"):
        if not env.get(key) and os.environ.get(key):
            env[key] = os.environ[key]

    missing = [k for k in ("DB_HOST", "DB_DATABASE") if not env.get(k)]

    if missing:
        raise SystemExit(
            "No database credentials: " + ", ".join(missing) + " not found in "
            + (env_path or "any --env-path") + " or in the environment."
        )

    return env


def load_from_mysql(env_path: str | None, include_pos: bool = False) -> tuple[pd.DataFrame, dict]:
    import pymysql

    env = db_credentials(env_path)

    conn = pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME"),
        password=env.get("DB_PASSWORD"),
        database=env.get("DB_DATABASE"),
    )
    # BOTH RECORDS, not just the imported one.
    #
    # `sales_history` stops the day before this terminal went live, so reading
    # it alone means the newest month the model ever sees is the month of the
    # handoff -- and since monthly_series() drops an incomplete trailing month,
    # training ended in JULY while the shop had been trading through the till
    # into September. The horizon then opened on a month that had already
    # happened, which is the "forecasting the past" fault REMEDI.md records:
    # every product forecasting a window nobody can act on.
    #
    # UNION ALL, not a join: these are two records of the same event stream, and
    # monthly_series() aggregates by (product, month) anyway, so duplicate
    # (sku, date) pairs across the two sources sum exactly as they should.
    # Joined through products.sku, the only place sale_items.product_id and
    # sales_history.product_sku meet.
    # The till's branch is opt-in (--include-pos) -- see generate_forecasts.py.
    query = """
        SELECT sale_date AS date, product_sku, quantity_sold AS qty
        FROM sales_history
        WHERE product_sku NOT IN (
            SELECT sku FROM products WHERE archived_at IS NOT NULL
        )
    """
    if include_pos:
        query += """
        UNION ALL

        SELECT DATE(sales.created_at) AS date, products.sku AS product_sku,
               sale_items.quantity AS qty
        FROM sale_items
        JOIN sales ON sales.id = sale_items.sale_id
        JOIN products ON products.id = sale_items.product_id
        WHERE products.archived_at IS NULL
          -- A voided sale was reversed and restocked: it is not demand.
          AND sales.payment_voided = 0
        """
    sales_df = pd.read_sql(query, conn)
    price_df = pd.read_sql("SELECT sku, selling_price FROM products", conn)
    conn.close()

    sales_df["date"] = pd.to_datetime(sales_df["date"])
    prices = dict(zip(price_df["sku"], price_df["selling_price"]))
    return sales_df, prices


def monthly_series(df: pd.DataFrame) -> pd.DataFrame:
    df = df.copy()
    df["month"] = df["date"].dt.to_period("M").dt.to_timestamp()

    # Drop the trailing month when the data stops partway through it.
    #
    # These models are fitted on calendar months, so a month holding only the
    # first fortnight is not a weak month -- it is half a month. Fed in as a
    # real observation it reads as a collapse, and every method in the cascade
    # then forecasts forward from that depressed level. Measured after the
    # seeded history was trimmed to 2026-08-15: Jun 42,649 units, Jul 46,477,
    # Aug 22,239. Nothing happened in August except the calendar.
    #
    # Judged on the GLOBAL last date rather than per product: an incomplete
    # tail is a property of when the data stops, not of whether one product
    # happened to sell on the final day. Doing it per product would silently
    # drop a real final month for everything that did not sell that day.
    #
    # This also matters for any month-to-date run, trimmed history or not --
    # regenerating on the 3rd of a month would otherwise train on three days.
    last_date = df["date"].max()

    if pd.notna(last_date):
        month_end = last_date.to_period("M").end_time.date()

        if last_date.date() != month_end:
            df = df[df["month"] < last_date.to_period("M").to_timestamp()]

    return df.groupby(["product_sku", "month"], as_index=False)["qty"].sum()


def forecast_series(series: pd.Series, horizon: int):
    """
    Forecast one product with a SARIMA model -- always SARIMA, but the ORDER
    is picked per product from SARIMA_CANDIDATES by holdout accuracy, the
    same approach as generate_forecasts.py::forecast_product (kept in sync
    deliberately so unit and demand forecasts behave consistently): seasonal
    and non-seasonal orders COMPETE per product, then the level guard refits
    from NONSEASONAL_CANDIDATES alone when the forecast averages more than
    SEASONAL_LEVEL_GUARD from the last three months. No model outside the
    SARIMA family is fit.
    """
    rows = _forecast_series_with(series, horizon, SARIMA_CANDIDATES)

    recent = float(series.asfreq("MS", fill_value=0).iloc[-3:].mean())
    if rows and recent > 0:
        level = float(np.mean([r["forecast_value"] for r in rows]))
        if abs(level - recent) / recent > SEASONAL_LEVEL_GUARD:
            return _forecast_series_with(series, horizon, NONSEASONAL_CANDIDATES) or rows

    return rows


def _forecast_series_with(series: pd.Series, horizon: int, candidates):
    """forecast_series()'s fit, choosing among `candidates` only."""
    series = series.asfreq("MS", fill_value=0)
    n = len(series)
    last_date = series.index[-1]
    future_dates = pd.date_range(last_date + pd.offsets.MonthBegin(1), periods=horizon, freq="MS")

    if n < MIN_MONTHS_FOR_ANY_FORECAST:
        return []

    hist_max = float(series.max())
    hist_mean = float(series.mean())
    ceiling = max(hist_max * 2.5, hist_mean * 4, 1.0)

    def clamp(rows):
        for r in rows:
            r["forecast_value"] = min(max(0.0, r["forecast_value"]), ceiling)
            # Bound BOTH ends of each CI to [0, ceiling]. A near-singular covariance
            # matrix from a non-converged SARIMA fit can produce a huge or wildly
            # negative bound that is still a finite float (not NaN/inf), so it
            # would otherwise slip past a NaN-only check and later overflow the
            # DB's decimal column once multiplied by price.
            upper = r["upper_ci"]
            r["upper_ci"] = ceiling if np.isnan(upper) else min(max(0.0, upper), ceiling)
            lower = r["lower_ci"]
            r["lower_ci"] = 0.0 if np.isnan(lower) else min(max(0.0, lower), r["forecast_value"])
        return rows

    def plausible(rows):
        """
        Reject a fit instead of repairing it -- see generate_forecasts.py.
        A model that wants to predict negative sales has failed to fit, and
        clamping that to 0 launders the failure into a confident zero.
        """
        vals = np.asarray([r["forecast_value"] for r in rows], dtype=float)
        bounds = np.asarray(
            [r["lower_ci"] for r in rows] + [r["upper_ci"] for r in rows], dtype=float
        )

        if vals.size == 0 or not np.all(np.isfinite(vals)):
            return False
        if (vals < 0).any():
            return False
        # CIs may legitimately be NaN; only finite ones have to be sane.
        finite = bounds[np.isfinite(bounds)]
        if finite.size and (finite < -ceiling).any():
            return False

        return bool((vals <= ceiling).all())

    def sarimax_rows(order, seasonal_order, fit_series=None, fit_dates=None, fit_horizon=None):
        from statsmodels.tsa.statespace.sarimax import SARIMAX

        fit_series = series if fit_series is None else fit_series
        fit_dates = future_dates if fit_dates is None else fit_dates
        fit_horizon = horizon if fit_horizon is None else fit_horizon

        # Seasonal orders fit with stationarity/invertibility ENFORCED, and a
        # degenerate fit is a failed fit -- see generate_forecasts.py
        # _sarimax_rows (2026-09-29) for the diverged fit this prevents.
        seasonal = len(seasonal_order) == 4 and seasonal_order[3] > 0
        model = SARIMAX(
            fit_series, order=order, seasonal_order=seasonal_order,
            enforce_stationarity=seasonal, enforce_invertibility=seasonal,
        )
        with warnings.catch_warnings():
            # A locally-scoped filter is needed here: statsmodels re-registers
            # its own ConvergenceWarning filter on import, which otherwise wins
            # over the blanket warnings.filterwarnings("ignore") at module load.
            warnings.simplefilter("ignore")
            fit = model.fit(disp=False, maxiter=200, method="powell")

        pred = fit.get_forecast(steps=fit_horizon)
        ci = pred.conf_int(alpha=0.2)

        if not np.all(np.isfinite(ci.values)) or not float(fit.params.get("sigma2", 1.0)) > 0:
            raise ValueError("degenerate SARIMA fit")

        return [
            {
                "forecast_date": date,
                "forecast_value": round(float(m), 2),
                "lower_ci": round(float(lo), 2) if not np.isnan(lo) else 0.0,
                "upper_ci": round(float(hi), 2) if not np.isnan(hi) else float("nan"),
                "method": "sarima",
            }
            for date, m, (lo, hi) in zip(fit_dates, pred.predicted_mean, ci.values)
        ]

    def pick_order():
        """
        Best SARIMA order for THIS product: lowest AVERAGE error over up to
        SELECTION_FOLDS rolling 3-month windows, MAE-first -- the same rule as
        generate_forecasts.py::_pick_sarima_order (see there for why one
        window was not enough). Plausible in every window, or disqualified.
        """
        h = SARIMA_SELECTION_HOLDOUT
        folds = []
        for k in range(1, SELECTION_FOLDS + 1):
            cut = n - h * k
            if cut < MIN_MONTHS_FOR_ANY_FORECAST:
                break
            folds.append((series.iloc[:cut], series.iloc[cut:cut + h].to_numpy(dtype=float)))
        if not folds:
            return None

        best = None
        for order, seasonal_order in candidates:
            maes, smapes = [], []
            for train, observed in folds:
                dates = pd.date_range(train.index[-1] + pd.offsets.MonthBegin(1), periods=h, freq="MS")
                try:
                    rows = sarimax_rows(order, seasonal_order, train, dates, h)
                except Exception:
                    rows = None

                if not rows or not plausible(rows):
                    maes = None
                    break

                pred = np.array([min(max(0.0, r["forecast_value"]), ceiling) for r in rows[:len(observed)]], dtype=float)
                err = pred - observed[:len(pred)]
                maes.append(float(np.mean(np.abs(err))))
                denom = np.abs(pred) + np.abs(observed[:len(pred)])
                smapes.append(float(np.mean(np.where(denom == 0, 0.0, np.abs(err) / np.where(denom == 0, 1.0, denom))) * 200.0))

            if not maes:
                continue

            score = (float(np.mean(maes)), float(np.mean(smapes)))
            if best is None or score < best[1]:
                best = ((order, seasonal_order), score)

        return best[0] if best else None

    winner = pick_order()
    orders_to_try = [winner] if winner else []
    orders_to_try += [o for o in candidates if o != winner]

    for order, seasonal_order in orders_to_try:
        try:
            rows = sarimax_rows(order, seasonal_order)
        except Exception:  # noqa: BLE001 - any fitting failure means "try the next order"
            continue

        if rows and plausible(rows):
            return clamp(rows)

    return []


def _forecast_task(task):
    """
    Worker entry point -- module level so it pickles to spawned workers.
    Swallows per-product failures so one bad series can't abort the pool
    mid-catalog; the caller reports them in a summary.
    """
    sku, series, horizon = task
    try:
        return sku, forecast_series(series, horizon), None
    except Exception as exc:  # noqa: BLE001 - deliberately broad, see docstring
        return sku, [], f"{type(exc).__name__}: {exc}"


def resolve_workers(requested: int) -> int:
    """0 = auto (all cores but one, so the box stays responsive); 1 = sequential."""
    if requested and requested > 0:
        return max(1, requested)

    return max(1, (os.cpu_count() or 2) - 1)


def iter_forecasts(tasks, workers):
    """Yield (sku, rows, error) in input order, so the CSV stays deterministic."""
    if workers == 1:
        yield from map(_forecast_task, tasks)
        return

    with ProcessPoolExecutor(max_workers=workers) as pool:
        yield from pool.map(_forecast_task, tasks, chunksize=TASK_CHUNK_SIZE)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", choices=["csv", "mysql"], required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--horizon", type=int, default=6)
    parser.add_argument("--env-path", default=None)
    parser.add_argument("--include-pos", action="store_true",
                        help="also train on the till's own (non-voided) sales")
    parser.add_argument("--sales-csv", default=None, help="sales_history-shaped CSV (product_sku, sale_date, quantity_sold)")
    parser.add_argument("--price-csv", default=None, help="product master CSV with SKU / Barcode + Selling Price, for revenue conversion")
    parser.add_argument(
        "--workers",
        type=int,
        default=0,
        help="Worker processes for model fitting. 0 = auto (all cores but one), 1 = sequential.",
    )
    args = parser.parse_args()

    if args.source == "csv":
        if not args.sales_csv:
            raise SystemExit("--sales-csv is required for --source=csv")
        raw, prices = load_from_csv(args.sales_csv, args.price_csv)
    else:
        # No --env-path is fine on a server: db_credentials() falls back to
        # the process environment, and says what is missing if neither has
        # it -- same as generate_forecasts.py. This script used to gate on
        # --env-path unconditionally here, which is dead code in the only
        # in-repo caller (GenerateSalesForecast.php always passes one) but
        # would reject a direct/container invocation supplying credentials
        # purely via environment variables with a misleading "required"
        # error instead of db_credentials()'s more useful missing-key one.
        raw, prices = load_from_mysql(args.env_path, args.include_pos)

    if raw.empty:
        raise SystemExit("No historical rows parsed from the given source(s).")

    monthly = monthly_series(raw)
    generated_at = datetime.now().isoformat(sep=" ", timespec="seconds")

    products = list(monthly.groupby("product_sku"))
    total_products = len(products)
    workers = resolve_workers(args.workers)
    print(
        f"Forecasting {total_products} products across {workers} worker "
        f"process{'es' if workers > 1 else ''}...",
        flush=True,
    )

    # Every product must forecast the SAME forward window.
    #
    # Each series used to end at that product's own last month with a sale, and
    # the horizon is generated from `series.index[-1]` -- so a product that
    # stopped selling a year ago got a "forecast" covering months that have
    # already happened. Measured before this fix: 320 of 2,517 products had a
    # horizon entirely in the past, and there were 39 different horizon start
    # months across the catalogue. ACICLOVIR 800MG last sold in 2025-10 and was
    # forecast for 2025-11..2026-04 -- drawn on its chart as a dashed line and
    # confidence band sitting in the middle of the history, with nothing ahead
    # of today at all.
    #
    # Padding to the shared end with zeros fixes it, and the zeros are honest:
    # a month with no sales is a month that sold none. Same reasoning as
    # DemandForecastService::fillMissingMonths() on the PHP side, which had to
    # be taught this separately for the chart's actual series.
    series_end = monthly["month"].max()

    def _padded_series(group):
        s = group.set_index("month")["qty"].sort_index()

        return s.reindex(pd.date_range(s.index.min(), series_end, freq="MS"), fill_value=0)

    tasks = [
        (sku, _padded_series(group), args.horizon)
        for sku, group in products
    ]

    output_rows = []
    failures = []
    # See generate_forecasts.py's identical variable for the full reasoning:
    # rows == [] with no exception is a rejected/unfittable series, not a
    # crash, so it never reaches `failures` and is invisible in the output
    # CSV -- and a product that forecast fine last run landing here this run
    # has its old rows swept as stale by importCsv() with nothing anywhere
    # explaining why. Tracked separately since a nonzero count is often the
    # ordinary "too little history" case, not a fault.
    empty_no_error = []
    start_time = time.monotonic()
    progress_every = max(1, total_products // 20)

    for i, (sku, rows, error) in enumerate(iter_forecasts(tasks, workers), start=1):
        if error:
            failures.append((sku, error))
        elif not rows:
            empty_no_error.append(sku)

        # Price lookup stays in the parent: it's a dict hit, not worth
        # shipping the whole price table to every worker process.
        price = float(prices.get(sku, 0) or 0)
        for row in rows:
            confidence = "high" if row["method"] == "sarima" else "low"
            output_rows.append({
                "product_sku": sku,
                "forecast_date": row["forecast_date"].strftime("%Y-%m-%d"),
                "forecast_units": row["forecast_value"],
                "lower_ci_units": row["lower_ci"],
                "upper_ci_units": row["upper_ci"],
                "forecast_revenue": round(row["forecast_value"] * price, 2),
                "lower_ci_revenue": round(row["lower_ci"] * price, 2),
                "upper_ci_revenue": round(row["upper_ci"] * price, 2),
                "method": row["method"],
                "confidence": confidence,
                "generated_at": generated_at,
            })

        if i % progress_every == 0 or i == total_products:
            elapsed = time.monotonic() - start_time
            rate = i / elapsed if elapsed > 0 else 0
            remaining = (total_products - i) / rate if rate > 0 else 0
            print(
                f"[{i}/{total_products}] {100 * i / total_products:5.1f}%  "
                f"elapsed {elapsed:6.0f}s  ETA {remaining:6.0f}s",
                flush=True,
            )

    # Products that have never sold (2026-10-03, at the user's request: "a
    # forecast to all 2,638 products") -- kept in step with
    # generate_forecasts.py: nothing for SARIMA to fit, so 0 units and PHP 0
    # over the same window, carried as method "no_history". `prices` holds
    # every product in the catalogue, archived ones included.
    if args.source == "mysql":
        forecast_skus = {sku for sku, _ in products}
        never_sold = [sku for sku in prices if sku and sku not in forecast_skus]
        for sku in never_sold:
            for d in pd.date_range(series_end + pd.offsets.MonthBegin(1), periods=args.horizon, freq="MS"):
                output_rows.append({
                    "product_sku": sku,
                    "forecast_date": d.strftime("%Y-%m-%d"),
                    "forecast_units": 0.0, "lower_ci_units": 0.0, "upper_ci_units": 0.0,
                    "forecast_revenue": 0.0, "lower_ci_revenue": 0.0, "upper_ci_revenue": 0.0,
                    "method": "no_history",
                    "confidence": "none",
                    "generated_at": generated_at,
                })
        print(f"No sales history: {len(never_sold)} product(s) given a zero forecast.", flush=True)

    out_df = pd.DataFrame(output_rows)
    os.makedirs(os.path.dirname(args.output), exist_ok=True)
    out_df.to_csv(args.output, index=False)
    total_elapsed = time.monotonic() - start_time
    print(
        f"Wrote {len(out_df)} sales forecast rows for {out_df['product_sku'].nunique() if len(out_df) else 0} "
        f"products to {args.output} in {total_elapsed:.0f}s"
    )

    if failures:
        print(f"WARNING: {len(failures)} product(s) failed to forecast and were skipped:", flush=True)
        for sku, error in failures[:10]:
            print(f"  - {sku}: {error}", flush=True)
        if len(failures) > 10:
            print(f"  ... and {len(failures) - 10} more", flush=True)

    if empty_no_error:
        print(
            f"NOTE: {len(empty_no_error)} product(s) produced no forecast rows this run "
            "with no error raised (too little history, or every SARIMA order was rejected "
            "as implausible). Not necessarily a problem -- but if a product that forecast "
            "last run appears here, its previous rows are about to be swept as stale.",
            flush=True,
        )


if __name__ == "__main__":
    main()
