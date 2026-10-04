#!/usr/bin/env python3
"""
Evaluate the demand model on a chronological TRAIN/TEST split (80/20 by default).

    python evaluate_train_test_split.py --env-path ../../.env --output split.csv
    php artisan forecast:evaluate-split --python=python          (the usual way in)

What it does, per product:
  1. Reads the SAME data forecast:generate trains on -- load_from_mysql() and
     monthly_series() from generate_forecasts.py, so archived products, voided
     sales, the incomplete trailing month and the forecast.include_pos switch
     are all handled exactly as in the live pipeline.
  2. Splits that product's monthly series IN TIME ORDER: the first 80% of its
     months are the TRAINING set, the last 20% the TEST set. Never shuffled --
     a random split would let the model learn from later months to "predict"
     earlier ones, which is looking at the answer.
  3. Trains the SAME model the app uses (generate_forecasts.forecast_product:
     SARIMA, seasonal or not chosen per product, with the level guard) on the
     training months only, forecasts
     every test month in one go from the cut, and scores it.
  4. Scores two naive baselines on the same test months -- "repeat the last
     training month" and "mean of the last 3 training months" -- because a
     forecast is only worth having if it beats the obvious guess.

This is an EVALUATION, not a second model: it writes a CSV and prints a
summary, and touches nothing in the database. The forecasts the app shows are
still trained on ALL the months (holding data back from the real forecast
would only make it worse), and the accuracy on the Forecasting page still
comes from generate_forecasts.backtest_product()'s 3-month holdout, which
matches the 6-month horizon the app actually forecasts.
"""

import os

for _blas_var in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS",
                  "NUMEXPR_NUM_THREADS", "VECLIB_MAXIMUM_THREADS"):
    os.environ.setdefault(_blas_var, "1")

import argparse
import csv
import json
import math
import warnings
from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

import generate_forecasts as gf

warnings.filterwarnings("ignore")


def split_series(series: pd.Series, train_ratio: float):
    """(train, test) in time order: the first `train_ratio` of months, then the rest."""
    n = len(series)
    test_n = max(1, round(n * (1 - train_ratio)))
    return series.iloc[:-test_n], series.iloc[-test_n:]


def metrics(pred: np.ndarray, obs: np.ndarray) -> dict:
    """Same formulas as generate_forecasts.backtest_product(), plus the raw sums WAPE needs."""
    err = pred - obs
    nonzero = obs != 0
    denom = np.abs(pred) + np.abs(obs)
    return {
        "mae": float(np.mean(np.abs(err))),
        "rmse": float(math.sqrt(np.mean(err ** 2))),
        # MAPE is undefined on a month that sold nothing -- scored on non-zero months only.
        "mape": float(np.mean(np.abs(err[nonzero] / obs[nonzero])) * 100) if nonzero.any() else None,
        "smape": float(np.mean(np.where(denom == 0, 0.0, np.abs(err) / np.where(denom == 0, 1.0, denom))) * 200),
        "abs_error": float(np.sum(np.abs(err))),
        "units": float(np.sum(obs)),
    }


def grade(m: dict) -> str:
    """App\\Support\\ForecastGrade: MAPE bands where defined, sMAPE's wider bands otherwise."""
    if m["mape"] is not None:
        return "Normal" if m["mape"] <= 20 else ("Acceptable" if m["mape"] <= 50 else "Not acceptable")
    return "Normal" if m["smape"] <= 40 else ("Acceptable" if m["smape"] <= 90 else "Not acceptable")


def evaluate_product(task):
    """Worker entry point (module level so it pickles under Windows' spawn)."""
    sku, series, train_ratio = task
    train, test = split_series(series, train_ratio)

    if len(train) < gf.MIN_MONTHS_FOR_ANY_FORECAST:
        return None

    try:
        rows = gf.forecast_product(train, len(test))
    except Exception:  # noqa: BLE001 - one bad series must not stop the run
        rows = []

    if not rows:
        return None

    obs = test.to_numpy(dtype=float)
    pred = np.array([float(r["forecast_value"]) for r in rows[:len(obs)]], dtype=float)
    obs = obs[:len(pred)]
    history = train.to_numpy(dtype=float)

    return {
        "sku": sku,
        "train_months": len(train),
        "test_months": len(obs),
        "train_period": f"{train.index[0]:%Y-%m} to {train.index[-1]:%Y-%m}",
        "test_period": f"{test.index[0]:%Y-%m} to {test.index[len(obs) - 1]:%Y-%m}",
        "model": metrics(pred, obs),
        "repeat_last": metrics(np.repeat(history[-1], len(obs)), obs),
        "mean_last_3": metrics(np.repeat(history[-3:].mean(), len(obs)), obs),
    }


def summarise(results, key):
    ms = [r[key] for r in results]
    mape = [m["mape"] for m in ms if m["mape"] is not None]
    units = sum(m["units"] for m in ms)
    return {
        "mae": np.mean([m["mae"] for m in ms]),
        "rmse": np.mean([m["rmse"] for m in ms]),
        "mape": np.mean(mape) if mape else float("nan"),
        "smape": np.mean([m["smape"] for m in ms]),
        "wape": sum(m["abs_error"] for m in ms) / units * 100 if units else float("nan"),
    }


def product_names(env_path):
    import pymysql

    env = gf.db_credentials(env_path)
    conn = pymysql.connect(host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", 3306)),
                           user=env.get("DB_USERNAME"), password=env.get("DB_PASSWORD"),
                           database=env.get("DB_DATABASE"))
    with conn.cursor() as cur:
        cur.execute("SELECT sku, name FROM products")
        names = dict(cur.fetchall())
    conn.close()
    return names


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--env-path", default=None)
    parser.add_argument("--output", required=True, help="Per-product results CSV")
    parser.add_argument("--summary", default=None, help="Also write the overall figures as JSON (read by the Forecasting page)")
    parser.add_argument("--train-ratio", type=float, default=0.8, help="Share of each product's months used for training")
    parser.add_argument("--include-pos", action="store_true", help="also train on the till's own sales")
    parser.add_argument("--workers", type=int, default=1, help="0 = all cores but one, 1 = sequential")
    args = parser.parse_args()

    if not 0.5 <= args.train_ratio <= 0.95:
        raise SystemExit("--train-ratio must be between 0.5 and 0.95")

    monthly = gf.monthly_series(gf.load_from_mysql(args.env_path, args.include_pos))
    if monthly.empty:
        raise SystemExit("No sales history to evaluate.")

    # Same shared end and zero-padding as generate_forecasts.main(), so each
    # product's series is exactly the one the live model trains on.
    series_end = monthly["month"].max()
    tasks = []
    for sku, group in monthly.groupby("product_sku"):
        s = group.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), series_end, freq="MS"), fill_value=0)
        tasks.append((sku, s, args.train_ratio))

    workers = gf.resolve_workers(args.workers)
    train_pct = round(args.train_ratio * 100)
    print(f"Evaluating {len(tasks)} products on a chronological {train_pct}/{100 - train_pct} split "
          f"({workers} worker{'s' if workers > 1 else ''})...", flush=True)

    if workers == 1:
        results = [r for r in map(evaluate_product, tasks) if r]
    else:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            results = [r for r in pool.map(evaluate_product, tasks, chunksize=4) if r]

    if not results:
        raise SystemExit("No product had enough history to evaluate.")

    typical = pd.Series([(r["train_months"], r["test_months"], r["train_period"], r["test_period"])
                         for r in results]).value_counts()
    (train_n, test_n, train_period, test_period), count = typical.index[0], typical.iloc[0]
    print(f"\nScored {len(results)} of {len(tasks)} products.")
    print(f"Most products ({count}): TRAIN {train_period} ({train_n} months), TEST {test_period} ({test_n} months).")
    print("Products that started selling later are split the same way over their own, shorter history.")

    print(f"\n{'':28}{'MAE':>8}{'RMSE':>8}{'MAPE':>9}{'sMAPE':>9}{'WAPE':>9}")
    overall = {}
    for key, label in (("model", "Model (SARIMA, as in the app)"),
                       ("mean_last_3", "Baseline: mean of last 3"),
                       ("repeat_last", "Baseline: repeat last month")):
        s = summarise(results, key)
        overall[key] = s
        print(f"{label:28}{s['mae']:8.2f}{s['rmse']:8.2f}{s['mape']:8.1f}%{s['smape']:8.1f}%{s['wape']:8.1f}%")

    mape_n = sum(1 for r in results if r["model"]["mape"] is not None)
    print(f"\nMAPE is defined on {mape_n} products (the rest sold nothing in any test month).")
    for key, label in (("mean_last_3", "mean of last 3"), ("repeat_last", "repeat last month")):
        wins = sum(r["model"]["mae"] < r[key]["mae"] - 1e-9 for r in results)
        print(f"Model has a lower MAE than '{label}' on {wins} of {len(results)} products.")
    grades = pd.Series([grade(r["model"]) for r in results]).value_counts()
    print("Grades: " + ", ".join(f"{g} {int(grades.get(g, 0))}" for g in ("Normal", "Acceptable", "Not acceptable")))

    # The same split on the WHOLE STORE's monthly units (every product summed):
    # per-product errors partly cancel, so this is a different question --
    # shown as store-wide, never as the per-product figure.
    total = monthly.groupby("month")["qty"].sum().sort_index()
    total = total.reindex(pd.date_range(total.index.min(), series_end, freq="MS"), fill_value=0)
    cut = int(round(len(total) * args.train_ratio))
    storewide = None
    if cut >= gf.MIN_MONTHS_FOR_ANY_FORECAST and cut < len(total):
        sw_train, sw_test = total.iloc[:cut], total.iloc[cut:]
        sw_rows = gf.forecast_store_total(sw_train, len(sw_test))  # raw units: see forecast_store_total
        if sw_rows:
            pred = np.array([float(r["forecast_value"]) for r in sw_rows], dtype=float)
            obs = sw_test.to_numpy(dtype=float)[:len(pred)]
            nz = obs != 0
            storewide = {
                "train_period": f"{sw_train.index[0]:%Y-%m} to {sw_train.index[-1]:%Y-%m}",
                "test_period": f"{sw_test.index[0]:%Y-%m} to {sw_test.index[len(obs) - 1]:%Y-%m}",
                "mape": round(float(np.mean(np.abs((pred - obs)[nz] / obs[nz])) * 100.0), 2) if nz.any() else None,
                "wape": round(float(np.sum(np.abs(pred - obs)) / np.sum(obs) * 100.0), 2) if obs.sum() else None,
            }
            print(f"Store-wide (all products summed): MAPE {storewide['mape']}%, WAPE {storewide['wape']}%")

    if args.summary:
        def clean(v):
            v = float(v)
            return None if math.isnan(v) else round(v, 2)

        summary = {
            "generated_at": pd.Timestamp.now().strftime("%Y-%m-%d %H:%M"),
            "train_pct": train_pct,
            "test_pct": 100 - train_pct,
            "products": len(tasks),
            "scored": len(results),
            "mape_defined": mape_n,
            "typical": {"count": int(count), "train_period": train_period, "train_months": int(train_n),
                        "test_period": test_period, "test_months": int(test_n)},
            "metrics": {k: {m: clean(v) for m, v in s.items()} for k, s in overall.items()},
            "wins": {k: int(sum(r["model"]["mae"] < r[k]["mae"] - 1e-9 for r in results))
                     for k in ("mean_last_3", "repeat_last")},
            "grades": {g: int(grades.get(g, 0)) for g in ("Normal", "Acceptable", "Not acceptable")},
            "storewide": storewide,
        }
        os.makedirs(os.path.dirname(os.path.abspath(args.summary)), exist_ok=True)
        with open(args.summary, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2)
            f.write("\n")
        print(f"Summary written to {args.summary}")

    names = product_names(args.env_path)
    os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
    with open(args.output, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["SKU", "Product", "Train months", "Test months", "Train period", "Test period",
                    "MAE", "RMSE", "MAPE %", "sMAPE %", "Grade",
                    "Baseline MAE (mean of last 3)", "Beats baseline"])
        for r in sorted(results, key=lambda r: names.get(r["sku"], "")):
            m, base = r["model"], r["mean_last_3"]
            w.writerow([r["sku"], names.get(r["sku"], ""), r["train_months"], r["test_months"],
                        r["train_period"], r["test_period"],
                        round(m["mae"], 2), round(m["rmse"], 2),
                        "" if m["mape"] is None else round(m["mape"], 1), round(m["smape"], 1), grade(m),
                        round(base["mae"], 2), "Yes" if m["mae"] < base["mae"] - 1e-9 else "No"])
    print(f"\nPer-product results written to {args.output}")


if __name__ == "__main__":
    main()
