"""REMEDI category-level forecast accuracy test, run from a terminal:

    python docs/forecast-study/category_report.py

The same test as forecast_report.py -- each product's last 3 months are hidden, the live
model (resources/python/generate_forecasts.py) forecasts them from the months before --
but the forecasts and the actual sales are then ADDED UP per category and month, and MAPE
is taken on those totals -- with MAE and RMSE (in units) beside it. Per-product misses
largely cancel inside a category, which is what this measures.

  [1/4] Loading sales data and categories
  [2/4] Building monthly demand series
  [3/4] Forecasting every product's hidden months
  [4/4] CATEGORY RESULTS  -> docs/forecast-study/report/category_accuracy.csv

Reads the database only; writes nothing to it.
"""
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
BAR = "=" * 78


def work(task):
    """One product's hidden months: backtest_product()'s rule, keeping each month's numbers."""
    warnings.filterwarnings("ignore")
    sku, values, start = task
    s = pd.Series(values, index=pd.date_range(start, periods=len(values), freq="MS"))
    rows, holdout = None, 0
    for holdout in range(min(gf.HOLDOUT_MONTHS, len(s) - gf.MIN_MONTHS_FOR_ANY_FORECAST), 0, -1):
        try:
            rows = gf.forecast_product(s.iloc[:-holdout], holdout)
        except Exception:  # noqa: BLE001
            rows = None
        if rows:
            break
    if not rows:
        return []
    actual = s.iloc[-holdout:]
    return [(sku, d.strftime("%Y-%m"), float(r["forecast_value"]), float(a))
            for d, a, r in zip(actual.index, actual.to_numpy(), rows)]


def main():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    print(BAR)
    print("REMEDI CATEGORY-LEVEL FORECAST ACCURACY TEST")
    print("Each product's last 3 months hidden, forecast, then added up per category")
    print(BAR)

    print("\n[1/4] Loading sales data and categories...")
    raw = gf.load_from_mysql(ENV)
    import pymysql
    e = gf.db_credentials(ENV)
    conn = pymysql.connect(host=e.get("DB_HOST", "127.0.0.1"), port=int(e.get("DB_PORT", 3306)),
                           user=e.get("DB_USERNAME", "root"), password=e.get("DB_PASSWORD", ""),
                           database=e["DB_DATABASE"])
    cats = pd.read_sql("SELECT p.sku, COALESCE(c.name, 'Uncategorised') AS category "
                       "FROM products p LEFT JOIN categories c ON c.id = p.category_id", conn)
    conn.close()
    cats["sku"] = cats["sku"].astype(str)
    print(f"      {len(raw):,} daily sales rows, {raw.product_sku.nunique():,} products, "
          f"{cats.category.nunique()} categories")

    print("\n[2/4] Building monthly demand series...")
    monthly = gf.monthly_series(raw)
    end = monthly["month"].max()
    tasks = []
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        tasks.append((str(sku), s.to_numpy(float), s.index[0]))
    print(f"      {len(tasks):,} product series, last complete month {end:%b %Y}")

    print("\n[3/4] Forecasting every product's hidden months...")
    out, done = [], 0
    with ProcessPoolExecutor(max_workers=max(1, (os.cpu_count() or 2) - 1)) as pool:
        for res in pool.map(work, tasks, chunksize=8):
            out.extend(res)
            done += 1
            if done % 500 == 0 or done == len(tasks):
                print(f"      {done:,}/{len(tasks):,} products  ({time.time() - t0:.0f}s)", flush=True)

    h = pd.DataFrame(out, columns=["sku", "month", "forecast", "actual"]).merge(cats, on="sku", how="left")
    h["category"] = h["category"].fillna("Uncategorised")
    g = h.groupby(["category", "month"]).agg(forecast=("forecast", "sum"), actual=("actual", "sum"),
                                              products=("sku", "nunique")).reset_index()

    print("\n[4/4] CATEGORY RESULTS")
    print(BAR)
    print("Per month: actual units sold vs forecast, and the miss as a % of actual\n")
    rows = []
    for cat, d in g.groupby("category"):
        d = d[d.actual > 0].sort_values("month")
        err = (d.forecast - d.actual).abs()
        miss = err / d.actual * 100
        mae, rmse = float(err.mean()), float(np.sqrt((err ** 2).mean()))
        rows.append({"category": cat, "products": int(d.products.max()),
                     "units_per_month": float(d.actual.mean()),
                     "mae": mae, "rmse": rmse, "mape": float(miss.mean()),
                     "wape": float(err.sum() / d.actual.sum() * 100)})
        print(f"  {cat}  ({int(d.products.max()):,} products)")
        for (_, r), e_, m in zip(d.iterrows(), err, miss):
            print(f"      {r.month}   actual {r.actual:>9,.0f}   forecast {r.forecast:>9,.0f}"
                  f"   off by {e_:>7,.0f} units   miss {m:6.2f}%")
        n = len(err)
        print(f"      MAE  = ({' + '.join(f'{v:,.0f}' for v in err)}) / {n} = {mae:,.2f} units")
        print(f"      RMSE = sqrt(({' + '.join(f'{v:,.0f}^2' for v in err)}) / {n}) = {rmse:,.2f} units")
        print(f"      MAPE = ({' + '.join(f'{m:.2f}' for m in miss)}) / {n} = {miss.mean():.2f}%\n")

    cr = pd.DataFrame(rows).sort_values("units_per_month", ascending=False)
    print(BAR)
    print(f"  {'Category':28} {'Products':>9} {'Units/month':>12} {'MAE':>9} {'RMSE':>9} {'MAPE':>8}  Reading")
    for _, r in cr.iterrows():
        reading = ("highly accurate" if r.mape < 10 else "good" if r.mape <= 20
                   else "reasonable" if r.mape <= 50 else "inaccurate")
        print(f"  {r.category:28} {r.products:9,} {r.units_per_month:12,.0f} {r.mae:9,.2f} {r.rmse:9,.2f}"
              f" {r.mape:7.2f}%  {reading}")
    weighted = np.average(cr.mape, weights=cr.units_per_month)
    print(BAR)
    print(f"Category MAPE, weighted by units sold = {weighted:.2f}%")
    print(f"Category MAPE, simple average of {len(cr)}  = {cr.mape.mean():.2f}%")
    print(f"Categories under 10% (highly accurate) = {int((cr.mape < 10).sum())} of {len(cr)}")
    print("MAE and RMSE are in UNITS per category per month, so they grow with the category's size:")
    print(f"  average category MAE  = {cr.mae.mean():,.2f} units"
          f"   ({cr.mae.sum() / cr.units_per_month.sum() * 100:.2f}% of units sold)")
    print(f"  average category RMSE = {cr.rmse.mean():,.2f} units")

    t = h.groupby("month").agg(forecast=("forecast", "sum"), actual=("actual", "sum"))
    t_err = (t.forecast - t.actual).abs()
    store = float((t_err / t.actual).mean() * 100)
    h["err"] = (h.forecast - h.actual).abs()
    per = h.groupby("sku")["err"].agg(mae="mean", rmse=lambda v: float(np.sqrt((v ** 2).mean())))
    p = h[h.actual > 0].assign(pct=lambda d: d.err / d.actual * 100).groupby("sku")["pct"].mean()
    print("\nFor comparison, on the same hidden months:")
    print(f"  {'level':38} {'MAE (units)':>12} {'RMSE (units)':>13} {'MAPE':>8}")
    print(f"  {'per product (average of ' + format(len(per), ',') + ')':38} "
          f"{per['mae'].mean():12,.2f} {per['rmse'].mean():13,.2f} {p.mean():7.2f}%")
    print(f"  {'per category (average of ' + str(len(cr)) + ')':38} "
          f"{cr.mae.mean():12,.2f} {cr.rmse.mean():13,.2f} {cr.mape.mean():7.2f}%")
    print(f"  {'per category (weighted by units)':38} {'':12} {'':13} {weighted:7.2f}%")
    print(f"  {'whole store (all products added up)':38} "
          f"{t_err.mean():12,.2f} {float(np.sqrt((t_err ** 2).mean())):13,.2f} {store:7.2f}%")
    print(BAR)

    path = os.path.join(OUT, "category_accuracy.csv")
    cr.round(4).to_csv(path, index=False, encoding="utf-8-sig")
    print(f"\nCategory accuracy stored in:\n  {os.path.abspath(path)}")
    print(f"\nDone in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
