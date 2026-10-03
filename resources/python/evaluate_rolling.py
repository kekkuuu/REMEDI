#!/usr/bin/env python3
"""
Evaluate the demand model month by month across the WHOLE record -- a rolling
(walk-forward) test, 2026-10-02, at the user's request ("test accuracy over 4
years").

    php artisan forecast:evaluate-rolling --python=python     (the usual way in)

For every month t, the model is trained ONLY on the months before t and
forecasts t, one month ahead -- exactly what the app does each month when it
forecasts "next month". The forecast is then compared with what t actually
sold. Never looks ahead: month t's own sales are not in its training data.

Two series are tested this way:
  - the WHOLE STORE (every product's units summed per month), and
  - every PRODUCT on its own, over its own history.

Both use generate_forecasts.forecast_product() -- the live model (ACF/PACF
identification, lowest AIC, level guard) -- and the same loader, so this
scores exactly what forecast:generate runs. A "mean of the last 3 months"
baseline is scored on the same months for comparison.

EVALUATION ONLY: it writes resources/data/forecast_rolling.json (shown on the
Forecasting page; commit it to publish a run) and touches nothing in the
database.
"""

import os

for _blas_var in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS",
                  "NUMEXPR_NUM_THREADS", "VECLIB_MAXIMUM_THREADS"):
    os.environ.setdefault(_blas_var, "1")

import argparse
import json
import time
import warnings
from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

import generate_forecasts as gf

warnings.filterwarnings("ignore")


def walk_forward(series: pd.Series):
    """[(month, actual, forecast, baseline)] -- one-step-ahead, trained on the months before each."""
    out = []
    for t in range(gf.MIN_MONTHS_FOR_ANY_FORECAST, len(series)):
        train = series.iloc[:t]
        try:
            rows = gf.forecast_product(train, 1)
        except Exception:  # noqa: BLE001 - one failed month is skipped, not fatal
            rows = []
        if not rows:
            continue
        out.append((
            series.index[t].strftime("%Y-%m"),
            float(series.iloc[t]),
            float(rows[0]["forecast_value"]),
            float(train.iloc[-3:].mean()),
        ))
    return out


def _product_task(task):
    sku, series = task
    return sku, walk_forward(series)


def mape(points, col):
    """Mean absolute % error over the points that sold something (MAPE is undefined at zero)."""
    ape = [abs(p[col] - p[1]) / p[1] * 100 for p in points if p[1] > 0]
    return float(np.mean(ape)) if ape else None


def wape(points, col):
    sold = sum(p[1] for p in points)
    return float(sum(abs(p[col] - p[1]) for p in points) / sold * 100) if sold else None


def r2(v):
    return None if v is None else round(v, 2)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--env-path", default=None)
    parser.add_argument("--output", required=True, help="Summary JSON")
    parser.add_argument("--include-pos", action="store_true")
    parser.add_argument("--workers", type=int, default=1, help="0 = all cores but one, 1 = sequential")
    args = parser.parse_args()

    started = time.monotonic()
    monthly = gf.monthly_series(gf.load_from_mysql(args.env_path, args.include_pos))
    if monthly.empty:
        raise SystemExit("No sales history to evaluate.")
    series_end = monthly["month"].max()

    # The whole store.
    total = monthly.groupby("month")["qty"].sum().sort_index()
    total = total.reindex(pd.date_range(total.index.min(), series_end, freq="MS"), fill_value=0)
    store = walk_forward(total)
    print(f"Store-wide: {len(store)} months tested, {store[0][0]} to {store[-1][0]}", flush=True)

    # Every product, padded to the shared end exactly as forecast:generate does.
    tasks = []
    for sku, group in monthly.groupby("product_sku"):
        s = group.set_index("month")["qty"].sort_index()
        tasks.append((sku, s.reindex(pd.date_range(s.index.min(), series_end, freq="MS"), fill_value=0)))

    workers = gf.resolve_workers(args.workers)
    print(f"Products: walking {len(tasks)} series forward ({workers} worker{'s' if workers > 1 else ''})...", flush=True)
    if workers == 1:
        results = [_product_task(t) for t in tasks]
    else:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            results = list(pool.map(_product_task, tasks, chunksize=4))
    results = [(sku, pts) for sku, pts in results if pts]

    years = sorted({p[0][:4] for p in store} | {p[0][:4] for _, pts in results for p in pts})

    def per_product(year=None):
        """Each product's own MAPE over its tested months, averaged ACROSS products (like the holdout card)."""
        model, base, all_pts = [], [], []
        for _, pts in results:
            sel = [p for p in pts if year is None or p[0].startswith(year)]
            all_pts += sel
            m, b = mape(sel, 2), mape(sel, 3)
            if m is not None:
                model.append(m)
            if b is not None:
                base.append(b)
        return {
            "products": sum(1 for _, pts in results if any(year is None or p[0].startswith(year) for p in pts)),
            "mape": r2(float(np.mean(model)) if model else None),
            "baseline_mape": r2(float(np.mean(base)) if base else None),
            "wape": r2(wape(all_pts, 2)),
            "baseline_wape": r2(wape(all_pts, 3)),
        }

    def store_block(year=None):
        sel = [p for p in store if year is None or p[0].startswith(year)]
        return {
            "months": len(sel),
            "mape": r2(mape(sel, 2)),
            "baseline_mape": r2(mape(sel, 3)),
            "wape": r2(wape(sel, 2)),
        }

    summary = {
        "generated_at": pd.Timestamp.now().strftime("%Y-%m-%d %H:%M"),
        "horizon": "1 month ahead",
        "first_month": store[0][0],
        "last_month": store[-1][0],
        "storewide": {
            **store_block(),
            "by_year": {y: store_block(y) for y in years if any(p[0].startswith(y) for p in store)},
            "monthly": [{"month": m, "actual": int(round(a)), "forecast": int(round(f))} for m, a, f, _ in store],
        },
        "per_product": {
            **per_product(),
            "tested_months": sum(len(pts) for _, pts in results),
            "by_year": {y: per_product(y) for y in years},
        },
    }

    os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
    with open(args.output, "w", encoding="utf-8") as f:
        json.dump(summary, f, indent=2)
        f.write("\n")

    s, p = summary["storewide"], summary["per_product"]
    print(f"\nWalk-forward, one month ahead, {summary['first_month']} to {summary['last_month']}:")
    print(f"  Store-wide   MAPE {s['mape']}%  (mean of last 3: {s['baseline_mape']}%)  WAPE {s['wape']}%")
    print(f"  Per product  MAPE {p['mape']}%  (mean of last 3: {p['baseline_mape']}%)  WAPE {p['wape']}%  over {p['products']} products")
    for y, b in s["by_year"].items():
        q = p["by_year"].get(y, {})
        print(f"  {y}: store-wide {b['mape']}% over {b['months']} months; per product {q.get('mape')}%")
    print(f"Summary written to {args.output} in {time.monotonic() - started:.0f}s")


if __name__ == "__main__":
    main()
