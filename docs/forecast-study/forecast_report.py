"""REMEDI forecasting report, run from a terminal:

    python docs/forecast-study/forecast_report.py

Runs the live model (resources/python/generate_forecasts.py: seasonal SARIMA, with the fallback,
on log(1 + units)) on every product in the local database, then prints and saves:

  [1/5] Loading sales data
  [2/5] Building monthly demand series
  [3/5] Forecasting every product (demand, then revenue = units x selling price)
  [4/5] Computing overall forecasting accuracy (last 3 months hidden per product)
  [5/5] FINAL RESULTS  -> CSV files in docs/forecast-study/report/
        including each product's ACF and PACF (lags 1-12) and what they suggest

ACF / PACF are reported, not used to choose the SARIMA order: letting them
choose measured worse (see generate_forecasts.forecast_product_explained).

Reads the database only; writes nothing to it.
"""
import math
import os
import sys
import time
import warnings
from concurrent.futures import ProcessPoolExecutor

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")
warnings.filterwarnings("ignore")

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "resources", "python"))
import numpy as np  # noqa: E402
import pandas as pd  # noqa: E402

import generate_forecasts as gf  # noqa: E402

ENV = os.path.join(HERE, "..", "..", ".env")
OUT = os.path.join(HERE, "report")
HORIZON = 6
BAR = "=" * 72


def work(task):
    """One product: the 6-month forecast and the 3-month accuracy test."""
    warnings.filterwarnings("ignore")
    sku, values, start = task
    s = pd.Series(values, index=pd.date_range(start, periods=len(values), freq="MS"))
    try:
        rows, info = gf.forecast_product_explained(s, HORIZON)
    except Exception:  # noqa: BLE001
        rows, info = [], {}
    try:
        acc = gf.backtest_product(s)
    except Exception:  # noqa: BLE001
        acc = None
    return sku, rows, acc, info, acf_row(s, info)


def acf_row(s, info):
    """This product's ACF and PACF (lags 1-12), the cut-offs read from them and what they suggest."""
    if len(s) < gf.MIN_MONTHS_FOR_SARIMA:
        return None
    values = gf.acf_pacf_values(s)
    if values is None:
        return None
    r, phi, band = values
    suggested, _ = gf.identify_orders(s)
    chosen = info.get("sarima_order")
    row = {"band_95": round(band, 4),
           "pacf_cutoff_p": gf._leading_significant(phi, band, gf.ACF_MAX_P),
           "acf_cutoff_q": gf._leading_significant(r, band, gf.ACF_MAX_Q),
           "acf_lag12_spike": len(r) > 12 and abs(r[12]) > band,
           "pacf_lag12_spike": len(phi) > 12 and abs(phi[12]) > band,
           "suggested_orders": " ".join(f"{o}{s_}" for o, s_ in suggested),
           "chosen_order": chosen,
           "chosen_is_suggested": chosen in {f"{o}{s_}" for o, s_ in suggested}}
    for lag in range(1, 13):
        row[f"acf_{lag}"] = round(float(r[lag]), 4) if lag < len(r) else None
    for lag in range(1, 13):
        row[f"pacf_{lag}"] = round(float(phi[lag]), 4) if lag < len(phi) else None
    return row


def main():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    print(BAR)
    print("REMEDI DEMAND AND SALES FORECAST REPORT")
    print("Model: seasonal SARIMA on log(1 + units); fallback (Croston / recent mean) under 24 months or if SARIMA fails")
    print(BAR)

    print("\n[1/5] Loading sales data...")
    raw = gf.load_from_mysql(ENV)
    import pymysql
    e = gf.db_credentials(ENV)
    conn = pymysql.connect(host=e.get("DB_HOST", "127.0.0.1"), port=int(e.get("DB_PORT", 3306)),
                           user=e.get("DB_USERNAME", "root"), password=e.get("DB_PASSWORD", ""),
                           database=e["DB_DATABASE"])
    products = pd.read_sql("SELECT sku, name, selling_price FROM products", conn).set_index("sku")
    conn.close()
    print(f"      {len(raw):,} daily sales rows, {raw.product_sku.nunique():,} products, "
          f"{raw.date.min():%d %b %Y} to {raw.date.max():%d %b %Y}")

    print("\n[2/5] Building monthly demand series...")
    monthly = gf.monthly_series(raw)
    end = monthly["month"].max()
    tasks = []
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        tasks.append((sku, s.to_numpy(float), s.index[0]))
    print(f"      {len(tasks):,} product series, last complete month {end:%b %Y} "
          f"(the incomplete {raw.date.max():%b %Y} is left out)")

    print(f"\n[3/5] Forecasting every product ({HORIZON} months ahead)...")
    workers = max(1, (os.cpu_count() or 2) - 1)
    results, done = [], 0
    with ProcessPoolExecutor(max_workers=workers) as pool:
        for res in pool.map(work, tasks, chunksize=8):
            results.append(res)
            done += 1
            if done % 250 == 0 or done == len(tasks):
                print(f"      {done:,}/{len(tasks):,} products  ({time.time() - t0:.0f}s)", flush=True)

    fc_rows, acc_rows, acf_rows = [], [], []
    for sku, rows, acc, info, acf_info in results:
        name = products.name.get(sku, "")
        if acf_info:
            acf_rows.append({"sku": sku, "product": name, **acf_info})
        price = float(products.selling_price.get(sku, 0) or 0)
        for r in rows:
            fc_rows.append({"sku": sku, "product": name, "month": r["forecast_date"].strftime("%Y-%m"),
                            "model": r["method"], "forecast_units": r["forecast_value"],
                            "lower_80": r["lower_ci"], "upper_80": r["upper_ci"], "selling_price": price,
                            "forecast_revenue": round(r["forecast_value"] * price, 2)})
        if acc:
            acc_rows.append({"sku": sku, "product": name, "model": acc["method"], **{k: acc[k] for k in (
                "mae", "rmse", "mape", "smape", "abs_error", "actual_units", "avg_monthly_units", "holdout_months")}})
    fc = pd.DataFrame(fc_rows)
    ac = pd.DataFrame(acc_rows)
    af = pd.DataFrame(acf_rows)

    print("\n[4/5] Computing overall forecasting accuracy...")
    n = len(ac)
    sum_mae, sum_rmse = ac.mae.sum(), ac.rmse.sum()
    mape_ok = ac.mape.dropna()
    mae, rmse, mape = sum_mae / n, sum_rmse / n, mape_ok.sum() / len(mape_ok)
    wape = ac.abs_error.sum() / ac.actual_units.sum() * 100

    print("\n[5/5] FINAL RESULTS")
    print(BAR)
    print(f"Total products: {n:,}   (" + ", ".join(f"{m} {c:,}" for m, c in ac.model.value_counts().items()) + ")")
    print("Test: each product's last 3 months hidden, forecast, compared with actual\n")
    print(f"MAE  = {sum_mae:,.4f} / {n:,}")
    print(f"Overall MAE  = {mae:.2f} units\n")
    print(f"RMSE = {sum_rmse:,.4f} / {n:,}")
    print(f"Overall RMSE = {rmse:.2f} units\n")
    print(f"MAPE = {mape_ok.sum():,.4f} / {len(mape_ok):,}")
    print(f"Overall MAPE = {mape:.2f}%")
    print(f"Products excluded from MAPE: {n - len(mape_ok)}  (sold 0 in every test month)\n")
    print(f"WAPE = {ac.abs_error.sum():,.0f} / {ac.actual_units.sum():,.0f} x 100")
    print(f"Overall WAPE = {wape:.2f}%  (total units missed / total units sold)")
    print(BAR)

    print("\nMAPE by sales volume:")
    for lo, hi, nm in [(0, 5, "under 5 a month"), (5, 20, "5-20 a month"), (20, 100, "20-100 a month"),
                       (100, 1e12, "100+ a month")]:
        b = ac[(ac.avg_monthly_units >= lo) & (ac.avg_monthly_units < hi)]
        print(f"  {nm:16} {len(b):6,} products   MAE {b.mae.mean():6.2f}   MAPE {b.mape.mean():6.2f}%")

    print("\nACF / PACF (log(1 + units), first-differenced; significant = outside +/-1.96/sqrt(n)):")
    print(f"  {len(af):,} products with 24+ months analysed")
    for col, label in [("pacf_cutoff_p", "PACF cut-off (AR order p)"), ("acf_cutoff_q", "ACF cut-off (MA order q)")]:
        counts = af[col].value_counts().sort_index()
        print(f"  {label:27} " + "   ".join(f"{k}: {v:,}" for k, v in counts.items()))
    print(f"  Lag 12 spike in the ACF      {int(af.acf_lag12_spike.sum()):,} products  (seasonal MA)")
    print(f"  Lag 12 spike in the PACF     {int(af.pacf_lag12_spike.sum()):,} products  (seasonal AR)")
    print(f"  Lag 12 spike in either       {int((af.acf_lag12_spike | af.pacf_lag12_spike).sum()):,} products")
    print(f"  Order chosen was one ACF/PACF suggested: {int(af.chosen_is_suggested.sum()):,} of {len(af):,}")
    print("  (ACF/PACF are recorded; the order is chosen by accuracy on unseen months)")

    total = fc.groupby("month").agg(demand_units=("forecast_units", "sum"),
                                    forecast_revenue=("forecast_revenue", "sum")).reset_index()
    print("\nDemand and sales forecast (all products):")
    print(f"  {'Month':8} {'Demand units':>14} {'Revenue (PHP)':>18}")
    for _, r in total.iterrows():
        print(f"  {r.month:8} {r.demand_units:>14,.0f} {r.forecast_revenue:>18,.2f}")
    print(f"  {'TOTAL':8} {total.demand_units.sum():>14,.0f} {total.forecast_revenue.sum():>18,.2f}")
    print(BAR)

    files = {
        "product_forecast.csv": fc,
        "product_accuracy.csv": ac,
        "product_acf_pacf.csv": af,
        "overall_demand_sales_forecast.csv": total,
        "overall_metrics.csv": pd.DataFrame([{
            "products": n, "sum_mae": round(sum_mae, 4), "mae": round(mae, 4), "sum_rmse": round(sum_rmse, 4),
            "rmse": round(rmse, 4), "sum_mape": round(mape_ok.sum(), 4), "mape_products": len(mape_ok),
            "mape": round(mape, 4), "excluded_from_mape": n - len(mape_ok), "wape": round(wape, 4)}]),
    }
    for fname, df in files.items():
        df.to_csv(os.path.join(OUT, fname), index=False, encoding="utf-8-sig")
    print("\nMonthly forecasts per product stored in:")
    print(f"  {os.path.abspath(os.path.join(OUT, 'product_forecast.csv'))}")
    print("Accuracy per product stored in:")
    print(f"  {os.path.abspath(os.path.join(OUT, 'product_accuracy.csv'))}")
    print("ACF and PACF per product stored in:")
    print(f"  {os.path.abspath(os.path.join(OUT, 'product_acf_pacf.csv'))}")
    print("Overall accuracy stored in:")
    print(f"  {os.path.abspath(os.path.join(OUT, 'overall_metrics.csv'))}")
    print("Overall demand and revenue forecast stored in:")
    print(f"  {os.path.abspath(os.path.join(OUT, 'overall_demand_sales_forecast.csv'))}")
    print(f"\nDone in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
