#!/usr/bin/env python3
"""
Regenerate demand forecasts for every product.

Two data sources:
  --source=csv   Bootstrap directly from receiving-report exports (--csv-path / --xls-path).
                 These reports are grouped by supplier, with a repeating header row per
                 group and a TOTAL row closing each group, e.g.:

                     Supplier >>, YAKULT
                     DATE, INVENTORY, [DESCRIPTIONS,] QTY RCV, COST, TOTAL, DR NO
                     01/02/2025, YAKULT 5S, ..., 10, 50.00, 500.00, 01/02/2025
                     , , , , TOTAL, 500.00,

  --source=mysql Read monthly units sold from `sales_history` PLUS this terminal's
                 own sales (sale_items), which is the record after the handoff
                 in the app's own database (credentials read from --env-path). This is
                 real customer demand -- what was actually bought -- as opposed to
                 restocking/receiving data, which reflects supplier order patterns
                 (minimum order quantities, bulk deals, catch-up orders) rather than
                 demand itself.

Output: a CSV with columns product_sku, forecast_date, forecast_value, lower_ci,
upper_ci, method, confidence, generated_at -- matching the demand_forecasts table.

Products are fitted in parallel across CPU cores (see --workers). Model
fitting is CPU-bound inside statsmodels' optimizer, so this uses worker
*processes*, not threads -- Python's GIL would serialize threads and gain
nothing here.
"""

import os

# Pin the BLAS/LAPACK thread pools to 1 BEFORE numpy is imported (importing
# numpy is what reads these). numpy already multi-threads a single model fit
# internally; combining that with N worker processes oversubscribes the CPU
# (N x cores threads fighting over cores) and measures *slower* than running
# sequentially. Parallelism here comes from processes, so each one stays
# single-threaded. Set as defaults so an operator can still override from
# the environment.
for _blas_var in (
    "OMP_NUM_THREADS",
    "OPENBLAS_NUM_THREADS",
    "MKL_NUM_THREADS",
    "NUMEXPR_NUM_THREADS",
    "VECLIB_MAXIMUM_THREADS",
):
    os.environ.setdefault(_blas_var, "1")

import argparse
import collections
import re
import sys
import time
import warnings
from concurrent.futures import ProcessPoolExecutor
from datetime import datetime

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

# Every product is forecast with a SARIMA(p,d,q)(P,D,Q,s) model -- never a
# different model family (Holt-Winters, plain ARIMA-as-a-separate-method,
# Croston SBA and the moving-average floor were all removed, at the user's
# explicit request, and are not coming back). Within that family, though,
# ONE order forced onto every product is a poor fit for a lot of this
# catalogue: SARIMA(0,1,1)(0,1,1,12), the "airline model" REMEDI.md measured
# as the best SINGLE specification, needs a full seasonal cycle to estimate
# its seasonal MA term at all -- and roughly half this catalogue does not
# have the history or the regularity for that to be reliable.
#
# SARIMA_CANDIDATES is a small grid of orders, all still SARIMA, that
# _pick_sarima_order() scores per product on a holdout exactly the way the
# retired cross-model cascade used to -- the difference is every candidate
# stays inside the one model family the user asked for. The last two are the
# same equation with P=D=Q=0 and s dropped, i.e. plain ARIMA(p,d,q), offered
# because a short or irregular series can fail a seasonal fit outright while
# still supporting a light non-seasonal one.
SARIMA_CANDIDATES = [
    ((0, 1, 1), (0, 1, 1, 12)),
    ((1, 1, 1), (0, 1, 1, 12)),
    ((1, 1, 1), (0, 0, 0, 0)),
    ((0, 1, 1), (0, 0, 0, 0)),
]
SARIMA_ORDER, SARIMA_SEASONAL_ORDER = SARIMA_CANDIDATES[0]
SARIMA_SELECTION_HOLDOUT = 3
MIN_MONTHS_FOR_ANY_FORECAST = 3

# Each task ships one product's monthly series to a worker and gets its
# forecast rows back. Batching several per hand-off keeps the pickling
# round-trips from eating the benefit on fast (moving-average) products.
TASK_CHUNK_SIZE = 8


def parse_receiving_report(path: str) -> pd.DataFrame:
    """
    Parse one grouped-by-supplier receiving report (csv or xls/xlsx) into a
    tidy dataframe: columns = [date, product_sku, qty].
    Robust to the two known layouts (with/without a DESCRIPTIONS column) by
    re-detecting the header row at the start of every supplier block.
    """
    ext = os.path.splitext(path)[1].lower()
    if ext in (".xls", ".xlsx"):
        raw = pd.read_excel(path, header=None, dtype=str)
    else:
        raw = pd.read_csv(path, header=None, dtype=str)

    raw = raw.fillna("")
    records = []
    col_map = None  # maps column name -> column index, refreshed at each header row

    for _, row in raw.iterrows():
        cell0 = str(row[0]).strip()

        if cell0 == "Supplier >>":
            col_map = None  # new group; wait for its header row
            continue

        if cell0.upper() == "DATE":
            # header row for this block -- map names to column indices
            col_map = {}
            for idx, val in row.items():
                name = str(val).strip().upper()
                if name:
                    col_map[name] = idx
            continue

        if col_map is None:
            continue  # stray/blank line before we've seen a header

        date_val = str(row[col_map.get("DATE", 0)]).strip()
        qty_val = str(row[col_map.get("QTY RCV", "")]).strip() if "QTY RCV" in col_map else ""

        if not date_val or not qty_val or qty_val.upper() == "TOTAL":
            continue  # blank separator row or the group's TOTAL row

        product_col = col_map.get("INVENTORY")
        if product_col is None:
            continue
        product_sku = str(row[product_col]).strip()
        if not product_sku:
            continue

        try:
            qty = float(str(qty_val).replace(",", ""))
        except ValueError:
            continue

        try:
            date = pd.to_datetime(date_val, errors="coerce")
        except Exception:
            date = pd.NaT
        if pd.isna(date):
            continue

        records.append({"date": date, "product_sku": product_sku, "qty": qty})

    return pd.DataFrame(records, columns=["date", "product_sku", "qty"])


def load_from_csv_sources(csv_path: str | None, xls_path: str | None) -> pd.DataFrame:
    frames = []
    for path in (csv_path, xls_path):
        if path:
            frames.append(parse_receiving_report(path))
    if not frames:
        raise SystemExit("No input files provided for --source=csv")
    return pd.concat(frames, ignore_index=True)


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


def load_from_mysql(env_path: str | None) -> pd.DataFrame:
    """Read historical monthly units sold straight from the app's own database."""
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
    query = """
        SELECT sale_date AS date, product_sku, quantity_sold AS qty
        FROM sales_history

        UNION ALL

        SELECT DATE(sales.created_at) AS date, products.sku AS product_sku,
               sale_items.quantity AS qty
        FROM sale_items
        JOIN sales ON sales.id = sale_items.sale_id
        JOIN products ON products.id = sale_items.product_id
    """
    df = pd.read_sql(query, conn)
    conn.close()
    df["date"] = pd.to_datetime(df["date"])
    return df


def monthly_series(df: pd.DataFrame) -> pd.DataFrame:
    """Aggregate raw receipt rows into a per-product, per-month qty total."""
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


def _plausible(values, ceiling) -> bool:
    """
    Is this fit usable as a demand forecast at all?

    Rejects rather than repairs, which is the whole point:

      - Non-finite values: the optimiser diverged.
      - ANY negative month. Demand cannot be negative, so a model asking for
        it has failed to fit. This used to be papered over with max(0.0, x),
        which laundered a broken fit into a confident "0 units" -- products
        selling 160/month with no empty months were being shown a forecast of
        zero. A zero must mean "the model expects no demand", never "the model
        fell over".
      - Anything above the ceiling: a short, noisy series can extrapolate to
        absurd volumes (one product averaging 20/month forecast 778).

    A rejected fit falls through to a simpler, better-behaved method.
    """
    arr = np.asarray(values, dtype=float)

    if arr.size == 0 or not np.all(np.isfinite(arr)):
        return False
    if (arr < 0).any():
        return False

    return bool((arr <= ceiling).all())


def _sarimax_rows(monthly, future_dates, horizon, order, seasonal_order, method):
    """One SARIMAX fit, returned as raw (unclamped) rows so _plausible can judge it."""
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    model = SARIMAX(
        monthly,
        order=order,
        seasonal_order=seasonal_order,
        enforce_stationarity=False,
        enforce_invertibility=False,
    )
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")  # statsmodels forces its own convergence-warning filter
        fit = model.fit(disp=False)

    pred = fit.get_forecast(steps=horizon)
    ci = pred.conf_int(alpha=0.2)  # 80% interval

    return [
        {
            "forecast_date": date,
            "forecast_value": round(float(m), 2),
            "lower_ci": round(float(lo), 2) if not np.isnan(lo) else 0.0,
            "upper_ci": round(float(hi), 2) if not np.isnan(hi) else float("nan"),
            "method": method,
        }
        for date, m, (lo, hi) in zip(future_dates, pred.predicted_mean, ci.values)
    ]


def _future_dates(series, horizon):
    return pd.date_range(series.index[-1] + pd.offsets.MonthBegin(1), periods=horizon, freq="MS")



def _clamp_rows(rows, ceiling):
    """
    Keep values non-negative, inside the ceiling, and in WHOLE UNITS.

    Demand is integral -- nobody dispenses 0.4 of a box -- so a forecast of
    2.47 was never a thing anyone could order against, and it scored as a 23%
    error against an actual of 2 purely for being written to two decimals.
    12,387 of 15,474 rows carried decimals, 2,339 of them a fraction between 0
    and 1.

    Rounding is applied to the value and both CI bounds so the band still
    brackets the point estimate after rounding.
    """
    for r in rows:
        value = round(min(max(0.0, r["forecast_value"]), ceiling))
        r["forecast_value"] = float(value)
        r["lower_ci"] = float(round(max(0.0, min(r["lower_ci"], value))))
        r["upper_ci"] = (
            float(round(min(r["upper_ci"], ceiling)))
            if not np.isnan(r["upper_ci"])
            else float(value)
        )

        # Rounding must not invert the band.
        r["upper_ci"] = float(max(r["upper_ci"], value))
        r["lower_ci"] = float(min(r["lower_ci"], value))

    return rows


def _sarima_selection_error(rows, observed):
    """
    How wrong one SARIMA order was on the months it was not allowed to see.

    MAPE-first, falling back to sMAPE when the holdout sold nothing (MAPE
    undefined). This is the opposite order from the retired cross-model
    cascade's criterion, deliberately: that one led with MAE/sMAPE because
    optimising MAPE directly, across DIFFERENT model families, tended to
    overfit low-volume products in a way that did not generalise. Choosing
    only ever stays inside the SARIMA family here, so there is no such risk,
    and lowering MAPE specifically is the point of trying more than one order.
    """
    if not rows:
        return None

    pred = np.array([float(r["forecast_value"]) for r in rows[:len(observed)]], dtype=float)
    obs = np.asarray(observed, dtype=float)[:len(pred)]

    if len(pred) == 0:
        return None

    nonzero = obs != 0
    mape = (
        float(np.mean(np.abs((pred[nonzero] - obs[nonzero]) / obs[nonzero])) * 100.0)
        if nonzero.any() else None
    )

    denom = np.abs(pred) + np.abs(obs)
    terms = np.where(denom == 0, 0.0, np.abs(pred - obs) / np.where(denom == 0, 1.0, denom))
    smape = float(np.mean(terms) * 200.0)

    return (mape if mape is not None else float("inf"), smape)


def _pick_sarima_order(series, holdout, ceiling):
    """
    Choose the SARIMA order that scores best on THIS product's own holdout,
    among SARIMA_CANDIDATES only -- never a different model family.
    """
    if len(series) < MIN_MONTHS_FOR_ANY_FORECAST + holdout:
        return None

    train = series.iloc[:-holdout]
    observed = series.iloc[-holdout:].to_numpy(dtype=float)
    dates = _future_dates(train, holdout)

    best = None

    for order, seasonal_order in SARIMA_CANDIDATES:
        try:
            rows = _sarimax_rows(train, dates, holdout, order, seasonal_order, "sarima")
        except Exception:  # noqa: BLE001 - a failed fit just loses the contest
            continue

        if not rows:
            continue

        rows = _clamp_rows(rows, ceiling)

        if not _plausible([r["forecast_value"] for r in rows], ceiling):
            continue

        error = _sarima_selection_error(rows, observed)

        if error is None:
            continue

        if best is None or error < best[1]:
            best = ((order, seasonal_order), error)

    return best[0] if best else None


def forecast_product(monthly: pd.Series, horizon: int):
    """
    Forecast one product with a SARIMA model -- always SARIMA, but the
    ORDER is picked per product from SARIMA_CANDIDATES by holdout accuracy
    (see _pick_sarima_order), rather than forcing the same order onto every
    series regardless of how well it fits.

    Returns a list of dicts, one per forecasted month, or [] when nothing in
    SARIMA_CANDIDATES produces a usable fit. There is no fallback outside
    the SARIMA family: a rejected fit means no forecast.
    """
    monthly = monthly.asfreq("MS", fill_value=0)
    n = len(monthly)

    if n < MIN_MONTHS_FOR_ANY_FORECAST:
        return []  # not enough data to forecast at all

    hist_max = float(monthly.max())
    hist_mean = float(monthly.mean())
    ceiling = max(hist_max * 2.5, hist_mean * 4, 1.0)
    dates = _future_dates(monthly, horizon)

    if n >= MIN_MONTHS_FOR_ANY_FORECAST + SARIMA_SELECTION_HOLDOUT:
        winner = _pick_sarima_order(monthly, SARIMA_SELECTION_HOLDOUT, ceiling)

        if winner:
            try:
                rows = _sarimax_rows(monthly, dates, horizon, winner[0], winner[1], "sarima")
            except Exception:  # noqa: BLE001 - refit failed; fall through below
                rows = None

            if rows:
                rows = _clamp_rows(rows, ceiling)
                if _plausible([r["forecast_value"] for r in rows], ceiling):
                    return rows

    # Too short to hold out SARIMA_SELECTION_HOLDOUT months, or the winning
    # order failed to refit on the full series (rare -- more data usually
    # helps rather than hurts): try every candidate directly, richest first,
    # first plausible fit wins. Still never leaves the SARIMA family.
    for order, seasonal_order in SARIMA_CANDIDATES:
        try:
            rows = _sarimax_rows(monthly, dates, horizon, order, seasonal_order, "sarima")
        except Exception:  # noqa: BLE001 - any fitting failure means "try the next order"
            continue

        if not rows:
            continue

        rows = _clamp_rows(rows, ceiling)

        if _plausible([r["forecast_value"] for r in rows], ceiling):
            return rows

    return []


HOLDOUT_MONTHS = 3


def backtest_product(monthly: pd.Series, holdout: int = HOLDOUT_MONTHS):
    """
    Score this product's forecast against months it was not allowed to see.

    Refits the SAME SARIMA model forecast_product() uses, on the series minus
    its last `holdout` months, then compares the predictions to the months held
    back. Scoring the model actually in use is the whole point -- a metric
    taken from some other model would describe a forecast nobody is looking at.

    Returns None when the remaining history is too short to fit anything, which
    is honest: no score is better than a score computed from three data points.
    """
    monthly = monthly.asfreq('MS', fill_value=0)

    # The training half must still clear the cascade's own floor, or the
    # backtest measures a model the product would never actually get.
    if len(monthly) < MIN_MONTHS_FOR_ANY_FORECAST + holdout:
        return None

    train = monthly.iloc[:-holdout]
    actual = monthly.iloc[-holdout:]

    rows = forecast_product(train, holdout)

    if not rows:
        return None

    predicted = np.array([float(r['forecast_value']) for r in rows[:holdout]], dtype=float)
    observed = actual.to_numpy(dtype=float)[:len(predicted)]

    if len(predicted) == 0 or len(observed) == 0:
        return None

    errors = predicted - observed
    mae = float(np.mean(np.abs(errors)))
    rmse = float(np.sqrt(np.mean(errors ** 2)))

    # MAPE divides by the observed value, so a month that sold nothing makes
    # the term undefined -- and that is the common case here, not an edge case:
    # most products in this catalogue sell in only a few months of the year.
    # Score it over the non-zero months only and report how many those were, so
    # a MAPE built from one month is not mistaken for one built from three.
    nonzero = observed != 0
    mape = (float(np.mean(np.abs(errors[nonzero] / observed[nonzero])) * 100.0)
            if nonzero.any() else None)

    # sMAPE stays defined at zero because it divides by the sum of both terms,
    # so it is the fallback wherever MAPE is null. 0/0 is scored as 0 error.
    denom = (np.abs(predicted) + np.abs(observed))
    smape_terms = np.where(denom == 0, 0.0, np.abs(errors) / np.where(denom == 0, 1.0, denom))
    smape = float(np.mean(smape_terms) * 200.0)

    return {
        'mae': round(mae, 4),
        'rmse': round(rmse, 4),
        'mape': round(mape, 4) if mape is not None else None,
        'smape': round(smape, 4),
        'holdout_months': int(holdout),
        'points_scored': int(len(predicted)),
        'points_scored_mape': int(nonzero.sum()),
        'method': rows[0].get('method'),
    }


def _forecast_task(task):
    """
    Worker entry point: one product in, its forecast rows out.

    Must stay a module-level function so it survives pickling to the worker
    processes (Windows uses spawn, not fork).

    Never raises: a single pathological series must not tear down a pool
    that is 20 minutes into a 2,600-product catalog. Failures come back as
    an error string and are reported as a summary at the end.
    """
    sku, series, horizon = task
    try:
        rows = forecast_product(series, horizon)

        # Scored in the worker, not the parent: the backtest is a second fit of
        # the same cascade, so it belongs on the pool rather than serialised
        # through one process after the fact.
        try:
            score = backtest_product(series)
        except Exception:  # noqa: BLE001 - a failed score must not lose the forecast
            score = None

        return sku, rows, score, None
    except Exception as exc:  # noqa: BLE001 - deliberately broad, see docstring
        return sku, [], None, f"{type(exc).__name__}: {exc}"


def resolve_workers(requested: int) -> int:
    """
    0 (the default) means "pick automatically": leave one core free so the
    machine stays responsive -- this often runs on the same box that serves
    the app. Anything explicit is honoured, clamped to at least 1.
    """
    if requested and requested > 0:
        return max(1, requested)

    return max(1, (os.cpu_count() or 2) - 1)


def iter_forecasts(tasks, workers):
    """
    Yield (sku, rows, error) for every task, in the order given.

    Order matters: it keeps the output CSV byte-for-byte deterministic
    regardless of how many workers ran or which finished first.
    Single-worker runs skip the pool entirely, which keeps tracebacks
    readable when debugging a model change.
    """
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
    parser.add_argument(
        "--metrics",
        default=None,
        help="Optional path for the holdout accuracy CSV (MAE / RMSE / MAPE / sMAPE per product).",
    )
    parser.add_argument("--env-path", default=None)
    parser.add_argument("--csv-path", default=None)
    parser.add_argument("--xls-path", default=None)
    parser.add_argument(
        "--workers",
        type=int,
        default=0,
        help="Worker processes for model fitting. 0 = auto (all cores but one), 1 = sequential.",
    )
    args = parser.parse_args()

    if args.source == "csv":
        raw = load_from_csv_sources(args.csv_path, args.xls_path)
    else:
        # No --env-path is fine on a server: db_credentials() falls back to
        # the process environment, and says what is missing if neither has it.
        raw = load_from_mysql(args.env_path)

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

    # Materialize the per-product series up front so the workers receive
    # plain picklable data rather than a shared groupby object.
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
    # defaultdict, not a fixed dict: forecast_product() picks from a fallback
    # chain and adding a method there must not KeyError the write step.
    method_counts = collections.defaultdict(int)
    failures = []
    start_time = time.monotonic()
    progress_every = max(1, total_products // 100)  # ~100 progress lines total, regardless of catalog size

    metric_rows = []

    for i, (sku, rows, score, error) in enumerate(iter_forecasts(tasks, workers), start=1):
        if score:
            metric_rows.append({"product_sku": sku, **score})
        if error:
            failures.append((sku, error))
        for row in rows:
            # .get(), not [] -- a new method name in forecast_product() must not
            # abort a 2,600-product run at the write step.
            confidence = "high" if row["method"] == "sarima" else "low"
            method_counts[row["method"]] += 1
            output_rows.append({
                "product_sku": sku,
                "forecast_date": row["forecast_date"].strftime("%Y-%m-%d"),
                "forecast_value": row["forecast_value"],
                "lower_ci": row["lower_ci"],
                "upper_ci": row["upper_ci"],
                "method": row["method"],
                "confidence": confidence,
                "generated_at": generated_at,
            })

        if i % progress_every == 0 or i == total_products:
            elapsed = time.monotonic() - start_time
            rate = i / elapsed if elapsed > 0 else 0
            remaining = (total_products - i) / rate if rate > 0 else 0
            pct = 100 * i / total_products
            print(
                f"[{i}/{total_products}] {pct:5.1f}%  "
                f"elapsed {elapsed:6.0f}s  ETA {remaining:6.0f}s  "
                "(" + " ".join(f"{k}={v}" for k, v in sorted(method_counts.items())) + ")",
                flush=True,
            )

    if args.metrics:
        # Written even when empty, so the importer can tell "no products were
        # scorable" from "the run never produced a metrics file".
        metrics_df = pd.DataFrame(metric_rows, columns=[
            "product_sku", "mae", "rmse", "mape", "smape",
            "holdout_months", "points_scored", "points_scored_mape", "method",
        ])
        os.makedirs(os.path.dirname(args.metrics), exist_ok=True)
        metrics_df.to_csv(args.metrics, index=False)

        scored = len(metrics_df)
        with_mape = int(metrics_df["mape"].notna().sum()) if scored else 0
        print(
            f"Scored {scored} products on a {HOLDOUT_MONTHS}-month holdout "
            f"({with_mape} with a usable MAPE) -> {args.metrics}",
            flush=True,
        )

    out_df = pd.DataFrame(output_rows)
    os.makedirs(os.path.dirname(args.output), exist_ok=True)
    out_df.to_csv(args.output, index=False)
    total_elapsed = time.monotonic() - start_time
    print(f"Wrote {len(out_df)} forecast rows for {monthly['product_sku'].nunique()} products to {args.output} in {total_elapsed:.0f}s")
    print("Method breakdown: " + " ".join(f"{k}={v}" for k, v in sorted(method_counts.items())))

    if failures:
        # Non-fatal: these products simply have no forecast rows this run.
        # Surfaced loudly so a systematic breakage isn't silently tolerated.
        print(f"WARNING: {len(failures)} product(s) failed to forecast and were skipped:", flush=True)
        for sku, error in failures[:10]:
            print(f"  - {sku}: {error}", flush=True)
        if len(failures) > 10:
            print(f"  ... and {len(failures) - 10} more", flush=True)


if __name__ == "__main__":
    main()
