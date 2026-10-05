#!/usr/bin/env python3
"""
Demand forecast and sales forecast for every product with ONE seasonal SARIMA model,
SARIMA(1,1,1)(0,1,1,12) -- the model on the Forecasting page's monthly card -- at the user's request.

    python sarima_product_forecast.py --env ../../.env --out <folder> --workers 12

Data: the app's own database (sales_history, the same loader forecast:generate uses), every complete
month, each product's series from its first sale to the shared last complete month (zeros where it
sold nothing). Units, no transformation. Forecast: the 6 months after the last complete month, with an
80% interval, rounded to whole units, never below zero.
Sales forecast = forecast units x the product's current selling price (how the app prices revenue).
Accuracy: each product's model refitted without its last 3 months and scored on them (MAE, MAPE).
A product with fewer than 14 months of history cannot fit this model and is marked so -- no other
model is substituted. A product that has never sold is forecast 0. The app is not changed.
"""
import argparse
import os
import sys
import warnings
from concurrent.futures import ProcessPoolExecutor

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")
warnings.filterwarnings("ignore")

import numpy as np
import pandas as pd

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "resources", "python"))
import generate_forecasts as gf  # noqa: E402

ORDER, SEASONAL = (1, 1, 1), (0, 1, 1, 12)
HORIZON, HOLDOUT, MIN_MONTHS = 6, 3, 14
MODEL = "SARIMA(1,1,1)(0,1,1,12)"


def fit_forecast(y, h):
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        res = SARIMAX(np.asarray(y, float), order=ORDER, seasonal_order=SEASONAL,
                      enforce_stationarity=True, enforce_invertibility=True).fit(disp=False, maxiter=300)
    p = res.get_forecast(h)
    mean = np.asarray(p.predicted_mean, float)
    ci = np.asarray(p.conf_int(alpha=0.2), float)
    if not (np.all(np.isfinite(mean)) and np.all(np.isfinite(ci)) and res.params[-1] > 0):
        raise ValueError("degenerate fit")
    return mean, ci


def task(args):
    sku, values = args
    out = {"sku": sku, "status": "ok", "forecast": None, "holdout": None}
    if len(values) < MIN_MONTHS:
        out["status"] = f"too little history ({len(values)} months; needs {MIN_MONTHS})"
        return out
    try:
        mean, ci = fit_forecast(values, HORIZON)
        out["forecast"] = (np.maximum(np.round(mean), 0), np.maximum(np.round(ci[:, 0]), 0), np.maximum(np.round(ci[:, 1]), 0))
    except Exception as exc:  # noqa: BLE001
        out["status"] = f"fit failed: {str(exc)[:60]}"
        return out
    if len(values) - HOLDOUT >= MIN_MONTHS:
        try:
            m, _ = fit_forecast(values[:-HOLDOUT], HOLDOUT)
            a, f = np.asarray(values[-HOLDOUT:], float), np.maximum(np.round(m), 0)
            nz = a > 0
            out["holdout"] = (float(np.mean(np.abs(f - a))),
                              float(np.mean(np.abs(f[nz] - a[nz]) / a[nz]) * 100) if nz.any() else None,
                              float(np.abs(f - a).sum()), float(a.sum()))
        except Exception:  # noqa: BLE001
            pass
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--env", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--workers", type=int, default=12)
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)

    import pymysql
    env = gf.db_credentials(args.env)
    conn = pymysql.connect(host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", 3306)),
                           user=env.get("DB_USERNAME", "root"), password=env.get("DB_PASSWORD", ""),
                           database=env["DB_DATABASE"], charset="utf8mb4")
    products = pd.read_sql("SELECT p.sku, p.name AS product, c.name AS category, p.selling_price, "
                           "IF(p.archived_at IS NULL, 'no', 'yes') AS archived "
                           "FROM products p LEFT JOIN categories c ON c.id = p.category_id", conn)
    conn.close()

    monthly = gf.monthly_series(gf.load_from_mysql(args.env, False))
    end = monthly["month"].max()
    future = pd.date_range(end + pd.offsets.MonthBegin(1), periods=HORIZON, freq="MS")
    tasks = []
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        tasks.append((sku, s.to_numpy(float)))
    hist_len = {sku: len(v) for sku, v in tasks}
    print(f"{len(tasks)} products with sales, months up to {end:%Y-%m}; forecasting {future[0]:%Y-%m}..{future[-1]:%Y-%m}", flush=True)
    with ProcessPoolExecutor(args.workers) as pool:
        results = {r["sku"]: r for r in pool.map(task, tasks, chunksize=8)}

    demand, sales, acc = [], [], []
    for _, p in products.sort_values("product").iterrows():
        r = results.get(p.sku)
        price = float(p.selling_price or 0)
        base = {"sku": p.sku, "product": p["product"], "category": p.category, "archived": p.archived, "model": MODEL,
                "history_months": hist_len.get(p.sku, 0)}
        if r is None:
            status, fc = "never sold: forecast 0", (np.zeros(HORIZON),) * 3
        elif r["forecast"] is None:
            status, fc = r["status"], None
        else:
            status, fc = "ok", r["forecast"]
        ho = r["holdout"] if r else None
        hmae = round(ho[0], 2) if ho else None
        hmape = round(ho[1], 2) if ho and ho[1] is not None else None
        acc.append({**base, "status": status, "holdout_MAE": hmae, "holdout_MAPE": hmape,
                    "_abs": ho[2] if ho else None, "_units": ho[3] if ho else None})
        for i, d in enumerate(future):
            u = None if fc is None else (int(fc[0][i]), int(fc[1][i]), int(fc[2][i]))
            demand.append({**base, "status": status, "forecast_date": d.strftime("%Y-%m-%d"),
                           "forecast_units": u[0] if u else None, "lower_80": u[1] if u else None, "upper_80": u[2] if u else None,
                           "holdout_MAE_3mo": hmae, "holdout_MAPE_3mo": hmape})
            sales.append({**base, "status": status, "forecast_date": d.strftime("%Y-%m-%d"), "selling_price": round(price, 2),
                          "forecast_units": u[0] if u else None,
                          "forecast_revenue": round(u[0] * price, 2) if u else None,
                          "lower_80_revenue": round(u[1] * price, 2) if u else None,
                          "upper_80_revenue": round(u[2] * price, 2) if u else None})

    pd.DataFrame(demand).to_csv(os.path.join(args.out, "sarima_demand_forecast.csv"), index=False, encoding="utf-8-sig")
    pd.DataFrame(sales).to_csv(os.path.join(args.out, "sarima_sales_forecast.csv"), index=False, encoding="utf-8-sig")

    a = pd.DataFrame(acc)
    scored = a[a.holdout_MAE.notna()]
    d, s = pd.DataFrame(demand), pd.DataFrame(sales)
    summary = {
        "products": len(a), "forecast_ok": int((a.status == "ok").sum()),
        "never_sold": int(a.status.str.startswith("never").sum()),
        "too_little_history_or_failed": int((~a.status.isin(["ok"]) & ~a.status.str.startswith("never")).sum()),
        "holdout_products_scored": len(scored), "holdout_mean_MAE": round(scored.holdout_MAE.mean(), 2),
        "holdout_mean_MAPE": round(scored.holdout_MAPE.mean(), 2),
        "holdout_WAPE": round(scored._abs.sum() / scored._units.sum() * 100, 2),
        "total_forecast_units_6mo": int(d.forecast_units.fillna(0).sum()),
        "total_forecast_revenue_6mo": round(s.forecast_revenue.fillna(0).sum(), 2),
    }
    pd.DataFrame([summary]).T.rename(columns={0: "value"}).to_csv(os.path.join(args.out, "sarima_forecast_summary.csv"), encoding="utf-8-sig")
    for k, v in summary.items():
        print(f"{k}: {v}")


if __name__ == "__main__":
    main()
