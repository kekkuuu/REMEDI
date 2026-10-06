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
import json
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
# HISTORY of the candidate set (superseded by SARIMA ONLY below): from
# 2026-09-29 seasonal and non-seasonal orders competed per product with a level
# guard (the user's choice from measured options):
#   2026-09-27, first zip: seasonal orders projected the record's May-2025
#   level step forward (MAE 12.07 vs 6.92 without them), so seasonal was cut.
#   On the RED Pharmacy record (May-Jul 2026 holdout, 330 products): non-
#   seasonal 15.33 / MAPE 59.5%, seasonal kept 15.93 / 62.6%, seasonal WITH
#   the guard below 15.62 / 60.3% -- still ahead of both naive baselines
#   (mean of last 3: 16.61 / 62.9%; repeat last: 18.13 / 72.9%). Those
#   seasonal figures included DIVERGED fits (see _sarimax_rows); with the
#   seasonal fits enforced, seasonal FIRST scored 16.16 / 67.3% there and
#   9.43 / 66.2% on the 2023-2026 transaction file, behind the competing
#   rule used now (8.47 / 57.7%).
# SARIMA ONLY (2026-10-04, the user's call): every product is fitted with a
# SEASONAL SARIMA(p,1,q)(P,0,Q,12) -- no ARIMA, no fallback to a non-seasonal
# order. (Since 2026-10-06 a product too short for SARIMA, or one every order
# fails on, gets a non-SARIMA FALLBACK instead of no forecast -- see
# MIN_MONTHS_FOR_SARIMA.) D = 0 on purpose: with seasonal DIFFERENCING (D = 1) the model reads
# this year as "last year plus a change" and copies the store's growth year into
# a flat one (3-month holdout MAPE 100.9% forced on every product). With D = 0
# the seasonal term is ONE coefficient the fit estimates, near zero where a
# product has no yearly pattern. Measured on all 2,617 products, May-Jul 2026
# holdout, log(1 + units): MAE 8.30 / RMSE 9.72 / MAPE 52.3% / WAPE 29.9%
# (D = 0 + D = 1 together 58.1%; D = 0 on raw units 56.0%; the rule this
# replaced -- SARIMA only where a seasonality test passed, ARIMA otherwise --
# 50.7%). The order is still chosen per product by _pick_sarima_order().
SARIMA_CANDIDATES = [
    ((1, 1, 1), (1, 0, 0, 12)),
    ((0, 1, 1), (1, 0, 0, 12)),
    ((1, 1, 1), (0, 0, 1, 12)),
    ((0, 1, 1), (0, 0, 1, 12)),
]
SEASONAL_CANDIDATES = SARIMA_CANDIDATES
SARIMA_ORDER, SARIMA_SEASONAL_ORDER = SARIMA_CANDIDATES[0]

# Fit on log(1 + units), forecasts back-transformed with expm1 (2026-10-04, the
# user's request to lower MAPE). Monthly units here are small, skewed counts;
# on the log scale a sale of 2 vs 4 weighs like 200 vs 400, so a few big
# months stop dominating the fit, and the back-transform gives the MEDIAN month
# rather than the mean -- the figure MAPE rewards. REMEDI_TRANSFORM=none fits
# raw units, as before. Measured in CLAUDE.md "Forecasting pipeline".
TRANSFORM = os.environ.get("REMEDI_TRANSFORM", "log1p")


def _to_units(values):
    """Back-transform a forecast (or band) from the fitting scale to units."""
    v = np.asarray(values, dtype=float)
    if TRANSFORM != "log1p":
        return v
    if np.any(v > 25):  # expm1(25) is ~7e10 units: a runaway fit, not a forecast
        raise ValueError("log-scale forecast overflow")
    return np.expm1(v)


SARIMA_SELECTION_HOLDOUT = 3
MIN_MONTHS_FOR_ANY_FORECAST = 3

# FALLBACK (2026-10-06, at the user's request: "every eligible product should
# have a forecast"). Before this, a product SARIMA could not fit got NO rows --
# 4 products a run, silently missing from every total. Now:
#
#   - Under MIN_MONTHS_FOR_SARIMA months (two yearly cycles) SARIMA is not
#     attempted: its seasonal term is fitted at lag 12 and cannot be estimated
#     from less. These products (26 of 2,617, most selling in under half their
#     months) go straight to the fallback.
#   - With enough history SARIMA is attempted as before. If every order fails,
#     the reason is recorded per order (fit error / degenerate fit / negative /
#     above the ceiling), the series is described (describe_series), one repair
#     is tried where it applies (outliers capped, then SARIMA again), and only
#     then does the product fall back.
#   - The fallback is chosen from the series: Croston's method with the SBA
#     correction for intermittent demand (half or more of its months at zero),
#     otherwise the average of the last 3 months -- the baseline the 80/20 test
#     already measures the model against.
MIN_MONTHS_FOR_SARIMA = 24
INTERMITTENT_ZERO_SHARE = 0.5
FALLBACK_RECENT_MEAN = "fallback_recent_mean"
FALLBACK_CROSTON = "fallback_croston_sba"

# On the log(1 + units) scale a forecast cannot go below -1 unit, and a slightly
# negative one means "about nothing". The sanity check rejected ANY negative,
# which was right on raw units (a model asking for -40 boxes has failed) but
# not here: all 4 products SARIMA "failed" on 2026-10-06 were rejected for
# forecasts of -0.01 to -0.31 units on items selling 0.3-0.6 a month -- every
# one of which rounds to 0 whole units anyway. Down to -0.5 (still 0 once
# rounded) is accepted on the log scale; below it is still a failed fit.
LOG_NEGATIVE_TOLERANCE = 0.5

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


def load_from_mysql(env_path: str | None, include_pos: bool = False) -> pd.DataFrame:
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
    #
    # Archived products are left out of BOTH halves. Their history stays in the
    # database (that is what archiving is for), but a product nobody stocks any
    # more must not keep getting a forecast, and its rows would otherwise flow
    # straight into the store-wide totals on the Sales Forecasting page. The
    # first branch has no join to products, so it excludes by SKU.
    # The till's branch is OPT-IN (--include-pos, from config forecast.include_pos)
    # as of 2026-09-27: the imported record runs ~10x the till's volume, and
    # mixing them made the handoff month read as a crash. Off, training is the
    # imported record alone.
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
    df = pd.read_sql(query, conn)
    conn.close()
    df["date"] = pd.to_datetime(df["date"])
    return df


def catalogue_skus(env_path: str | None) -> list:
    """Every product's SKU, archived ones included -- the whole catalogue."""
    import pymysql

    env = db_credentials(env_path)
    conn = pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME"),
        password=env.get("DB_PASSWORD"),
        database=env.get("DB_DATABASE"),
    )
    with conn.cursor() as cur:
        cur.execute("SELECT sku FROM products WHERE sku IS NOT NULL AND sku <> ''")
        skus = [str(r[0]) for r in cur.fetchall()]
    conn.close()
    return skus


# The method a never-sold product's forecast carries (2026-10-03, at the
# user's request: "a forecast to all 2,638 products"). With no sale in its
# history there is nothing for SARIMA to fit, so its forecast is 0 units a
# month, written down as exactly that rather than as a model's output.
NO_HISTORY_METHOD = "no_history"


def no_history_rows(horizon_start, horizon: int):
    """The zero forecast for a product that has never sold."""
    return [
        {"forecast_date": d, "forecast_value": 0.0, "lower_ci": 0.0, "upper_ci": 0.0, "method": NO_HISTORY_METHOD}
        for d in pd.date_range(horizon_start, periods=horizon, freq="MS")
    ]


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

    A rejected fit falls through to the next SARIMA order, and past the last one
    to the fallback (forecast_product_explained). On the log scale "negative"
    allows LOG_NEGATIVE_TOLERANCE -- see there.
    """
    return _implausible_reason(values, ceiling) is None


def _implausible_reason(values, ceiling):
    """None when _plausible() would accept the forecast, else why not -- for the log."""
    arr = np.asarray(values, dtype=float)

    if arr.size == 0:
        return "no forecast values"
    if not np.all(np.isfinite(arr)):
        return "non-finite forecast (the fit diverged)"
    floor = -LOG_NEGATIVE_TOLERANCE if TRANSFORM == "log1p" else 0.0
    if (arr < floor).any():
        return f"negative forecast (min {arr.min():.2f} units)"
    if (arr > ceiling).any():
        return f"above the ceiling (max {arr.max():.0f} > {ceiling:.0f} units)"
    return None


def _sarimax_rows(monthly, future_dates, horizon, order, seasonal_order, method):
    """One SARIMAX fit, returned as raw (unclamped) rows so _plausible can judge it."""
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    # SEASONAL orders are fitted with stationarity/invertibility ENFORCED
    # (2026-09-29). Left free, the seasonal MA term diverged on real products:
    # TELMIGEN 40MG came back with ma.S.L12 = -3.5e13 and sigma2 = 0, a point
    # forecast that just replayed last year and an "80% band" of +/-590
    # million units -- accepted, because only the point values were checked.
    # Enforced, the same order fits ma.S.L12 = -0.71 with a 268-673 band.
    # Non-seasonal orders keep the free fit they were measured with.
    seasonal = len(seasonal_order) == 4 and seasonal_order[3] > 0
    model = SARIMAX(
        np.log1p(monthly) if TRANSFORM == "log1p" else monthly,
        order=order,
        seasonal_order=seasonal_order,
        enforce_stationarity=seasonal,
        enforce_invertibility=seasonal,
    )
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")  # statsmodels forces its own convergence-warning filter
        fit = model.fit(disp=False)

    pred = fit.get_forecast(steps=horizon)
    ci = pred.conf_int(alpha=0.2)  # 80% interval

    # A degenerate fit (no noise, or a band that is not a number) has failed,
    # whatever its point forecast looks like: treat it as a failed fit.
    if not np.all(np.isfinite(ci.values)) or not float(fit.params.get("sigma2", 1.0)) > 0:
        raise ValueError("degenerate SARIMA fit")

    mean, bands = _to_units(pred.predicted_mean), _to_units(ci.values)
    return [
        {
            "forecast_date": date,
            "forecast_value": round(float(m), 2),
            "lower_ci": round(float(lo), 2) if not np.isnan(lo) else 0.0,
            "upper_ci": round(float(hi), 2) if not np.isnan(hi) else float("nan"),
            "method": method,
        }
        for date, m, (lo, hi) in zip(future_dates, mean, bands)
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


# How many rolling windows the order is chosen over (2026-09-27). ONE 3-month
# window let a lucky order win: tested against two naive baselines on the
# May-Jul 2026 holdout, SARIMA lost on average (MAE 13.68 vs 6.57 for "mean of
# the last 3 months") -- entirely from ~5% of products where a SEASONAL order
# read the source data's May-2025 volume step as year-on-year growth and
# projected it into 2026 (a steady ~700/month product forecast ~1,000). Scoring
# each order over several consecutive windows makes it earn its place more than
# once. Still SARIMA_CANDIDATES only -- never a different model family.
SELECTION_FOLDS = 3


def _selection_folds(series, holdout):
    """(train, observed) pairs, newest window first, each training on the months before it."""
    folds = []
    for k in range(1, SELECTION_FOLDS + 1):
        cut = len(series) - holdout * k
        if cut < MIN_MONTHS_FOR_ANY_FORECAST:
            break
        folds.append((series.iloc[:cut], series.iloc[cut:cut + holdout].to_numpy(dtype=float)))
    return folds


def _pick_sarima_order(series, holdout, ceiling, candidates=None):
    """
    Choose the SARIMA order with the lowest AVERAGE error across the rolling
    windows in _selection_folds, among SARIMA_CANDIDATES only.

    MAE-first across windows (ties: unrounded MAE, then sMAPE). Within one product every
    candidate is scored on the same units, so MAE compares them directly; the
    MAPE-first rule it replaces could not average over a window that sold
    nothing (MAPE undefined there). An order must produce a PLAUSIBLE raw
    forecast in every window to qualify -- judged before clamping, so a fit
    that wanted negative demand is rejected, not repaired into zeros.
    """
    folds = _selection_folds(series, holdout)
    if not folds:
        return None

    best = None

    for order, seasonal_order in (candidates or SARIMA_CANDIDATES):
        maes, smapes, exact_maes = [], [], []
        for train, observed in folds:
            try:
                rows = _sarimax_rows(train, _future_dates(train, holdout), holdout, order, seasonal_order, "sarima")
            except Exception:  # noqa: BLE001 - a failed fit just loses the contest
                rows = None

            if not rows or not _plausible([r["forecast_value"] for r in rows], ceiling):
                maes = None
                break

            # Kept before _clamp_rows rounds to whole units in place -- see the
            # tie rule below.
            exact = np.clip([r["forecast_value"] for r in rows[:len(observed)]], 0.0, ceiling)
            rows = _clamp_rows(rows, ceiling)
            pred = np.array([r["forecast_value"] for r in rows[:len(observed)]], dtype=float)
            err = pred - observed[:len(pred)]
            maes.append(float(np.mean(np.abs(err))))
            exact_maes.append(float(np.mean(np.abs(exact - observed[:len(exact)]))))
            denom = np.abs(pred) + np.abs(observed[:len(pred)])
            smapes.append(float(np.mean(np.where(denom == 0, 0.0, np.abs(err) / np.where(denom == 0, 1.0, denom))) * 200.0))

        if not maes:
            continue

        # Whole-unit MAE first, as measured. An exact tie on it -- common, since
        # whole units give few distinct values -- is broken on the UNROUNDED
        # forecast's MAE, then sMAPE (2026-10-06, at the user's request: make the
        # live site match the terminal). Breaking it on sMAPE alone made the
        # winner hang on whole-unit rounding: a fold forecast of exactly x.5
        # rounds one way on the Windows PC and the other on the Linux server,
        # so DUVADILAN 10MG chose a different order on each -- 1 unit a month
        # apart. The unrounded MAEs differ by far more than the machines do.
        # Measured with the fallback: 252 forecasts changed, holdout MAPE
        # 52.33% -> 52.42%, MAE 8.3038 -> 8.3076.
        score = (float(np.mean(maes)), float(np.mean(exact_maes)), float(np.mean(smapes)))
        if best is None or score < best[1]:
            best = ((order, seasonal_order), score)

    return best[0] if best else None


def forecast_product(monthly: pd.Series, horizon: int):
    """
    Forecast one product: a seasonal SARIMA, the ORDER picked per product from
    SARIMA_CANDIDATES by rolling-window accuracy (_pick_sarima_order) -- or,
    when the history is too short for SARIMA or every order fails, the
    fallback (fallback_rows). Returns one dict per forecast month; [] only for
    an empty series. forecast_product_explained() also says what happened.
    """
    return forecast_product_explained(monthly, horizon)[0]


def forecast_product_explained(monthly: pd.Series, horizon: int, candidates=None):
    """
    forecast_product() plus a record of how the forecast was reached, for the
    per-product log (--log): history, whether SARIMA was attempted, whether it
    passed the sanity check, why it failed, any repair tried, the fallback used.
    """
    candidates = candidates or SARIMA_CANDIDATES
    monthly = monthly.asfreq("MS", fill_value=0)
    info = {
        **describe_series(monthly),
        "sarima_attempted": False,
        "sarima_status": "skipped",
        "sarima_order": None,
        "failure_reason": None,
        "repair": None,
        "fallback_method": None,
    }

    if len(monthly) == 0:
        info["failure_reason"] = "no history"
        return [], info

    ceiling = max(float(monthly.max()) * 2.5, float(monthly.mean()) * 4, 1.0)

    if len(monthly) < MIN_MONTHS_FOR_SARIMA:
        info["failure_reason"] = f"short history ({len(monthly)} months < {MIN_MONTHS_FOR_SARIMA})"
    else:
        info["sarima_attempted"] = True
        rows, order, reasons = _sarima_attempt(monthly, horizon, candidates, ceiling)

        # One repair, where the reason calls for it: forecasts thrown above the
        # ceiling by a few extreme months are refitted with those months capped.
        # The SAME ceiling still judges the result -- the check is not relaxed.
        if rows is None and "outliers" in info["flags"] and any("above the ceiling" in r for r in reasons):
            rows, order, retry = _sarima_attempt(_cap_outliers(monthly), horizon, candidates, ceiling)
            info["repair"] = "outliers capped: " + ("passed" if rows else "still failed")
            reasons += [f"after capping outliers: {r}" for r in retry]

        if rows is not None:
            info["sarima_status"] = "passed"
            info["sarima_order"] = f"{order[0]}{order[1]}"
            return rows, info

        info["sarima_status"] = "failed"
        info["failure_reason"] = "; ".join(dict.fromkeys(reasons)) or "no usable fit"

    rows, method = fallback_rows(monthly, horizon, ceiling, info)
    info["fallback_method"] = method
    return rows, info


def _sarima_attempt(monthly, horizon, candidates, ceiling):
    """
    The SARIMA half of forecast_product_explained: (rows, order, reasons), rows
    None when every order failed. Unchanged rule: the rolling-window winner
    first, then every candidate directly, richest first, first plausible fit wins.
    """
    dates = _future_dates(monthly, horizon)
    reasons = []

    if len(monthly) >= MIN_MONTHS_FOR_ANY_FORECAST + SARIMA_SELECTION_HOLDOUT:
        winner = _pick_sarima_order(monthly, SARIMA_SELECTION_HOLDOUT, ceiling, candidates)

        if winner:
            try:
                rows = _sarimax_rows(monthly, dates, horizon, winner[0], winner[1], _family(winner[1]))
                why = _implausible_reason([r["forecast_value"] for r in rows], ceiling)
            except Exception as exc:  # noqa: BLE001 - refit failed; try the rest below
                rows, why = None, f"fit error ({type(exc).__name__}: {exc})"

            # Judge the RAW forecast, THEN clamp. The other way round -- the
            # order this had until 2026-09-27 -- made _plausible() unable to
            # fail: clamping had already turned negatives into 0 and capped the
            # rest, so a broken fit was "repaired" and accepted. That is how
            # ALKALINSE (selling ~450/month) was forecast [0, 9360, 0].
            if rows and why is None:
                return _clamp_rows(rows, ceiling), winner, reasons
            reasons.append(f"{_order_label(winner)} refit: {why}")
        else:
            reasons.append("no order passed the rolling-window check")

    for order, seasonal_order in candidates:
        try:
            rows = _sarimax_rows(monthly, dates, horizon, order, seasonal_order, _family(seasonal_order))
        except Exception as exc:  # noqa: BLE001 - any fitting failure means "try the next order"
            reasons.append(f"{_order_label((order, seasonal_order))}: fit error ({type(exc).__name__}: {str(exc)[:60]})")
            continue

        why = _implausible_reason([r["forecast_value"] for r in rows], ceiling)
        if why is None:
            return _clamp_rows(rows, ceiling), (order, seasonal_order), reasons
        reasons.append(f"{_order_label((order, seasonal_order))}: {why}")

    return None, None, reasons


def _order_label(order_pair) -> str:
    order, seasonal_order = order_pair
    return f"SARIMA{order}{tuple(seasonal_order)}"


def describe_series(monthly: pd.Series) -> dict:
    """
    What a product's history looks like, in the terms SARIMA failures come
    from -- logged beside every forecast so problem products can be grouped:
      short_history     fewer months than MIN_MONTHS_FOR_SARIMA
      many_zero_months  half or more of its months sold nothing (intermittent)
      erratic           month-to-month spread (CV) above 1.5
      outliers          a month far above its usual sales (Tukey's 3 x IQR fence,
                        and at least 3x its median selling month)
      level_shift       the last 6 months average 3x or a third of the 12 before
      no_variation      every month the same
    """
    s = monthly.asfreq("MS", fill_value=0).astype(float)
    n = len(s)
    nonzero = s[s > 0]
    mean = float(s.mean()) if n else 0.0
    zero_share = float((s == 0).mean()) if n else 1.0
    cv = float(s.std(ddof=0) / mean) if mean > 0 else 0.0

    flags = []
    if n < MIN_MONTHS_FOR_SARIMA:
        flags.append("short_history")
    if zero_share >= INTERMITTENT_ZERO_SHARE:
        flags.append("many_zero_months")
    if cv > 1.5:
        flags.append("erratic")
    if n and s.nunique() <= 1:
        flags.append("no_variation")
    if len(nonzero) >= 4 and (nonzero > _outlier_fence(nonzero)).any():
        flags.append("outliers")
    if n >= 18:
        recent, before = float(s.iloc[-6:].mean()), float(s.iloc[-18:-6].mean())
        if (before > 0 and (recent >= 3 * before or recent <= before / 3)) or (before == 0 and recent > 0):
            flags.append("level_shift")

    return {
        "months": n,
        "nonzero_months": int(len(nonzero)),
        "zero_share": round(zero_share, 3),
        "cv": round(cv, 3),
        "flags": flags,
    }


def _outlier_fence(nonzero: pd.Series) -> float:
    q1, q3 = np.percentile(nonzero, [25, 75])
    return max(q3 + 3 * (q3 - q1), 3 * float(np.median(nonzero)))


def _cap_outliers(monthly: pd.Series) -> pd.Series:
    """The series with months above the outlier fence brought down to it."""
    nonzero = monthly[monthly > 0]
    if len(nonzero) < 4:
        return monthly
    return monthly.clip(upper=_outlier_fence(nonzero))


def fallback_rows(monthly: pd.Series, horizon: int, ceiling: float, info: dict):
    """
    The forecast for a product SARIMA cannot serve: (rows, method).

    Intermittent demand (half or more of its months at zero, at least two
    sales) gets Croston's method with the Syntetos-Boylan correction -- the
    standard estimator for it: it forecasts the RATE (average size of a sale
    over the average gap between sales) rather than chasing zeros. Anything
    else gets the average of its last 3 months, the baseline the 80/20 test
    already reports beside the model. The forecast is flat across the horizon,
    and the 80% band is the 10th-90th percentile of the product's last 12 months
    (it does not widen with distance -- neither method models that).
    """
    s = monthly.asfreq("MS", fill_value=0).astype(float)
    dates = _future_dates(s, horizon)

    if "many_zero_months" in info["flags"] and info["nonzero_months"] >= 2:
        method, value = FALLBACK_CROSTON, _croston_sba(s)
    else:
        method, value = FALLBACK_RECENT_MEAN, float(s.iloc[-3:].mean())

    recent = s.iloc[-12:].to_numpy()
    lo, hi = (float(v) for v in np.percentile(recent, [10, 90]))
    rows = [
        {"forecast_date": d, "forecast_value": value,
         "lower_ci": min(lo, value), "upper_ci": max(hi, value), "method": method}
        for d in dates
    ]
    return _clamp_rows(rows, ceiling), method


def _croston_sba(s: pd.Series, alpha: float = 0.1) -> float:
    """Croston's demand rate with the SBA bias correction: (1 - a/2) * size / interval."""
    values = s.to_numpy(dtype=float)
    sales = np.flatnonzero(values > 0)
    if len(sales) == 0:
        return 0.0
    # Started from the product's own average sale and average gap, not its
    # first sale: with a = 0.1 a short history barely moves the estimate, and a
    # first gap of 1 month put a product selling 6 units in 23 months at 1/month.
    size = float(values[sales].mean())
    interval = len(values) / len(sales)
    previous = sales[0]
    for i in sales[1:]:
        size += alpha * (values[i] - size)
        interval += alpha * ((i - previous) - interval)
        previous = i
    return (1 - alpha / 2) * size / interval


def _family(seasonal_order) -> str:
    """The method label a forecast row carries: "sarima" with a seasonal part, "arima" without."""
    return "sarima" if seasonal_order and seasonal_order[3] else "arima"


HOLDOUT_MONTHS = 3


def backtest_product(monthly: pd.Series, holdout: int = HOLDOUT_MONTHS):
    """
    Score this product's forecast against months it was not allowed to see.

    Refits the SAME SARIMA model forecast_product() uses, on the series minus
    its last `holdout` months, then compares the predictions to the months held
    back. Scoring the model actually in use is the whole point -- a metric
    taken from some other model would describe a forecast nobody is looking at.

    Returns None only when no holdout of even one month leaves a series the
    model can fit -- under MIN_MONTHS_FOR_ANY_FORECAST + 1 months of history.
    """
    monthly = monthly.asfreq('MS', fill_value=0)

    # The training half must still clear the model's own floor, or the
    # backtest measures a model the product would never actually get. A
    # product too new for the full holdout, or whose shortened series the model
    # cannot fit, holds back FEWER months -- never fewer than one -- rather than
    # going unscored (2026-10-03, at the user's request: "only 2,612 of 2,617
    # scored"). The months actually held back are recorded per product in
    # holdout_months, so a shorter test never passes for a full one.
    rows = None
    for holdout in range(min(holdout, len(monthly) - MIN_MONTHS_FOR_ANY_FORECAST), 0, -1):
        train = monthly.iloc[:-holdout]
        actual = monthly.iloc[-holdout:]
        rows = forecast_product(train, holdout)
        if rows:
            break

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
        # For WAPE (total units missed / total units sold, pooled across
        # products) and for grouping products by how fast they sell -- the
        # average over the 12 months BEFORE the holdout, so the grouping does
        # not peek at the months being scored. Added 2026-10-02.
        'abs_error': round(float(np.sum(np.abs(errors))), 4),
        'actual_units': round(float(np.sum(observed)), 4),
        'avg_monthly_units': round(float(train.iloc[-12:].mean()), 4),
    }


def forecast_store_total(series: pd.Series, horizon: int):
    """
    forecast_product() on the WHOLE STORE's units, fitted on RAW units.

    The per-product log transform is wrong for one series that grew ~55x and
    then levelled off: on the log scale that growth is a straight line, and the
    model extends it -- the 80/20 store-wide MAPE went 7.4% -> 47.6% with it.
    Products are small counts where the log helps; the store total is not.
    """
    global TRANSFORM
    saved, TRANSFORM = TRANSFORM, "none"
    try:
        return forecast_product(series, horizon)
    finally:
        TRANSFORM = saved


def storewide_backtest(monthly: pd.DataFrame, holdout: int = HOLDOUT_MONTHS):
    """
    The same model, scored on the WHOLE STORE's monthly units -- every
    product added together (2026-10-02, at the user's request). Per-product
    errors partly cancel when summed, so this answers a different, real
    question ("how much will the pharmacy sell next month?") and is labelled
    as store-wide wherever it is shown, never passed off as the per-product
    figure.
    """
    total = monthly.groupby("month")["qty"].sum().sort_index()
    total = total.reindex(pd.date_range(total.index.min(), total.index.max(), freq="MS"), fill_value=0)

    if len(total) < MIN_MONTHS_FOR_ANY_FORECAST + holdout:
        return None

    train, actual = total.iloc[:-holdout], total.iloc[-holdout:]
    rows = forecast_store_total(train, holdout)
    if not rows:
        return None

    predicted = np.array([float(r["forecast_value"]) for r in rows[:holdout]], dtype=float)
    observed = actual.to_numpy(dtype=float)[:len(predicted)]
    errors = predicted - observed
    nonzero = observed != 0

    return {
        "holdout_months": int(holdout),
        "months": [d.strftime("%Y-%m") for d in actual.index[:len(predicted)]],
        "actual": [int(round(v)) for v in observed],
        "forecast": [int(round(v)) for v in predicted],
        "mae": round(float(np.mean(np.abs(errors))), 2),
        "mape": round(float(np.mean(np.abs(errors[nonzero] / observed[nonzero])) * 100.0), 2) if nonzero.any() else None,
        "wape": round(float(np.sum(np.abs(errors)) / np.sum(observed) * 100.0), 2) if observed.sum() else None,
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
        rows, info = forecast_product_explained(series, horizon)

        # Scored in the worker, not the parent: the backtest is a second fit of
        # the same cascade, so it belongs on the pool rather than serialised
        # through one process after the fact.
        try:
            score = backtest_product(series)
        except Exception:  # noqa: BLE001 - a failed score must not lose the forecast
            score = None

        return sku, rows, score, None, info
    except Exception as exc:  # noqa: BLE001 - deliberately broad, see docstring
        # Even an unexpected crash must not drop the product: give it the
        # fallback and log the crash as the failure reason.
        error = f"{type(exc).__name__}: {exc}"
        try:
            monthly = series.asfreq("MS", fill_value=0)
            info = {**describe_series(monthly), "sarima_attempted": True, "sarima_status": "failed",
                    "sarima_order": None, "failure_reason": f"crashed: {error}", "repair": None}
            ceiling = max(float(monthly.max()) * 2.5, float(monthly.mean()) * 4, 1.0)
            rows, info["fallback_method"] = fallback_rows(monthly, horizon, ceiling, info)
            return sku, rows, None, None, info
        except Exception:  # noqa: BLE001 - nothing left to try; reported at the end
            return sku, [], None, error, None


def _log_row(sku, rows, info, error):
    """One line of the --log CSV: how this product's forecast was reached."""
    info = info or {}
    return {
        "product_sku": sku,
        "months_of_history": info.get("months"),
        "months_with_sales": info.get("nonzero_months"),
        "zero_share": info.get("zero_share"),
        "cv": info.get("cv"),
        "series_flags": " ".join(info.get("flags", [])),
        "sarima_attempted": info.get("sarima_attempted"),
        "sarima_status": info.get("sarima_status", "error" if error else None),
        "sarima_order": info.get("sarima_order"),
        "failure_reason": info.get("failure_reason") or error,
        "repair": info.get("repair"),
        "fallback_method": info.get("fallback_method"),
        "method": rows[0]["method"] if rows else None,
        "forecast_total": round(float(sum(r["forecast_value"] for r in rows)), 2) if rows else None,
        "forecast_by_month": " ".join(f"{r['forecast_date']:%Y-%m}={r['forecast_value']:g}" for r in rows),
    }


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
    parser.add_argument("--storewide", default=None,
                        help="Optional path for the store-wide holdout accuracy JSON (all products summed).")
    parser.add_argument("--log", default=None,
                        help="Optional path for the per-product forecast log CSV: history, SARIMA "
                             "attempted / passed / failed and why, fallback used, final forecast.")
    parser.add_argument("--env-path", default=None)
    parser.add_argument("--include-pos", action="store_true",
                        help="also train on the till's own (non-voided) sales")
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
        raw = load_from_mysql(args.env_path, args.include_pos)

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
    # A product can also contribute zero rows with NO exception: rows == []
    # coming out of forecast_product() means every SARIMA_CANDIDATES order
    # was either too short a history to fit or got rejected by the
    # plausibility check -- not a crash, so it never reaches `failures`, and
    # it is invisible in the output CSV since it wrote nothing at all. A
    # product that forecast fine last run and lands here this run (e.g. a
    # borderline convergence result) has its forecast_date rows then removed
    # by importCsv()'s `generated_at <` sweep with no trace anywhere of why
    # it went from "has a forecast" to "has none". Tracked separately from
    # `failures` (which are genuine exceptions) because a nonzero count here
    # is often the ordinary "too little history" case, not a fault --
    # printed as a count to check against, not an alarm.
    empty_no_error = []
    start_time = time.monotonic()
    progress_every = max(1, total_products // 100)  # ~100 progress lines total, regardless of catalog size

    metric_rows = []
    log_rows = []

    for i, (sku, rows, score, error, info) in enumerate(iter_forecasts(tasks, workers), start=1):
        if score:
            metric_rows.append({"product_sku": sku, **score})
        if error:
            failures.append((sku, error))
        elif not rows:
            empty_no_error.append(sku)
        log_rows.append(_log_row(sku, rows, info, error))
        for row in rows:
            # .get(), not [] -- a new method name in forecast_product() must not
            # abort a 2,600-product run at the write step.
            confidence = "high" if row["method"] in ("sarima", "arima") else "low"
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

    # Products that have never sold: no model can learn from an empty history,
    # so each gets the zero forecast over the SAME window as everything else.
    # Not scored -- there is nothing to test it against -- so it never enters
    # the accuracy figures.
    if args.source == "mysql":
        forecast_skus = {sku for sku, _ in products}
        never_sold = [sku for sku in catalogue_skus(args.env_path) if sku not in forecast_skus]
        for sku in never_sold:
            for row in no_history_rows(series_end + pd.offsets.MonthBegin(1), args.horizon):
                method_counts[row["method"]] += 1
                output_rows.append({
                    "product_sku": sku,
                    "forecast_date": row["forecast_date"].strftime("%Y-%m-%d"),
                    "forecast_value": row["forecast_value"],
                    "lower_ci": row["lower_ci"],
                    "upper_ci": row["upper_ci"],
                    "method": row["method"],
                    "confidence": "none",
                    "generated_at": generated_at,
                })
        print(f"No sales history: {len(never_sold)} product(s) given a zero forecast.", flush=True)

    if args.metrics:
        # Written even when empty, so the importer can tell "no products were
        # scorable" from "the run never produced a metrics file".
        metrics_df = pd.DataFrame(metric_rows, columns=[
            "product_sku", "mae", "rmse", "mape", "smape",
            "holdout_months", "points_scored", "points_scored_mape", "method",
            "abs_error", "actual_units", "avg_monthly_units",
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

    if args.storewide:
        storewide = storewide_backtest(monthly)
        if storewide:
            os.makedirs(os.path.dirname(os.path.abspath(args.storewide)), exist_ok=True)
            with open(args.storewide, "w", encoding="utf-8") as f:
                json.dump(storewide, f)
            print(f"Store-wide {HOLDOUT_MONTHS}-month holdout: MAPE {storewide['mape']}% -> {args.storewide}", flush=True)

    log_df = pd.DataFrame(log_rows)
    if args.log:
        os.makedirs(os.path.dirname(os.path.abspath(args.log)), exist_ok=True)
        log_df.to_csv(args.log, index=False)
    if len(log_df):
        status = log_df["sarima_status"].fillna("error").value_counts().to_dict()
        fallback = log_df["fallback_method"].dropna().value_counts().to_dict()
        print("SARIMA: " + " ".join(f"{k}={v}" for k, v in sorted(status.items()))
              + " | fallback used: " + (" ".join(f"{k}={v}" for k, v in sorted(fallback.items())) or "none")
              + (f" | log -> {args.log}" if args.log else ""), flush=True)
        failed = log_df[log_df["sarima_status"] == "failed"]
        for r in failed.head(20).itertuples():
            print(f"  SARIMA failed: {r.product_sku} ({r.months_of_history} months, {r.months_with_sales} with sales;"
                  f" {r.series_flags or 'no flags'}) -> {r.fallback_method}, total {r.forecast_total}:"
                  f" {str(r.failure_reason)[:160]}", flush=True)
        if len(failed) > 20:
            print(f"  ... and {len(failed) - 20} more in the log", flush=True)

    out_df = pd.DataFrame(output_rows)
    os.makedirs(os.path.dirname(args.output), exist_ok=True)
    out_df.to_csv(args.output, index=False)
    total_elapsed = time.monotonic() - start_time
    print(f"Wrote {len(out_df)} forecast rows for {out_df['product_sku'].nunique() if len(out_df) else 0} products to {args.output} in {total_elapsed:.0f}s")
    print("Method breakdown: " + " ".join(f"{k}={v}" for k, v in sorted(method_counts.items())))

    if failures:
        # Non-fatal: these products simply have no forecast rows this run.
        # Surfaced loudly so a systematic breakage isn't silently tolerated.
        print(f"WARNING: {len(failures)} product(s) failed to forecast and were skipped:", flush=True)
        for sku, error in failures[:10]:
            print(f"  - {sku}: {error}", flush=True)
        if len(failures) > 10:
            print(f"  ... and {len(failures) - 10} more", flush=True)

    if empty_no_error:
        # Not a WARNING -- see the note where empty_no_error is declared.
        # Still printed unconditionally so a run where this count jumps
        # (a product that had rows last time and has none now) is at least
        # visible in the log, even though nothing here can tell "expected,
        # too little history" apart from "a fit that used to work no longer
        # does" -- that distinction needs comparing against the previous
        # run's product list, which this script does not have.
        print(
            f"NOTE: {len(empty_no_error)} product(s) produced no forecast rows this run "
            "with no error raised. Since the fallback (2026-10-06) that should only be an "
            "empty series -- anything else is a fault to investigate in the --log CSV.",
            flush=True,
        )


if __name__ == "__main__":
    main()
