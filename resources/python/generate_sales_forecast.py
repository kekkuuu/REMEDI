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
statsmodels' optimizer. The units ARE generate_forecasts.py's demand forecast
(forecast_product); this script adds revenue at the current selling price.
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

import pandas as pd

warnings.filterwarnings("ignore")

import generate_forecasts as gf

# ONE MODEL, NOT TWO (2026-10-05, at the user's request: "make them match, and
# also the revenue"). This script used to carry its own copy of the SARIMA
# fit, kept "in step" with generate_forecasts.py by hand. Fitted separately,
# the two disagreed: 353 of 15,804 product-months came out a unit or more
# apart, and units here were stored to two decimals while demand is whole
# units, so the Forecasting page's store-wide chart (this table) never matched
# the demand forecast or the terminal report (Nov 2026: 69,707 vs 69,500).
# Units now come from generate_forecasts.forecast_product() itself -- the same
# order selection, transform, clamping and whole-unit rounding -- and revenue
# is those units x the product's current selling price, rounded to centavos.
# Change the model in generate_forecasts.py; this script follows it.

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
    Forecast one product's units: generate_forecasts.forecast_product(), the
    demand forecast itself, so the two tables hold the same numbers.
    """
    return gf.forecast_product(series, horizon)


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
            confidence = "high" if row["method"] in ("sarima", "arima") else "low"
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
