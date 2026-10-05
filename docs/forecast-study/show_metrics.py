"""Print the system's forecast figures from the local database, for a demo:

    python docs/forecast-study/show_metrics.py

Reads what forecast:generate and sales-forecast:generate last wrote (forecast_accuracy, demand_forecasts,
sales_forecasts) and computes the same summary the Forecasting page shows. Changes nothing.
"""
import os
import sys
import warnings

warnings.filterwarnings("ignore")
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "resources", "python"))
import pandas as pd  # noqa: E402
import pymysql  # noqa: E402

import generate_forecasts as gf  # noqa: E402

env = gf.db_credentials(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", ".env"))
conn = pymysql.connect(host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", 3306)),
                       user=env.get("DB_USERNAME", "root"), password=env.get("DB_PASSWORD", ""),
                       database=env["DB_DATABASE"])
acc = pd.read_sql("SELECT * FROM forecast_accuracy", conn)
demand = pd.read_sql("SELECT forecast_date, forecast_value FROM demand_forecasts", conn)
sales = pd.read_sql("SELECT forecast_date, forecast_units, forecast_revenue, method FROM sales_forecasts", conn)
conn.close()

print("=== MODEL ACCURACY (3-month holdout, per product) ===")
print(f"products scored : {len(acc):,}   (MAPE defined on {acc.mape.notna().sum():,})")
print(f"MAE   (avg)     : {acc.mae.mean():.2f} units/month")
print(f"RMSE  (avg)     : {acc.rmse.mean():.2f} units/month")
print(f"MAPE  (avg)     : {acc.mape.mean():.1f}%")
print(f"sMAPE (avg)     : {acc.smape.mean():.1f}%")
print(f"WAPE  (pooled)  : {acc.abs_error.sum() / acc.actual_units.sum() * 100:.1f}%  (total units missed / total units sold)")
print("models          : " + ", ".join(f"{m} {n:,}" for m, n in acc.method.value_counts().items()))

print("\n=== MAPE BY SALES VOLUME ===")
bands = [(0, 5, "under 5 a month"), (5, 20, "5-20 a month"), (20, 100, "20-100 a month"), (100, 1e12, "100+ a month")]
for lo, hi, name in bands:
    b = acc[(acc.avg_monthly_units >= lo) & (acc.avg_monthly_units < hi)]
    print(f"{name:16}: {len(b):5,} products  MAE {b.mae.mean():6.2f}  MAPE {b.mape.mean():5.1f}%  "
          f"WAPE {b.abs_error.sum() / b.actual_units.sum() * 100:5.1f}%")

print("\n=== DEMAND AND SALES FORECAST (all products, per month) ===")
d = demand.groupby("forecast_date").forecast_value.sum()
s = sales.groupby("forecast_date")[["forecast_units", "forecast_revenue"]].sum()
print(f"{'month':8} {'demand units':>13} {'sales units':>12} {'revenue (PHP)':>16}")
for m in d.index:
    print(f"{pd.Timestamp(m):%Y-%m}  {d[m]:13,.0f} {s.loc[m, 'forecast_units']:12,.0f} {s.loc[m, 'forecast_revenue']:16,.2f}")
print(f"{'total':8} {d.sum():13,.0f} {s.forecast_units.sum():12,.0f} {s.forecast_revenue.sum():16,.2f}")
