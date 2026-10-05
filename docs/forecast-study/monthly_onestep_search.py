#!/usr/bin/env python3
"""
Can the monthly card's one-month-ahead error get to 10% or less, legitimately?

Monthly units, Jul 2022 - Jul 2026, monthly only. Every candidate is scored one month ahead
(trained on the months before each forecast only). To avoid choosing on the numbers we report:
  SELECTION  = one-month-ahead months Jul 2024 - Jul 2025 (13 months)  -> pick the configuration
  EVALUATION = one-month-ahead months Aug 2025 - Jul 2026 (12 months)  -> report it, untouched
The full Jul 2024 - Jul 2026 figure (what the card shows) is listed too.
"""
import itertools
import os
import sys
import warnings
from concurrent.futures import ProcessPoolExecutor

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")
warnings.filterwarnings("ignore")
import numpy as np
import pandas as pd

DATA = sys.argv[1]
OUT = sys.argv[2]
ORDERS = [(0, 1, 1), (1, 1, 0), (1, 1, 1), (2, 1, 1), (0, 1, 2)]
SEASONAL = [(0, 1, 1, 12), (1, 1, 0, 12), (1, 1, 1, 12), (0, 1, 0, 12), (0, 0, 0, 0)]
TRANSFORMS = ["none", "log1p"]
WINDOWS = [None, 24, 30, 36]          # None = everything since Jul 2022 (expanding)


def load():
    it = pd.read_csv(os.path.join(DATA, "transaction_items_2022_2026.csv"), usecols=["Date / Time", "Quantity"])
    it["dt"] = pd.to_datetime(it["Date / Time"], format="%m/%d/%Y %I:%M:%S %p")
    y = it.groupby(it.dt.dt.to_period("M").dt.to_timestamp()).Quantity.sum().astype(float).loc["2022-07-01":"2026-07-01"]
    return y


def task(args):
    y, o, so, tr, w = args
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    out = []
    for t in range(24, len(y)):
        a = 0 if w is None else max(0, t - w)
        v = y[a:t]
        z = np.log1p(v) if tr == "log1p" else v
        try:
            f = float(SARIMAX(z, order=o, seasonal_order=so).fit(disp=False, maxiter=300).forecast(1)[0])
            f = float(np.expm1(f)) if tr == "log1p" else f
        except Exception:  # noqa: BLE001
            f = np.nan
        out.append(f)
    return (o, so, tr, w), out


def score(a, f):
    a, f = np.asarray(a), np.asarray(f)
    if np.isnan(f).any():
        return dict(MAPE=np.nan, MAE=np.nan, RMSE=np.nan, WAPE=np.nan)
    e = f - a
    return dict(MAPE=np.mean(np.abs(e) / a) * 100, MAE=np.mean(np.abs(e)), RMSE=np.sqrt(np.mean(e ** 2)),
                WAPE=np.abs(e).sum() / a.sum() * 100)


if __name__ == "__main__":
    y = load()
    months = y.index[24:].strftime("%Y-%m")
    sel = np.array([m <= "2025-07" for m in months])
    v = y.to_numpy()
    tasks = [(v, o, so, tr, w) for o, so, tr, w in itertools.product(ORDERS, SEASONAL, TRANSFORMS, WINDOWS)
             if not (so == (0, 0, 0, 0) and False)]
    with ProcessPoolExecutor(12) as pool:
        res = dict(pool.map(task, tasks, chunksize=2))
    act = v[24:]
    rows = []
    for (o, so, tr, w), f in res.items():
        f = np.asarray(f)
        rows.append({"order": str(o), "seasonal": str(so), "transform": tr, "window": "all" if w is None else w,
                     **{f"sel_{k}": x for k, x in score(act[sel], f[sel]).items()},
                     **{f"eval_{k}": x for k, x in score(act[~sel], f[~sel]).items()},
                     **{f"full_{k}": x for k, x in score(act, f).items()}})
    naive = v[23:-1]
    rows.append({"order": "naive", "seasonal": "-", "transform": "-", "window": "-",
                 **{f"sel_{k}": x for k, x in score(act[sel], naive[sel]).items()},
                 **{f"eval_{k}": x for k, x in score(act[~sel], naive[~sel]).items()},
                 **{f"full_{k}": x for k, x in score(act, naive).items()}})
    df = pd.DataFrame(rows).sort_values("sel_MAPE")
    os.makedirs(OUT, exist_ok=True)
    df.to_csv(os.path.join(OUT, "monthly_onestep_search.csv"), index=False)
    pd.set_option("display.width", 250)
    cols = ["order", "seasonal", "transform", "window", "sel_MAPE", "eval_MAPE", "eval_MAE", "eval_RMSE", "eval_WAPE", "full_MAPE", "full_MAE", "full_RMSE", "full_WAPE"]
    print(df[cols].head(15).round(2).to_string())
    print(df[df.order == "naive"][cols].round(2).to_string())
    ref = df[(df.order == "(1, 1, 1)") & (df.seasonal == "(0, 1, 1, 12)") & (df.transform == "none") & (df.window == "all")]
    print(ref[cols].round(2).to_string())
    print("seasonal-only best by sel:")
    print(df[df.seasonal != "(0, 0, 0, 0)"][cols].head(8).round(2).to_string())
