"""
Does the Forecasting page show what the terminal report printed?

Reads the two tables the app shows (demand_forecasts, and sales_forecasts --
the page's store-wide chart and 3-month cards) and the terminal report's
report/overall_demand_sales_forecast.csv, and prints them side by side.

    python docs/forecast-study/forecast_report.py
    python docs/forecast-study/compare_page_terminal.py
"""
import os
import sys

import pandas as pd
import pymysql

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "resources", "python"))
import generate_forecasts as gf  # noqa: E402

ENV = os.path.join(HERE, "..", "..", ".env")
REPORT = os.path.join(HERE, "report", "overall_demand_sales_forecast.csv")

e = gf.db_credentials(ENV)
conn = pymysql.connect(host=e.get("DB_HOST", "127.0.0.1"), port=int(e.get("DB_PORT", 3306)),
                       user=e.get("DB_USERNAME"), password=e.get("DB_PASSWORD"), database=e.get("DB_DATABASE"))
rows = pd.read_sql("""
    SELECT DATE_FORMAT(s.forecast_date, '%Y-%m') AS month,
           SUM(d.forecast_value) AS demand_units, SUM(s.forecast_units) AS page_units,
           SUM(s.forecast_revenue) AS page_revenue,
           SUM(d.forecast_value <> s.forecast_units) AS rows_differ, COUNT(*) AS n
    FROM sales_forecasts s
    JOIN demand_forecasts d ON d.product_sku = s.product_sku AND d.forecast_date = s.forecast_date
    GROUP BY month ORDER BY month""", conn)
conn.close()

term = pd.read_csv(REPORT, encoding="utf-8-sig").rename(columns={"demand_units": "term_units", "forecast_revenue": "term_revenue"})
t = rows.merge(term, on="month", how="outer")

line = "=" * 92
print(line)
print("FORECASTING PAGE vs TERMINAL REPORT")
print(line)
print(f"{'Month':8} {'Terminal units':>14} {'Page units':>11} {'Demand table':>13} "
      f"{'Terminal revenue':>17} {'Page revenue':>15}  Match")
for r in t.itertuples():
    ok = (round(r.term_units) == round(r.page_units) == round(r.demand_units)
          and round(r.term_revenue, 2) == round(r.page_revenue, 2))
    print(f"{r.month:8} {r.term_units:>14,.0f} {r.page_units:>11,.0f} {r.demand_units:>13,.0f} "
          f"{r.term_revenue:>17,.2f} {r.page_revenue:>15,.2f}  {'YES' if ok else 'NO'}")
print(f"{'TOTAL':8} {t.term_units.sum():>14,.0f} {t.page_units.sum():>11,.0f} {t.demand_units.sum():>13,.0f} "
      f"{t.term_revenue.sum():>17,.2f} {t.page_revenue.sum():>15,.2f}")
print(line)
three = t[t.month.isin(["2026-11", "2026-12", "2027-01"])]
print(f"Page cards (Nov-Jan): {three.page_units.sum():,.0f} units / PHP {three.page_revenue.sum():,.2f}")
print(f"Product-months compared: {int(t.n.sum()):,}   with different units: {int(t.rows_differ.sum())}")
print(line)
