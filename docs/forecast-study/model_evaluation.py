"""REMEDI model evaluation table, run from a terminal:

    python docs/forecast-study/model_evaluation.py

RMSE, MAE and MAPE of the live model (SARIMA, every product, on log(1 + units)),
NOT rounded: each product's last 3 months are hidden, the model is refitted on the
months before, and its forecast is compared with what sold -- generate_forecasts.
backtest_product, the exact function forecast:generate runs to fill the live
forecast_accuracy table that the Forecasting page's Model accuracy card reads.

  MAE    mean |actual - forecast| over a product's held-back months, in units
  RMSE   sqrt(mean (actual - forecast)^2) over the same months, in units
  MAPE   mean |actual - forecast| / actual x 100 over the months that sold something

Each product's figure is kept to 4 decimal places, exactly as backtest_product returns
it and as forecast_accuracy stores it (DECIMAL(12,4) / DECIMAL(9,4)). The overall
figure is the plain average across products -- the same AVG() the live card takes --
printed as its exact sum / count and the quotient in full, never rounded.

Reads the database only; writes nothing to it. Per-product figures are saved to
docs/forecast-study/report/model_evaluation.csv for a row-by-row comparison with live.
"""
import os
import sys
import time
import warnings
from concurrent.futures import ProcessPoolExecutor
from decimal import Decimal, getcontext

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")
warnings.filterwarnings("ignore")

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "resources", "python"))
import pandas as pd  # noqa: E402

import generate_forecasts as gf  # noqa: E402

ENV = os.path.join(HERE, "..", "..", ".env")
OUT = os.path.join(HERE, "report")
BAR = "=" * 96
getcontext().prec = 40


def work(task):
    warnings.filterwarnings("ignore")
    sku, values, start = task
    s = pd.Series(values, index=pd.date_range(start, periods=len(values), freq="MS"))
    try:
        return sku, gf.backtest_product(s)
    except Exception:  # noqa: BLE001
        return sku, None


def exact_mean(values):
    """Sum and count of 4-decimal figures as exact decimals, and their quotient in full."""
    total = sum((Decimal(f"{v:.4f}") for v in values), Decimal(0))
    return total, len(values), total / len(values)


def main():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    order, seasonal_order = gf.SARIMA_CANDIDATES[0]
    label = (f"SARIMA ({','.join(map(str, order))})"
             f"({','.join(map(str, seasonal_order[:3]))}){seasonal_order[3]}")
    print(BAR)
    print("REMEDI MODEL EVALUATION -- RMSE, MAE and MAPE of the selected model, not rounded")
    print(f"Model: {label}, the live setup for every product")
    print("Software: " + " ".join(f"{k} {v}" for k, v in gf.runtime_versions().items()))
    print(BAR)

    print("\n[1/2] Loading sales data...")
    raw = gf.load_from_mysql(ENV)
    monthly = gf.monthly_series(raw)
    end = monthly["month"].max()
    print(f"      {len(raw):,} daily sales rows, {raw.product_sku.nunique():,} products, "
          f"monthly series up to {end:%b %Y} (the last complete month)")

    print("\n[2/2] Every product: last 3 months hidden, forecast, compared with what sold...")
    tasks = []
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        tasks.append((sku, s.to_numpy(float), s.index[0]))
    results, done = [], 0
    with ProcessPoolExecutor(max_workers=max(1, (os.cpu_count() or 2) - 1)) as pool:
        for sku, acc in pool.map(work, tasks, chunksize=8):
            done += 1
            if acc:
                results.append({"sku": str(sku), "rmse": acc["rmse"], "mae": acc["mae"], "mape": acc["mape"],
                                "holdout_months": acc["holdout_months"]})
            if done % 500 == 0 or done == len(tasks):
                print(f"      {done:,}/{len(tasks):,} products  ({time.time() - t0:.0f}s)", flush=True)
    per = pd.DataFrame(results)
    per.to_csv(os.path.join(OUT, "model_evaluation.csv"), index=False, float_format="%.4f")

    rmse = exact_mean(per.rmse.tolist())
    mae = exact_mean(per.mae.tolist())
    mape = exact_mean(per.mape.dropna().tolist())
    held = ", ".join(pd.date_range(end=end, periods=gf.HOLDOUT_MONTHS, freq="MS").strftime("%b %Y"))

    print("\n" + BAR)
    print("TABLE  Evaluation indicators of the selected model (average across products, not rounded)")
    print(BAR)
    print(f"Model: {label}")
    print(f"{'Metric':<10}{'Sum of products':>18}{'Products':>10}   Value (sum / products)")
    print("-" * 96)
    for name, (total, n, q) in (("RMSE", rmse), ("MAE", mae), ("MAPE (%)", mape)):
        print(f"{name:<10}{str(total):>18}{n:>10,}   {q}")
    print(BAR)
    print(f"\nProducts scored: {len(per):,} of {len(tasks):,} (MAPE on {mape[1]:,}; a product that sold "
          "nothing in its hidden months has no MAPE)")
    print(f"Hidden months: {held}")
    print(f"\nPer-product figures stored in:\n  {os.path.join(OUT, 'model_evaluation.csv')}")
    print(f"\nDone in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
