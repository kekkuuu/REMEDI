"""
REMEDI forecasting accuracy metrics -- one file, readable top to bottom.

What it does
------------
For every product it hides the last 3 complete months of sales, forecasts
those months with the SAME model the app uses (SARIMA(0,1,1)(1,0,0,12) on
log(1 + units), from resources/python/generate_forecasts.py), and compares the
forecast with what actually sold. It then reports:

    MAE    mean absolute error          mean(|actual - forecast|)            in units
    RMSE   root mean squared error      sqrt(mean((actual - forecast)^2))    in units
    MAPE   mean absolute % error        mean(|actual - forecast| / actual) x 100
           (months that sold 0 are skipped: dividing by 0 is impossible)
    sMAPE  symmetric MAPE               mean(2|actual - forecast| / (|actual| + |forecast|)) x 100
           (still works when a product sold nothing; 0 vs 0 counts as no error)
    WAPE   weighted absolute % error    sum(|actual - forecast|) / sum(actual) x 100

Each product is scored on its own months first; the headline MAE / RMSE / MAPE /
sMAPE are the AVERAGE across products (every product counts equally), which is
what the app's Forecasting page shows. WAPE is pooled over every product-month.
Per-product figures are kept to 4 decimals, as the app stores them, so the
results match the app exactly.

How to run
----------
    python docs/forecast-study/forecast_accuracy.py
        reads the newest storage/app/exports/sales_history_*.csv
    python docs/forecast-study/forecast_accuracy.py --csv path/to/sales_history.csv
        any CSV with the columns product_sku, sale_date, quantity_sold
    python docs/forecast-study/forecast_accuracy.py --mysql
        reads the database named in .env instead

Needs: pandas, numpy, statsmodels (pip install -r resources/python/requirements.txt).
Writes: docs/forecast-study/report/forecast_accuracy_by_product.csv
"""
import argparse
import glob
import math
import os
import sys
import time
import warnings
from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(REPO, "resources", "python"))
import generate_forecasts as gf  # noqa: E402  -- the app's own model

HOLDOUT_MONTHS = gf.HOLDOUT_MONTHS          # 3 months hidden and forecast
OUT = os.path.join(HERE, "report", "forecast_accuracy_by_product.csv")
LINE = "=" * 78


# --------------------------------------------------------------------------
# The metrics, one function each
# --------------------------------------------------------------------------

def mae(actual, forecast):
    """Mean absolute error: the average miss, in units."""
    return float(np.mean(np.abs(actual - forecast)))


def rmse(actual, forecast):
    """Root mean squared error: like MAE, but large misses count for more."""
    return float(np.sqrt(np.mean((actual - forecast) ** 2)))


def mape(actual, forecast):
    """Mean absolute percentage error, over the months that sold something.

    None when every month sold 0 -- there is nothing to divide by.
    """
    sold = actual != 0
    if not sold.any():
        return None
    return float(np.mean(np.abs(actual[sold] - forecast[sold]) / actual[sold]) * 100)


def smape(actual, forecast):
    """Symmetric MAPE: divides by actual + forecast, so it works at zero sales."""
    denominator = np.abs(actual) + np.abs(forecast)
    terms = np.where(denominator == 0, 0.0, 2 * np.abs(actual - forecast) / np.where(denominator == 0, 1, denominator))
    return float(np.mean(terms) * 100)


def grade(mape_value, smape_value):
    """The app's grade (App\\Support\\ForecastGrade): MAPE at 20 / 50 %, or sMAPE at 40 / 90 % where MAPE is undefined."""
    if mape_value is not None:
        return "Normal" if mape_value <= 20 else "Acceptable" if mape_value <= 50 else "Not acceptable"
    if smape_value is not None:
        return "Normal" if smape_value <= 40 else "Acceptable" if smape_value <= 90 else "Not acceptable"
    return "Not rated"


# --------------------------------------------------------------------------
# Data
# --------------------------------------------------------------------------

def load_sales(args) -> pd.DataFrame:
    """Daily sales as columns date, product_sku, qty."""
    if args.mysql:
        print("      source: the database in .env")
        return gf.load_from_mysql(os.path.join(REPO, ".env"))

    path = args.csv
    if not path:
        found = sorted(glob.glob(os.path.join(REPO, "storage", "app", "exports", "sales_history_*.csv")), key=os.path.getmtime)
        if not found:
            sys.exit("No CSV given and none in storage/app/exports. Use --csv <file> or --mysql.")
        path = found[-1]
    print(f"      source: {path}")
    df = pd.read_csv(path, dtype={"product_sku": str}, usecols=["product_sku", "sale_date", "quantity_sold"])
    return pd.DataFrame({"date": pd.to_datetime(df["sale_date"]), "product_sku": df["product_sku"],
                         "qty": df["quantity_sold"].astype(float)})


def product_series(daily: pd.DataFrame):
    """One monthly series per product, every month present (0 where nothing sold),
    ending at the last COMPLETE month -- a half month would read as a collapse."""
    monthly = gf.monthly_series(daily)
    end = monthly["month"].max()
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        yield str(sku), s.to_numpy(float), s.index[0]


# --------------------------------------------------------------------------
# Hide the last months, forecast them, score them
# --------------------------------------------------------------------------

def score_product(task):
    """Exactly what the app does (generate_forecasts.backtest_product): hide the
    last 3 months, forecast them from the months before; a product too short
    for that is tested on fewer months (never fewer than 1)."""
    warnings.filterwarnings("ignore")
    sku, values, start = task
    series = pd.Series(values, index=pd.date_range(start, periods=len(values), freq="MS"))

    rows = None
    for holdout in range(min(HOLDOUT_MONTHS, len(series) - gf.MIN_MONTHS_FOR_ANY_FORECAST), 0, -1):
        train, hidden = series.iloc[:-holdout], series.iloc[-holdout:]
        try:
            rows = gf.forecast_product(train, holdout)
        except Exception:  # noqa: BLE001
            rows = None
        if rows:
            break
    if not rows:
        return None  # too little history to test

    forecast = np.array([float(r["forecast_value"]) for r in rows[:holdout]])   # whole units, as the app stores them
    actual = hidden.to_numpy(float)[:len(forecast)]
    four = lambda v: None if v is None else round(v, 4)                          # noqa: E731  4 decimals, like the app
    # Graded from the 4-decimal figures, as the app grades what it stored: an
    # exact 20 % computes as 20.000000000000004 and would otherwise read as
    # "Acceptable" while the app says "Normal".
    m, s = four(mape(actual, forecast)), four(smape(actual, forecast))
    return {
        "sku": sku,
        "months_tested": len(forecast),
        "first_month": hidden.index[0].strftime("%Y-%m"),
        "last_month": hidden.index[len(forecast) - 1].strftime("%Y-%m"),
        "actual_units": float(actual.sum()),
        "forecast_units": float(forecast.sum()),
        "abs_error": float(np.abs(actual - forecast).sum()),
        # How busy the product is, from the 12 months BEFORE the ones checked
        # (as the app does), so a bad forecast cannot move it to another band.
        "avg_monthly_units": round(float(train.iloc[-12:].mean()), 4),
        "mae": four(mae(actual, forecast)),
        "rmse": four(rmse(actual, forecast)),
        "mape": m,
        "smape": s,
        "grade": grade(m, s),
    }


def main():
    parser = argparse.ArgumentParser(description="REMEDI forecasting accuracy metrics")
    parser.add_argument("--csv", help="sales history CSV (product_sku, sale_date, quantity_sold)")
    parser.add_argument("--mysql", action="store_true", help="read the database in .env instead of a CSV")
    parser.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) - 1))
    args = parser.parse_args()

    t0 = time.time()
    print(LINE)
    print("REMEDI FORECASTING ACCURACY METRICS")
    print("Model: SARIMA(0,1,1)(1,0,0,12) on log(1 + units) -- the app's own model")
    print(LINE)

    print("\n[1/3] Loading sales history...")
    daily = load_sales(args)
    print(f"      {len(daily):,} daily rows, {daily.product_sku.nunique():,} products, "
          f"{daily.date.min():%d %b %Y} to {daily.date.max():%d %b %Y}")

    print("\n[2/3] Hiding each product's last 3 complete months and forecasting them...")
    tasks = list(product_series(daily))
    results = []
    with ProcessPoolExecutor(max_workers=args.workers) as pool:
        for i, r in enumerate(pool.map(score_product, tasks, chunksize=8), 1):
            if r:
                results.append(r)
            if i % 500 == 0 or i == len(tasks):
                print(f"      {i:,}/{len(tasks):,} products  ({time.time() - t0:.0f}s)", flush=True)

    df = pd.DataFrame(results)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    df.to_csv(OUT, index=False)

    scored = len(df)
    with_mape = df["mape"].notna()
    months = sorted(set(df["first_month"]) | set(df["last_month"]))

    print("\n[3/3] RESULTS")
    print(LINE)
    print(f"Products in the data:     {len(tasks):,}")
    print(f"Products scored:          {scored:,}   ({len(tasks) - scored:,} have too little sales history to test)")
    print(f"Months checked:           {months[0]} to {months[-1]}   "
          f"({int((df.months_tested < HOLDOUT_MONTHS).sum()):,} newer products on fewer months)")
    print(LINE)
    print(f"MAE   = {df.mae.sum():,.4f} / {scored:,} products = {df.mae.mean():.4f} units")
    print(f"RMSE  = {df.rmse.sum():,.4f} / {scored:,} products = {df.rmse.mean():.4f} units")
    print(f"MAPE  = {df.mape[with_mape].sum():,.4f} / {int(with_mape.sum()):,} products = {df.mape[with_mape].mean():.4f} %"
          f"   ({int((~with_mape).sum())} sold nothing in the months checked: no MAPE)")
    print(f"Median MAPE                                   = {df.mape[with_mape].median():.4f} %")
    print(f"sMAPE = {df.smape.sum():,.4f} / {scored:,} products = {df.smape.mean():.4f} %")
    print(f"WAPE  = {df.abs_error.sum():,.0f} units missed / {df.actual_units.sum():,.0f} units sold = "
          f"{df.abs_error.sum() / df.actual_units.sum() * 100:.4f} %")
    print(LINE)

    print("Grades (MAPE: Normal <= 20 %, Acceptable <= 50 %, Not acceptable above;"
          " sMAPE 40 / 90 % where MAPE is undefined):")
    for g in ["Normal", "Acceptable", "Not acceptable", "Not rated"]:
        print(f"  {g:<16}{int((df.grade == g).sum()):>7,}")

    print("\nBy sales volume (average units a month over the 12 months before the months checked):")
    avg = df.avg_monthly_units
    bands = [("under 5 a month", avg < 5), ("5-20 a month", (avg >= 5) & (avg < 20)),
             ("20-100 a month", (avg >= 20) & (avg < 100)), ("100+ a month", avg >= 100)]
    for label, mask in bands:
        part = df[mask]
        if len(part):
            mp = part.mape.dropna()
            print(f"  {label:<16}{len(part):>7,} products   MAE {part.mae.mean():7.2f}   "
                  f"MAPE {mp.mean() if len(mp) else math.nan:6.2f} %")

    print(LINE)
    print(f"Per-product results: {OUT}")
    print(f"Done in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
