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

  --source=mysql Read historical monthly units sold from the `sales_history` table
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

# Seasonal differencing at period 12 (D=1, m=12) burns a whole year of
# observations before it can estimate anything, so two cycles is NOT enough --
# with 24-32 months it is wildly over-parameterised and the fit oscillates.
# Measured on this catalogue (183 products, 3-month holdout): seasonal SARIMA
# scored MAE 1319.63 and produced a negative forecast for 49% of products,
# against MAE 17.83 and 0% negative for the non-seasonal chain below. Three
# full cycles before the seasonal model is allowed to run.
MIN_MONTHS_FOR_SEASONAL_SARIMA = 36
MIN_MONTHS_FOR_SEASONAL_SMOOTHING = 24  # Holt-Winters needs two cycles to fit 12 seasonal indices
MIN_MONTHS_FOR_SARIMA = 24   # non-seasonal ARIMA(1,1,1)
MIN_MONTHS_FOR_SMOOTHING = 12  # enough for trend-only exponential smoothing
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


def load_from_mysql(env_path: str) -> pd.DataFrame:
    """Read historical monthly units sold straight from the app's own database."""
    import pymysql

    env = {}
    with open(env_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")

    conn = pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME"),
        password=env.get("DB_PASSWORD"),
        database=env.get("DB_DATABASE"),
    )
    query = """
        SELECT sale_date AS date, product_sku, quantity_sold AS qty
        FROM sales_history
    """
    df = pd.read_sql(query, conn)
    conn.close()
    df["date"] = pd.to_datetime(df["date"])
    return df


def monthly_series(df: pd.DataFrame) -> pd.DataFrame:
    """Aggregate raw receipt rows into a per-product, per-month qty total."""
    df = df.copy()
    df["month"] = df["date"].dt.to_period("M").dt.to_timestamp()
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


def _smoothing_rows(monthly, future_dates, horizon, hist_mean, seasonal=False):
    """
    Damped-trend exponential smoothing, raw rows.

    With seasonal=True this is additive Holt-Winters (period 12). It is now one
    of the three votes in _ensemble_rows as well as a fallback in its own right,
    so it runs for every product with 3+ years of history and its cost is on the
    hot path.

    use_brute=False matters for that reason. The statsmodels default runs a
    brute-force grid search for the optimiser's starting values, which measured
    218ms per product against 114ms without it -- on 2,637 products that is the
    difference between a 4-minute nightly job and a 2-minute one. Measured over
    a random 40-product sample, the two settings' forecasts differ by a mean of
    0.027 units, i.e. ~0.3% of the model's own MAE: the grid search is buying
    precision far below the noise floor of this data.
    """
    from statsmodels.tsa.holtwinters import ExponentialSmoothing

    kwargs = {"seasonal": "add", "seasonal_periods": 12} if seasonal else {}
    model = ExponentialSmoothing(monthly, trend="add", damped_trend=True, **kwargs)
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        fit = model.fit(use_brute=False)

    mean = fit.forecast(horizon)
    resid_std = float(np.std(fit.resid)) if len(fit.resid) > 1 else hist_mean * 0.2

    return [
        {
            "forecast_date": date,
            "forecast_value": round(float(m), 2),
            "lower_ci": round(float(m) - resid_std, 2),
            "upper_ci": round(float(m) + resid_std, 2),
            "method": "holt_winters_seasonal" if seasonal else "holt_winters",
        }
        for date, m in zip(future_dates, mean)
    ]


def _seasonal_naive_values(monthly, horizon):
    """
    Same calendar month one and two years back, averaged.

    No parameters to estimate, so it cannot diverge -- which is exactly why it
    earns a vote below. Two years rather than one because a single prior month
    carries that month's noise straight into the forecast.
    """
    v = np.asarray(monthly, dtype=float)
    out = []

    for h in range(1, horizon + 1):
        picks = []
        if len(v) >= 13 - h:
            picks.append(v[h - 13])
        if len(v) >= 25 - h:
            picks.append(v[h - 25])
        out.append(float(np.mean(picks)) if picks else float(v[-1]))

    return out


def _ensemble_rows(monthly, future_dates, horizon, hist_mean, ceiling):
    """
    Element-wise MEDIAN of three seasonal views of the same series.

    The three read the 12-month cycle differently and, more importantly, they
    fail differently: the airline SARIMA can overswing when one month of the
    cycle is an outlier, damped Holt-Winters carries a level that lags a turning
    point, and the seasonal naive is unbiased but noisy. Taking the median
    discards whichever one disagrees most with the other two, which is the
    failure that used to push a product all the way down the fallback chain.

    A member that cannot fit, or whose fit fails _plausible, gets NO vote --
    a diverged member must not drag the median with it. If nothing survives,
    this raises and the chain falls through to the plain airline SARIMA below.

    Measured on the FULL catalogue (2,637 products, every one scored -- a
    candidate that rejects a product falls through the same chain, so no model
    can flatter itself by dropping the hard ones), against the airline SARIMA
    as primary:

      | holdout | metric | airline alone | this ensemble |
      |---|---|---|---|
      | 3mo | MAE   |  8.76 |  8.49 (-3.1%) |
      | 3mo | RMSE  | 47.29 | 44.59 (-5.7%) |
      | 3mo | MAPE  | 20.0% | 18.6% (-7.0%) |
      | 3mo | sMAPE | 19.4% | 18.3% (-5.7%) |
      | 6mo | MAE   |  8.17 |  7.78 (-4.7%) |
      | 6mo | RMSE  | 38.94 | 38.09 (-2.2%) |
      | 6mo | MAPE  | 20.5% | 18.6% (-9.2%) |
      | 6mo | sMAPE | 19.7% | 18.4% (-6.6%) |

    6 months is the production horizon (--horizon default); 3 is the holdout the
    earlier order comparison in this file used. No single model tried -- and the
    search covered (0,1,2), (0,1,3), (1,1,2), (2,1,1), (1,0,1), D=0 seasonal
    terms, sqrt and log1p transforms -- beat this on MAE, RMSE and MAPE at once.
    Plain Holt-Winters edges it on MAE (7.78 at 6mo) and loses on RMSE.

    Note this keeps SARIMA as the centre of the method: it is one of the three
    votes and still the only member that estimates an interval, which is where
    lower_ci/upper_ci below come from.
    """
    members = []
    ci_row_source = None

    try:
        sarima = _sarimax_rows(
            monthly, future_dates, horizon, (0, 1, 1), (0, 1, 1, 12), "sarima_seasonal"
        )
        values = [r["forecast_value"] for r in sarima]
        if _plausible(values, ceiling):
            members.append(values)
            ci_row_source = sarima
    except Exception:
        pass

    try:
        hw = _smoothing_rows(monthly, future_dates, horizon, hist_mean, seasonal=True)
        values = [r["forecast_value"] for r in hw]
        if _plausible(values, ceiling):
            members.append(values)
            if ci_row_source is None:
                ci_row_source = hw
    except Exception:
        pass

    naive = _seasonal_naive_values(monthly, horizon)
    if _plausible(naive, ceiling):
        members.append(naive)

    if not members:
        raise RuntimeError("no ensemble member produced a plausible fit")

    combined = np.median(np.asarray(members, dtype=float), axis=0)

    # Interval width comes from the member that actually estimates uncertainty,
    # recentred on the combined point forecast. Falls back to a proportional
    # band when only the naive member survived (it has no interval of its own).
    halves = []
    for i in range(horizon):
        half = float("nan")
        if ci_row_source is not None:
            lo, hi = ci_row_source[i]["lower_ci"], ci_row_source[i]["upper_ci"]
            if np.isfinite(lo) and np.isfinite(hi):
                half = abs(hi - lo) / 2.0
        if not np.isfinite(half):
            half = max(abs(float(combined[i])) * 0.2, hist_mean * 0.1)
        halves.append(half)

    return [
        {
            "forecast_date": date,
            "forecast_value": round(float(m), 2),
            "lower_ci": round(float(m) - half, 2),
            "upper_ci": round(float(m) + half, 2),
            "method": "sarima_ensemble",
        }
        for date, m, half in zip(future_dates, combined, halves)
    ]


def forecast_product(monthly: pd.Series, horizon: int):
    """
    Forecast one product, trying the richest model its history can support and
    falling back whenever the fit fails the plausibility check above.

    Order: seasonal SARIMA (3+ years only) -> non-seasonal ARIMA (2+ years) ->
    damped exponential smoothing (1+ year) -> trailing moving average. The
    moving average is the floor of the chain because it cannot go negative or
    explode, so there is always a usable answer.

    Returns a list of dicts, one per forecasted month.
    """
    monthly = monthly.asfreq("MS", fill_value=0)
    n = len(monthly)
    last_date = monthly.index[-1]
    future_dates = pd.date_range(last_date + pd.offsets.MonthBegin(1), periods=horizon, freq="MS")

    if n < MIN_MONTHS_FOR_ANY_FORECAST:
        return []  # not enough data to forecast at all

    # Real sales are noisy and short models extrapolate absurdly. Never forecast
    # further out than a small multiple of the observed historical range.
    hist_max = float(monthly.max())
    hist_mean = float(monthly.mean())
    ceiling = max(hist_max * 2.5, hist_mean * 4, 1.0)

    def finalize(rows):
        """Tidy the accepted fit: keep CIs inside the ceiling and non-negative."""
        for r in rows:
            r["forecast_value"] = round(min(max(0.0, r["forecast_value"]), ceiling), 2)
            r["lower_ci"] = round(max(0.0, min(r["lower_ci"], r["forecast_value"])), 2)
            r["upper_ci"] = (
                round(min(r["upper_ci"], ceiling), 2)
                if not np.isnan(r["upper_ci"])
                else r["forecast_value"]
            )
        return rows

    candidates = []

    if n >= MIN_MONTHS_FOR_SEASONAL_SARIMA:
        # Median of airline SARIMA + seasonal Holt-Winters + seasonal naive.
        # See _ensemble_rows for the measurement; no single model beat it on
        # MAE, RMSE and MAPE at once.
        candidates.append(
            lambda: _ensemble_rows(monthly, future_dates, horizon, hist_mean, ceiling)
        )
        # The "airline" model, (0,1,1)(0,1,1,12), on its own -- still the best
        # single SARIMA order tried, and the fallback when the ensemble finds
        # no plausible member. The seasonal term is an MA, not the AR this used
        # to use: measured over 90 products on a 3-month holdout,
        # (1,1,1)(1,1,0,12) scored MAE 12.14 / RMSE 38.58 / MAPE 22.0% against
        # 9.58 / 32.55 / 20.0% here -- a 21% cut in MAE from the order alone.
        candidates.append(
            lambda: _sarimax_rows(monthly, future_dates, horizon, (0, 1, 1), (0, 1, 1, 12), "sarima_seasonal")
        )
    if n >= MIN_MONTHS_FOR_SEASONAL_SMOOTHING:
        candidates.append(
            lambda: _smoothing_rows(monthly, future_dates, horizon, hist_mean, seasonal=True)
        )
    if n >= MIN_MONTHS_FOR_SARIMA:
        candidates.append(
            lambda: _sarimax_rows(monthly, future_dates, horizon, (1, 1, 1), (0, 0, 0, 0), "arima")
        )
    if n >= MIN_MONTHS_FOR_SMOOTHING:
        candidates.append(lambda: _smoothing_rows(monthly, future_dates, horizon, hist_mean))

    for build in candidates:
        try:
            rows = build()
        except Exception:
            continue  # any fitting failure just means "try the next model"

        if _plausible([r["forecast_value"] for r in rows], ceiling):
            return finalize(rows)

    # Floor of the chain: trailing moving average (last up-to-6 months),
    # flat-line forecast. Cannot be negative and cannot exceed the ceiling.
    window = monthly.tail(min(6, n))
    avg = float(window.mean())
    std = float(window.std(ddof=0)) if len(window) > 1 else avg * 0.2

    return finalize([
        {
            "forecast_date": date,
            "forecast_value": round(avg, 2),
            "lower_ci": round(avg - std, 2),
            "upper_ci": round(avg + std, 2),
            "method": "moving_average",
        }
        for date in future_dates
    ])


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
        return sku, forecast_product(series, horizon), None
    except Exception as exc:  # noqa: BLE001 - deliberately broad, see docstring
        return sku, [], f"{type(exc).__name__}: {exc}"


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
        if not args.env_path:
            raise SystemExit("--env-path is required for --source=mysql")
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
    tasks = [
        (sku, group.set_index("month")["qty"].sort_index(), args.horizon)
        for sku, group in products
    ]

    output_rows = []
    # defaultdict, not a fixed dict: forecast_product() picks from a fallback
    # chain and adding a method there must not KeyError the write step.
    method_counts = collections.defaultdict(int)
    failures = []
    start_time = time.monotonic()
    progress_every = max(1, total_products // 100)  # ~100 progress lines total, regardless of catalog size

    for i, (sku, rows, error) in enumerate(iter_forecasts(tasks, workers), start=1):
        if error:
            failures.append((sku, error))
        for row in rows:
            # .get(), not [] -- a new method name in forecast_product() must not
            # abort a 2,600-product run at the write step.
            confidence = {
                "sarima_ensemble": "high",
                "sarima_seasonal": "high",
                "sarima": "high",          # legacy name, kept so old CSVs still load
                "arima": "high",
                "holt_winters_seasonal": "high",
                "holt_winters": "medium",
                "moving_average": "low",
            }.get(row["method"], "low")
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
