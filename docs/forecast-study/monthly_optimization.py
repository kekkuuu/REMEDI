#!/usr/bin/env python3
"""
Monthly SARIMA MAPE study -- MONTHLY data only, no weekly aggregation anywhere.

    python monthly_optimization.py --data <folder with the 3 CSVs> --out <output folder> --workers 8

Target: Quantity from transaction_items_2022_2026.csv, summed per calendar month. Nothing in the data
is edited, removed or invented; every figure below is computed from the file as supplied.

Design (all leakage-free):
  * Complete months only: 2022-06 .. 2026-07 (50 months). Aug 2026 (1-16 only) is excluded.
  * FINAL TEST = the last 12 complete months (2025-08 .. 2026-07), touched only to score the model
    chosen beforehand. Training = the 38 months before it.
  * MODEL SELECTION = rolling folds INSIDE the 38 training months (6-month horizon, origins 24, 26,
    28, 30, 32): configuration, transformation, window and exogenous variables are chosen on these
    folds only, ranked by mean WAPE then mean MAPE.
  * STABILITY = rolling origin over the whole record (6-month horizon, step 3, 7 folds; and 12-month
    horizon, step 6, 3 folds). These overlap the final test and are reported, never used to choose.
  * MAPE uses months with actual > 0 only; WAPE = sum|A-F| / sum(A).
  * SARIMAX exogenous variables must be known in advance (calendar days, a step dummy for a level
    shift found in the TRAINING data) or forecast themselves (transaction count, two-stage).
"""

import argparse
import itertools
import json
import os
import sys
import time
import warnings

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")

from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sarima_study as ss  # noqa: E402  (loader, quality checks, complete-period rules)

warnings.filterwarnings("ignore")

S, H_TEST = 12, 12
CONFIGS = [((0, 1, 1), (0, 1, 1)), ((1, 1, 0), (0, 1, 1)), ((1, 1, 1), (0, 1, 1)), ((2, 1, 1), (0, 1, 1)),
           ((0, 1, 1), (1, 1, 1)), ((1, 1, 1), (1, 1, 1)), ((2, 1, 1), (1, 1, 1)),
           ((1, 0, 1), (0, 1, 1)), ((2, 0, 1), (0, 1, 1)),
           # small additions: no seasonal MA, and plain ARIMA (s dropped) to test whether s=12 earns its place
           ((0, 1, 1), (1, 1, 0)), ((1, 1, 1), (0, 0, 0)), ((0, 1, 1), (0, 0, 0)),
           ((1, 1, 0), (0, 0, 0)), ((2, 1, 1), (0, 0, 0))]
TRANSFORMS = ["none", "log1p"]
WINDOWS = [24, 30, 36, 42, 48, None]          # None = all available
SEL_ORIGINS, SEL_H = [24, 26, 28, 30, 32], 6
REF = ((1, 1, 1), (0, 1, 1))


def lab(cfg):
    (p, d, q), (P, D, Q) = cfg
    return f"({p},{d},{q})" if (P, D, Q) == (0, 0, 0) else f"({p},{d},{q})({P},{D},{Q},12)"


def metrics(a, f):
    a, f = np.asarray(a, float), np.asarray(f, float)
    e = f - a
    nz = a > 0
    return {"MAPE": float(np.mean(np.abs(e[nz] / a[nz])) * 100) if nz.any() else np.nan,
            "WAPE": float(np.abs(e).sum() / a.sum() * 100) if a.sum() > 0 else np.nan,
            "MAE": float(np.mean(np.abs(e))), "RMSE": float(np.sqrt(np.mean(e ** 2))),
            "months": int(len(a)), "zero_actual": int((~nz).sum())}


# ----------------------------------------------------------------------------- one fit
def fit(task):
    """(y, cfg, transform, exog_train, exog_future, h, tag) -> forecast + fit facts. Never raises."""
    y, cfg, transform, xtr, xfu, h, tag = task
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    order, sorder = cfg
    out = {"tag": tag, "ok": False, "converged": False, "aic": np.nan, "bic": np.nan,
           "forecast": [np.nan] * h, "params": {}, "error": "",
           "nobs_effective": len(y) - order[1] - sorder[1] * S}
    try:
        z = np.log1p(np.asarray(y, float)) if transform == "log1p" else np.asarray(y, float)
        trend = "c" if order[1] == 0 else "n"
        seas = (*sorder, S) if sorder != (0, 0, 0) else (0, 0, 0, 0)
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            res = SARIMAX(z, exog=xtr, order=order, seasonal_order=seas, trend=trend).fit(disp=False, maxiter=300)
        fc = np.asarray(res.forecast(h, exog=xfu), float)
        if transform == "log1p":
            fc = np.expm1(np.clip(fc, None, 30))
        out.update(ok=bool(np.all(np.isfinite(fc))), converged=bool(res.mle_retvals.get("converged", False)),
                   aic=float(res.aic), bic=float(res.bic), forecast=fc.tolist(),
                   params={k: round(float(v), 4) for k, v in zip(res.param_names, res.params)})
    except Exception as exc:  # noqa: BLE001
        out["error"] = str(exc)[:160]
    return out


def run(tasks, workers):
    with ProcessPoolExecutor(max_workers=workers) as pool:
        return {r["tag"]: r for r in pool.map(fit, tasks, chunksize=4)}


def baselines(train, h):
    v = train.to_numpy(float)
    return {"Naive (last month)": np.repeat(v[-1], h),
            "Seasonal naive (12 months ago)": np.array([v[len(v) - 12 + (i % 12)] for i in range(h)]),
            "3-month moving average": np.repeat(v[-3:].mean(), h),
            "6-month moving average": np.repeat(v[-6:].mean(), h)}


def step_dummy(train_log):
    """The largest month-on-month level jump found in the TRAINING data (never the future)."""
    jumps = np.diff(train_log)
    k = int(np.argmax(jumps)) + 1          # first month at the new level
    return k, float(jumps[k - 1])


# ----------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--workers", type=int, default=8)
    ap.add_argument("--app-accuracy-csv", default=None, help="the app's per-product holdout CSV, for the 40-60%% explanation")
    args = ap.parse_args()
    charts = os.path.join(args.out, "charts")
    os.makedirs(charts, exist_ok=True)
    t0 = time.time()
    log = lambda s: print(f"[{time.time() - t0:5.0f}s] {s}", flush=True)

    # ------------------------------------------------------------------ 2-4. data and complete months
    it = ss.load(args.data)
    q, big = ss.quality(it, args.data)
    missing = it.drop(columns="dt").isna().sum()
    # Calendar months only (no weekly series is built anywhere). A month is complete when the data
    # covers its first and last day.
    month = it.dt.dt.to_period("M").dt.to_timestamp()
    all_months = it.groupby(month).Quantity.sum()
    first_day, last_day = it.dt.min().normalize(), it.dt.max().normalize()
    complete = (all_months.index >= first_day.replace(day=1)) & (all_months.index + pd.offsets.MonthEnd(0) <= last_day)
    if first_day.day != 1:
        complete &= all_months.index > first_day.replace(day=1)
    monthly = all_months[complete]
    excluded = {"monthly_excluded": [str(i.date()) for i in all_months.index[~complete]]}
    orders = it.groupby(month)["Order#"].nunique().reindex(monthly.index)
    revenue = it.groupby(month)["Line Total"].sum().reindex(monthly.index)
    nprod = it.groupby(month)["Product ID"].nunique().reindex(monthly.index)
    days = pd.Series(monthly.index.days_in_month, index=monthly.index).astype(float)
    y = monthly.astype(float)
    n = len(y)
    summary = pd.concat([q, pd.DataFrame(
        [{"check": f"missing values: {c}", "value": int(v), "note": ""} for c, v in missing.items()] + [
            {"check": "first complete month", "value": str(y.index[0])[:7], "note": "trading starts 2022-06-01, so June 2022 is complete"},
            {"check": "last complete month", "value": str(y.index[-1])[:7], "note": ""},
            {"check": "excluded incomplete month", "value": ", ".join(x[:7] for x in excluded["monthly_excluded"]),
             "note": f"data ends {it.dt.max():%Y-%m-%d %H:%M}: 16 of 31 days"},
            {"check": "complete monthly observations", "value": n, "note": ""},
            {"check": "final test (last 12 complete months)", "value": f"{str(y.index[-12])[:7]} .. {str(y.index[-1])[:7]}", "note": ""},
            {"check": "training for the final test", "value": f"{str(y.index[0])[:7]} .. {str(y.index[-13])[:7]} ({n - 12} months)", "note": ""},
            {"check": "weekly aggregation", "value": "not used", "note": "monthly only, as required"},
        ])], ignore_index=True)
    mdemand = pd.DataFrame({"month": y.index.strftime("%Y-%m"), "units": y.astype(int).values,
                            "transactions": orders.astype(int).values, "revenue": revenue.round(2).values,
                            "products_sold": nprod.astype(int).values, "days": days.astype(int).values,
                            "units_per_transaction": (y / orders).round(3).values,
                            "role": ["test" if i >= n - 12 else "train" for i in range(n)]})
    train, test = y.iloc[:-12], y.iloc[-12:]
    log(f"{n} complete months; train {train.index[0]:%Y-%m}..{train.index[-1]:%Y-%m}, test {test.index[0]:%Y-%m}..{test.index[-1]:%Y-%m}")

    # ------------------------------------------------------------------ 13. seasonality
    from statsmodels.tsa.seasonal import STL
    from statsmodels.tsa.stattools import acf, pacf
    ly = np.log(y)
    stl = STL(ly, period=12, robust=True).fit()
    var = lambda s: float(np.var(s))
    seas_strength = max(0.0, 1 - var(stl.resid) / var(stl.seasonal + stl.resid))
    trend_strength = max(0.0, 1 - var(stl.resid) / var(stl.trend + stl.resid))
    ratio = y / y.rolling(12, center=True).mean()
    sidx = ratio.groupby(ratio.index.month).mean()
    sidx = (sidx / sidx.mean() * 100).round(1)
    dly = ly.diff().dropna()
    acf_lvl, acf_d1 = acf(y, nlags=24), acf(dly, nlags=24)
    pacf_d1 = pacf(dly, nlags=min(20, len(dly) // 2 - 1))
    seas_tbl = pd.DataFrame({"calendar month": [pd.Timestamp(2000, m, 1).strftime("%b") for m in sidx.index],
                             "seasonal index (100 = average)": sidx.values,
                             "average units": y.groupby(y.index.month).mean().round(0).values,
                             "years observed": y.groupby(y.index.month).size().values})
    seas_facts = pd.DataFrame([
        {"measure": "STL seasonal strength (0 none .. 1 strong), log units", "value": round(seas_strength, 3)},
        {"measure": "STL trend strength (0 none .. 1 strong), log units", "value": round(trend_strength, 3)},
        {"measure": "ACF of monthly growth at lag 12", "value": round(float(acf_d1[12]), 3)},
        {"measure": "95% significance band for that ACF", "value": round(1.96 / np.sqrt(len(dly)), 3)},
        {"measure": "ACF of the level at lag 1 / lag 12", "value": f"{acf_lvl[1]:.3f} / {acf_lvl[12]:.3f}"},
        {"measure": "full seasonal cycles in the data", "value": round(n / 12, 2)},
        {"measure": "growth: last 12 months vs first 12 months", "value": f"{y.iloc[-12:].mean() / y.iloc[:12].mean():.1f}x"},
        {"measure": "growth: test year vs the 12 months before it", "value": f"{(test.mean() / train.iloc[-12:].mean() - 1) * 100:.1f}%"},
        {"measure": "growth: the 12 months before the test vs the year before that",
         "value": f"{(train.iloc[-12:].mean() / train.iloc[-24:-12].mean() - 1) * 100:.1f}%"},
    ])
    log(f"seasonality: STL seasonal strength {seas_strength:.2f}, trend strength {trend_strength:.2f}, ACF(12) of growth {acf_d1[12]:.2f}")

    # ------------------------------------------------------------------ 10. outliers (reported, never removed)
    g = ly.diff()
    rz = (g - g.median()) / (1.4826 * (g - g.median()).abs().median())
    q1, q3 = g.quantile([0.25, 0.75])
    out_rows = []
    for t in y.index:
        flag_r = (not np.isnan(rz[t])) and abs(rz[t]) > 3.5
        flag_g = (not np.isnan(g[t])) and (g[t] < q1 - 3 * (q3 - q1) or g[t] > q3 + 3 * (q3 - q1))
        if flag_r or flag_g:
            prev = y.index.get_loc(t) - 1
            o_ch = (orders[t] / orders.iloc[prev] - 1) * 100 if prev >= 0 else np.nan
            u_ch = (y[t] / y.iloc[prev] - 1) * 100 if prev >= 0 else np.nan
            out_rows.append({"month": t.strftime("%Y-%m"), "units": int(y[t]), "robust z of monthly growth": round(float(rz[t]), 2),
                             "month-on-month units %": round(u_ch, 1), "month-on-month transactions %": round(o_ch, 1),
                             "units per transaction": round(float(y[t] / orders[t]), 3),
                             "flagged by": " + ".join(x for x, f in (("robust z > 3.5", flag_r), ("3xIQR of growth", flag_g)) if f),
                             "verdict": ("legitimate: transactions moved with units (more customers, not bigger or duplicated lines); kept"
                                         if abs(o_ch - u_ch) < 15 else "check: units moved without transactions")})
    outliers = pd.DataFrame(out_rows)
    log(f"outliers flagged: {len(outliers)} (all kept)")

    # ------------------------------------------------------------------ tasks
    tasks = []
    def add(y_, cfg, tr, xtr, xfu, h, tag):
        tasks.append((np.asarray(y_, float), cfg, tr, xtr, xfu, h, tag))

    # A. final-test grid: every config x transform x window (window must fit inside the 38 training months)
    for cfg, tr, w in itertools.product(CONFIGS, TRANSFORMS, WINDOWS):
        if w is not None and w > len(train):
            continue
        add(train if w is None else train.iloc[-w:], cfg, tr, None, None, H_TEST, ("test", cfg, tr, w))

    # B. selection folds inside the training months
    def exog_for(kind, ytr_idx, yfu_idx, ytr_vals):
        """Exogenous matrix for training and future months; only training information is used."""
        if kind == "days":
            return days.iloc[ytr_idx].to_numpy()[:, None], days.iloc[yfu_idx].to_numpy()[:, None]
        if kind == "step":
            k, _ = step_dummy(np.log(ytr_vals))
            xtr = (np.arange(len(ytr_vals)) >= k).astype(float)[:, None]
            if xtr.min() == xtr.max():
                return None, None
            return xtr, np.ones((len(yfu_idx), 1))
        return None, None

    sel_specs = []
    for cfg, tr in itertools.product(CONFIGS, TRANSFORMS):
        for w in (24, None):
            for ex in ("none", "days", "step"):
                sel_specs.append((cfg, tr, w, ex))
    for o in SEL_ORIGINS:
        for (cfg, tr, w, ex) in sel_specs:
            a = 0 if w is None else o - w
            idx_tr, idx_fu = list(range(a, o)), list(range(o, o + SEL_H))
            xtr, xfu = exog_for(ex, idx_tr, idx_fu, y.iloc[a:o].to_numpy())
            if ex != "none" and xtr is None:
                continue
            add(y.iloc[a:o], cfg, tr, xtr, xfu, SEL_H, ("sel", o, cfg, tr, w, ex))
    # two-stage SARIMAX on transactions (log units on log transactions; transactions forecast first)
    for o in SEL_ORIGINS:
        for cfg in CONFIGS:
            add(orders.iloc[:o], cfg, "log1p", None, None, SEL_H, ("selorders", o, cfg))

    log(f"stage 1: fitting {len(tasks)} models...")
    R = run(tasks, args.workers)
    tasks = []

    # second stage of the transactions SARIMAX, using FORECAST transactions for the future months
    for o in SEL_ORIGINS:
        for cfg in CONFIGS:
            fo = R[("selorders", o, cfg)]
            if not fo["ok"]:
                continue
            xtr = np.log1p(orders.iloc[:o].to_numpy())[:, None]
            xfu = np.log1p(np.maximum(fo["forecast"], 0))[:, None]
            add(y.iloc[:o], cfg, "log1p", xtr, xfu, SEL_H, ("sel", o, cfg, "log1p", None, "transactions"))
    R.update(run(tasks, args.workers))
    tasks = []

    # ------------------------------------------------------------------ selection table
    rows = []
    for (cfg, tr, w, ex) in sel_specs + [(c, "log1p", None, "transactions") for c in CONFIGS]:
        fold = []
        for o in SEL_ORIGINS:
            r = R.get(("sel", o, cfg, tr, w, ex))
            if r is None or not r["ok"]:
                continue
            fold.append({**metrics(y.iloc[o:o + SEL_H], r["forecast"]), "converged": r["converged"]})
        if len(fold) < len(SEL_ORIGINS):
            continue
        f = pd.DataFrame(fold)
        rows.append({"SARIMA": lab(cfg), "transformation": tr, "window": "all" if w is None else w,
                     "exogenous": ex, "folds": len(f), "mean_MAPE": f.MAPE.mean(), "mean_WAPE": f.WAPE.mean(),
                     "mean_MAE": f.MAE.mean(), "mean_RMSE": f.RMSE.mean(), "sd_MAPE": f.MAPE.std(),
                     "all_converged": bool(f.converged.all()), "_cfg": cfg, "_w": "all" if w is None else w})
    sel = pd.DataFrame(rows).sort_values(["mean_WAPE", "mean_MAPE"]).reset_index(drop=True)
    base_sel = []
    for name in baselines(y.iloc[:24], SEL_H):
        f = pd.DataFrame([metrics(y.iloc[o:o + SEL_H], baselines(y.iloc[:o], SEL_H)[name]) for o in SEL_ORIGINS])
        base_sel.append({"SARIMA": name, "transformation": "-", "window": "-", "exogenous": "-", "folds": len(f),
                         "mean_MAPE": f.MAPE.mean(), "mean_WAPE": f.WAPE.mean(), "mean_MAE": f.MAE.mean(),
                         "mean_RMSE": f.RMSE.mean(), "sd_MAPE": f.MAPE.std(), "all_converged": True})
    sel_all = pd.concat([sel, pd.DataFrame(base_sel)], ignore_index=True)
    chosen = sel[sel.all_converged].iloc[0]
    best_plain = sel[sel.all_converged & (sel.exogenous == "none")].iloc[0]
    log(f"chosen on the selection folds: {chosen.SARIMA} {chosen.transformation} window={chosen.window} exog={chosen.exogenous} "
        f"(WAPE {chosen.mean_WAPE:.2f}, MAPE {chosen.mean_MAPE:.2f})")

    # Box-Jenkins identification on the TRAINING months only (no test data): is a 12-month seasonal
    # term justified? Keep s=12 when EITHER formal test finds seasonality at 5%: the Kruskal-Wallis test
    # of monthly growth grouped by calendar month (the "stable seasonality" test X-13 uses), or a lag-12
    # autocorrelation of monthly growth outside its 95% band. STL seasonal strength is reported but not
    # used: with ~3 cycles of a fast-growing series it is unstable (0.62 robust / 0.83 non-robust on the
    # 38 training months, 0.00 / 0.46 on all 50). Then parsimony: the fewest-parameter model in the
    # permitted family whose training residuals pass Ljung-Box (p > 0.05 at lags 6 and 12), trying the
    # original scale before log(1 + units) at each size (no transformation is the simpler model).
    from scipy.stats import kruskal
    from statsmodels.stats.diagnostic import acorr_ljungbox
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    ltr = np.log(train)
    stl_tr = STL(ltr, period=12, robust=True).fit()
    ss_tr = max(0.0, 1 - var(stl_tr.resid) / var(stl_tr.seasonal + stl_tr.resid))
    g_tr = ltr.diff().dropna()
    acf12_tr = float(acf(g_tr, nlags=12)[12])
    band_tr = 1.96 / np.sqrt(len(g_tr))
    kw_p = float(kruskal(*[g_tr[g_tr.index.month == m_].values for m_ in range(1, 13)]).pvalue)
    seasonal_ok = kw_p < 0.05 or abs(acf12_tr) > band_tr
    family = [c for c in CONFIGS if (c[1] != (0, 0, 0)) == seasonal_ok and c[0][1] == 1]
    family.sort(key=lambda c: (sum(c[0]) - c[0][1] + sum(c[1]) - c[1][1], c[0]))
    ident_rows = [{"step": "training months used", "value": f"{train.index[0]:%Y-%m}..{train.index[-1]:%Y-%m} ({len(train)})"},
                  {"step": "Kruskal-Wallis p, monthly growth by calendar month (training)", "value": round(kw_p, 3)},
                  {"step": "STL seasonal strength (training, robust; reported only, unstable)", "value": round(ss_tr, 3)},
                  {"step": "lag-12 ACF of monthly growth (training)", "value": f"{acf12_tr:.3f} (95% band +-{band_tr:.3f})"},
                  {"step": "seasonal term justified?", "value": "yes" if seasonal_ok else "NO -> seasonal order (0,0,0): SARIMA reduces to ARIMA"}]
    recommended = None
    for cfg, trf in [(c, t) for c in family for t in TRANSFORMS]:
        z_ = np.log1p(train.to_numpy()) if trf == "log1p" else train.to_numpy()
        seas_ = (*cfg[1], 12) if cfg[1] != (0, 0, 0) else (0, 0, 0, 0)
        r_ = SARIMAX(z_, order=cfg[0], seasonal_order=seas_).fit(disp=False, maxiter=300)
        res_ = r_.resid[cfg[0][1] + cfg[1][1] * 12:]
        lb_ = acorr_ljungbox(res_, lags=[6, 12], return_df=True)
        ok = bool((lb_.lb_pvalue > 0.05).all())
        ident_rows.append({"step": f"candidate {lab(cfg)} ({trf})",
                           "value": f"Ljung-Box p lag6 {lb_.lb_pvalue.iloc[0]:.3f}, lag12 {lb_.lb_pvalue.iloc[1]:.3f} -> {'adequate' if ok else 'rejected'}"})
        if ok:
            recommended = (cfg, trf)
            ident_rows.append({"step": "parameters", "value": json.dumps({k_: round(float(v_), 3) for k_, v_ in zip(r_.param_names, r_.params)})})
            break
    if recommended is None:
        recommended = (family[0], "none")
    ident = pd.DataFrame(ident_rows)
    log(f"identification: Kruskal-Wallis p {kw_p:.3f}, ACF12 {acf12_tr:.2f} (band {band_tr:.2f}) -> "
        f"recommended {lab(recommended[0])} {recommended[1]}")

    # ------------------------------------------------------------------ final test of the chosen + comparison rows
    def final_fit(cfg, tr, w, ex):
        w = None if w is None or (isinstance(w, float) and np.isnan(w)) else int(w)
        a = 0 if w is None else len(train) - w
        idx_tr, idx_fu = list(range(a, len(train))), list(range(len(train), n))
        ytr = y.iloc[a:len(train)]
        if ex == "transactions":
            fo = fit((orders.iloc[:len(train)].to_numpy(float), cfg, "log1p", None, None, H_TEST, "o"))
            xtr = np.log1p(orders.iloc[:len(train)].to_numpy())[:, None]
            xfu = np.log1p(np.maximum(fo["forecast"], 0))[:, None]
        else:
            xtr, xfu = exog_for(ex, idx_tr, idx_fu, ytr.to_numpy())
        return fit((ytr.to_numpy(float), cfg, tr, xtr, xfu, H_TEST, "final"))

    final_specs = {
        "SARIMA (1,1,1)(0,1,1,12), all history, original (reference)": (REF, "none", None, "none"),
        "SARIMA (0,1,1)(0,1,1,12), all history, original (reference)": (((0, 1, 1), (0, 1, 1)), "none", None, "none"),
        f"Recommended (identification + parsimony): {lab(recommended[0])}, all months, {recommended[1]}":
            (recommended[0], recommended[1], None, "none"),
        f"Fold-selected, unrestricted: {chosen.SARIMA}, {chosen.window} months, {chosen.transformation}, exog {chosen.exogenous}":
            (chosen._cfg, chosen.transformation, None if chosen._w == "all" else int(chosen._w), chosen.exogenous),
        f"Best without exogenous: {best_plain.SARIMA}, {best_plain.window} months, {best_plain.transformation}":
            (best_plain._cfg, best_plain.transformation, None if best_plain._w == "all" else int(best_plain._w), "none"),
    }
    for ex in ("days", "step", "transactions"):
        b = sel[sel.all_converged & (sel.exogenous == ex)]
        if len(b):
            b = b.iloc[0]
            final_specs[f"SARIMAX + {ex}: {b.SARIMA}, {b.window} months, {b.transformation}"] = (b._cfg, b.transformation, None if b._w == "all" else int(b._w), ex)
    finals = {k: final_fit(*v) for k, v in final_specs.items()}
    final_rows = []
    for k, r in finals.items():
        final_rows.append({"Model": k, **metrics(test, r["forecast"]), "AIC (own scale)": r["aic"], "converged": r["converged"]})
    for name, f in baselines(train, H_TEST).items():
        final_rows.append({"Model": name, **metrics(test, f), "AIC (own scale)": np.nan, "converged": True})
    final_tbl = pd.DataFrame(final_rows)
    chosen_key = [k for k in final_specs if k.startswith("Recommended")][0]
    fold_key = [k for k in final_specs if k.startswith("Fold-selected")][0]
    ft = final_tbl.set_index("Model")
    log(f"final test: recommended MAPE {ft.loc[chosen_key, 'MAPE']:.2f}, fold-selected {ft.loc[fold_key, 'MAPE']:.2f}")

    # history-window and config tables on the final test (descriptive: NOT used to choose)
    grid_rows = []
    for cfg, tr, w in itertools.product(CONFIGS, TRANSFORMS, WINDOWS):
        if w is not None and w > len(train):
            grid_rows.append({"SARIMA": lab(cfg), "transformation": tr, "history": w, "train_months": w,
                              "note": f"not possible: only {len(train)} months precede the 12-month test"})
            continue
        r = R[("test", cfg, tr, w)]
        mm = metrics(test, r["forecast"]) if r["ok"] else {}
        grid_rows.append({"SARIMA": lab(cfg), "transformation": tr, "history": "all" if w is None else w,
                          "train_months": len(train) if w is None else w, **{k: mm.get(k) for k in ("MAPE", "WAPE", "MAE", "RMSE")},
                          "AIC": r["aic"], "BIC": r["bic"], "converged": r["converged"],
                          "obs_after_differencing": r["nobs_effective"],
                          "underdetermined": ss.underdetermined(len(train) if w is None else w, *cfg, 12) if cfg[1] != (0, 0, 0) else False,
                          "note": r["error"]})
    grid = pd.DataFrame(grid_rows)
    hist_tbl = grid[(grid.SARIMA.isin([lab(REF), lab(((0, 1, 1), (0, 1, 1)))])) & (grid.transformation == "none")]
    log_tbl = grid[grid.SARIMA.isin([lab(REF), lab(((0, 1, 1), (0, 1, 1)))]) & (grid.history == "all")]

    # error analysis on the final test
    ea = []
    for k in [list(final_specs)[0], fold_key, chosen_key]:
        fc = np.asarray(finals[k]["forecast"])
        for t, a, f in zip(test.index, test.values, fc):
            ea.append({"model": k, "month": t.strftime("%Y-%m"), "actual": int(a), "forecast": round(float(f)),
                       "error (F-A)": round(float(f - a)), "absolute % error": round(abs(f - a) / a * 100, 2)})
    for name, f in baselines(train, H_TEST).items():
        if name.startswith("Naive"):
            for t, a, ff in zip(test.index, test.values, f):
                ea.append({"model": name, "month": t.strftime("%Y-%m"), "actual": int(a), "forecast": round(float(ff)),
                           "error (F-A)": round(float(ff - a)), "absolute % error": round(abs(ff - a) / a * 100, 2)})
    err = pd.DataFrame(ea)

    # ------------------------------------------------------------------ 17. rolling-origin over the whole record (stability)
    roll_specs = {
        "SARIMA (1,1,1)(0,1,1,12) original": (REF, "none", None, "none"),
        "SARIMA (0,1,1)(0,1,1,12) original": (((0, 1, 1), (0, 1, 1)), "none", None, "none"),
        "SARIMA (1,1,1)(0,1,1,12) log": (REF, "log1p", None, "none"),
        f"Fold-selected: {chosen.SARIMA} {chosen.transformation} exog {chosen.exogenous}":
            (chosen._cfg, chosen.transformation, None if chosen._w == "all" else int(chosen._w), chosen.exogenous),
        "ARIMA (0,1,1) original": (((0, 1, 1), (0, 0, 0)), "none", None, "none"),
        "ARIMA (0,1,1) log": (((0, 1, 1), (0, 0, 0)), "log1p", None, "none"),
        "ARIMA (1,1,1) original": (((1, 1, 1), (0, 0, 0)), "none", None, "none"),
    }
    rec_label = f"ARIMA {lab(recommended[0])} {'log' if recommended[1] == 'log1p' else 'original'}"
    if rec_label not in roll_specs:
        roll_specs[rec_label] = (recommended[0], recommended[1], None, "none")
    designs = {"6-month horizon, step 3": (6, list(range(24, n - 6 + 1, 3))),
               "12-month horizon, step 6": (12, list(range(24, n - 12 + 1, 6)))}
    for dname, (h, origins) in designs.items():
        for o in origins:
            for k, (cfg, tr, w, ex) in roll_specs.items():
                a = 0 if w is None else o - w
                if ex == "transactions":
                    add(orders.iloc[:o], cfg, "log1p", None, None, h, ("rollorders", dname, o, k))
                else:
                    xtr, xfu = exog_for(ex, list(range(a, o)), list(range(o, o + h)), y.iloc[a:o].to_numpy())
                    if ex != "none" and xtr is None:
                        xtr = xfu = None
                    add(y.iloc[a:o], cfg, tr, xtr, xfu, h, ("roll", dname, o, k))
    R.update(run(tasks, args.workers))
    tasks = []
    for dname, (h, origins) in designs.items():
        for o in origins:
            for k, (cfg, tr, w, ex) in roll_specs.items():
                if ex == "transactions":
                    fo = R[("rollorders", dname, o, k)]
                    add(y.iloc[:o], cfg, "log1p", np.log1p(orders.iloc[:o].to_numpy())[:, None],
                        np.log1p(np.maximum(fo["forecast"], 0))[:, None], h, ("roll", dname, o, k))
    if tasks:
        R.update(run(tasks, args.workers))
    roll_rows = []
    for dname, (h, origins) in designs.items():
        for o in origins:
            tst = y.iloc[o:o + h]
            for k in roll_specs:
                r = R[("roll", dname, o, k)]
                mm = metrics(tst, r["forecast"]) if r["ok"] else {}
                roll_rows.append({"design": dname, "model": k, "origin": o, "train_to": y.index[o - 1].strftime("%Y-%m"),
                                  "test": f"{tst.index[0]:%Y-%m}..{tst.index[-1]:%Y-%m}", "converged": r["converged"],
                                  **{m_: mm.get(m_, np.nan) for m_ in ("MAPE", "WAPE", "MAE", "RMSE")}})
            for name, f in baselines(y.iloc[:o], h).items():
                mm = metrics(tst, f)
                roll_rows.append({"design": dname, "model": name, "origin": o, "train_to": y.index[o - 1].strftime("%Y-%m"),
                                  "test": f"{tst.index[0]:%Y-%m}..{tst.index[-1]:%Y-%m}", "converged": True,
                                  **{m_: mm[m_] for m_ in ("MAPE", "WAPE", "MAE", "RMSE")}})
    roll = pd.DataFrame(roll_rows)
    roll_sum = (roll.groupby(["design", "model"], sort=False)
                .agg(folds=("MAPE", "size"), mean_MAPE=("MAPE", "mean"), median_MAPE=("MAPE", "median"),
                     sd_MAPE=("MAPE", "std"), mean_WAPE=("WAPE", "mean"), mean_MAE=("MAE", "mean"),
                     mean_RMSE=("RMSE", "mean"), all_converged=("converged", "all")).reset_index())
    log("rolling done")

    # ------------------------------------------------------------------ 21. diagnostics of the chosen model (fitted on the training months)
    ccfg, ctr, cw, cex = final_specs[chosen_key]
    a0 = 0 if cw is None else len(train) - cw
    ytr = train.iloc[a0:]
    z = np.log1p(ytr.to_numpy()) if ctr == "log1p" else ytr.to_numpy()
    xtr = None
    if cex in ("days", "step"):
        xtr, _ = exog_for(cex, list(range(a0, len(train))), list(range(len(train), n)), ytr.to_numpy())
    elif cex == "transactions":
        xtr = np.log1p(orders.iloc[a0:len(train)].to_numpy())[:, None]
    seas = (*ccfg[1], 12) if ccfg[1] != (0, 0, 0) else (0, 0, 0, 0)
    res = SARIMAX(z, exog=xtr, order=ccfg[0], seasonal_order=seas, trend="c" if ccfg[0][1] == 0 else "n").fit(disp=False, maxiter=300)
    burn = ccfg[0][1] + ccfg[1][1] * 12
    resid = res.resid[burn:]
    lags = [l for l in (6, 12) if l < len(resid)]
    lb = acorr_ljungbox(resid, lags=lags, return_df=True)
    diag = pd.DataFrame([
        {"item": "model", "value": chosen_key},
        {"item": "training months", "value": f"{ytr.index[0]:%Y-%m}..{ytr.index[-1]:%Y-%m} ({len(ytr)})"},
        {"item": "residuals used (after differencing burn-in)", "value": len(resid)},
        {"item": "residual mean", "value": float(np.mean(resid))},
        {"item": "residual variance", "value": float(np.var(resid, ddof=1))},
        *[{"item": f"Ljung-Box p-value, lag {l}", "value": float(lb.loc[l, "lb_pvalue"])} for l in lags],
        {"item": "converged", "value": bool(res.mle_retvals.get("converged", False))},
        {"item": "AIC", "value": float(res.aic)}, {"item": "BIC", "value": float(res.bic)},
        {"item": "scale", "value": "log(1 + units)" if ctr == "log1p" else "units"},
    ])
    params = pd.DataFrame({"parameter": res.param_names, "estimate": res.params, "p_value": res.pvalues})
    racf = acf(resid, nlags=min(12, len(resid) // 2 - 1))

    # ------------------------------------------------------------------ 16. product level, monthly
    pm = (it.assign(month=month).groupby(["Product ID", "month"]).Quantity.sum().unstack(0)
          .reindex(y.index).fillna(0.0))
    names = it.drop_duplicates("Product ID").set_index("Product ID")["Product Name"]
    first_m = it.assign(month=month).groupby("Product ID").month.min()
    elig = []
    for pid in pm.columns:
        s_ = pm[pid]
        k = y.index.get_loc(first_m[pid])
        hist = s_.iloc[k:n - 12]
        elig.append({"Product ID": pid, "Product Name": names[pid], "total_units": float(s_.sum()),
                     "training_months": int(len(hist)), "nonzero_share_training": float((hist > 0).mean()) if len(hist) else 0.0,
                     "avg_monthly_units_last_12_train": float(s_.iloc[n - 24:n - 12].mean())})
    elig = pd.DataFrame(elig)
    elig["eligible"] = (elig.training_months >= 24) & (elig.nonzero_share_training >= 0.9)
    top = elig[elig.eligible].sort_values("total_units", ascending=False).head(40)
    PSEAS, PARIMA = ((0, 1, 1), (0, 1, 1)), ((0, 1, 1), (0, 0, 0))
    allp = elig[elig.training_months >= 24]
    topset = set(top["Product ID"])
    for pid in allp["Product ID"]:
        s_ = pm[pid]
        k = y.index.get_loc(first_m[pid])
        add(s_.iloc[k:n - 12], PARIMA, "none", None, None, 12, ("parima12", pid))
        add(s_.iloc[k:n - 3], PARIMA, "none", None, None, 3, ("parima3", pid))
        if pid in topset:
            add(s_.iloc[k:n - 12], PSEAS, "none", None, None, 12, ("pseas12", pid))
            add(s_.iloc[k:n - 3], PSEAS, "none", None, None, 3, ("pseas3", pid))
    log(f"product level: {len(tasks)} fits ({len(top)} detailed products, {len(allp)} products for the volume table)")
    R.update(run(tasks, args.workers))
    tasks = []
    clip = lambda f: np.clip(np.round(np.asarray(f, float)), 0, None)
    prow, vol_rows = [], []
    for pid in allp["Product ID"]:
        s_ = pm[pid]
        k = y.index.get_loc(first_m[pid])
        te12, te3 = s_.iloc[n - 12:], s_.iloc[n - 3:]
        r12, r3 = R[("parima12", pid)], R[("parima3", pid)]
        if not (r12["ok"] and r3["ok"]):
            continue
        m12, m3 = metrics(te12, clip(r12["forecast"])), metrics(te3, clip(r3["forecast"]))
        ma3_3 = metrics(te3, baselines(s_.iloc[k:n - 3], 3)["3-month moving average"])
        vol_rows.append({"pid": pid, "avg": float(s_.iloc[n - 24:n - 12].mean()),
                         "MAPE12": m12["MAPE"], "MAE12": m12["MAE"], "MAPE3": m3["MAPE"], "MAE3": m3["MAE"], "MA3_MAPE3": ma3_3["MAPE"],
                         "abs12": float(np.abs(clip(r12["forecast"]) - te12.values).sum()), "units12": float(te12.sum()),
                         "abs3": float(np.abs(clip(r3["forecast"]) - te3.values).sum()), "units3": float(te3.sum())})
        if pid in topset:
            tr_ = s_.iloc[k:n - 12]
            rs12, rs3 = R[("pseas12", pid)], R[("pseas3", pid)]
            st = STL(np.log1p(tr_), period=12, robust=True).fit()
            sstr = max(0.0, 1 - var(st.resid) / var(st.seasonal + st.resid))
            b12 = baselines(tr_, 12)
            prow.append({"Product ID": pid, "Product Name": names[pid], "historical demand (units)": int(s_.sum()),
                         "training months": len(tr_), "seasonal strength (training)": round(sstr, 2), "history window": "all",
                         "ARIMA(0,1,1) MAPE, 12-mo": round(m12["MAPE"], 2), "ARIMA(0,1,1) WAPE, 12-mo": round(m12["WAPE"], 2),
                         "ARIMA(0,1,1) MAE, 12-mo": round(m12["MAE"], 2), "ARIMA(0,1,1) RMSE, 12-mo": round(m12["RMSE"], 2),
                         "SARIMA(0,1,1)(0,1,1,12) MAPE, 12-mo": round(metrics(te12, clip(rs12["forecast"]))["MAPE"], 2) if rs12["ok"] else np.nan,
                         "naive MAPE, 12-mo": round(metrics(te12, b12["Naive (last month)"])["MAPE"], 2),
                         "3-month MA MAPE, 12-mo": round(metrics(te12, b12["3-month moving average"])["MAPE"], 2),
                         "ARIMA(0,1,1) MAPE, 3-mo": round(m3["MAPE"], 2),
                         "SARIMA(0,1,1)(0,1,1,12) MAPE, 3-mo": round(metrics(te3, clip(rs3["forecast"]))["MAPE"], 2) if rs3["ok"] else np.nan})
    prod = pd.DataFrame(prow)
    vol = pd.DataFrame(vol_rows)
    bands = [(0, 5, "under 5 a month"), (5, 20, "5-20"), (20, 100, "20-100"), (100, 1e12, "100+")]
    def vrow(v, nm):
        return {"products selling (a month, year before test)": nm, "products": len(v),
                "share of products %": round(len(v) / len(vol) * 100, 1),
                "ARIMA(0,1,1) mean MAPE, 3-month test": round(v.MAPE3.mean(), 1),
                "3-month MA mean MAPE, 3-month test": round(v.MA3_MAPE3.mean(), 1),
                "ARIMA(0,1,1) mean MAE, 3-month test": round(v.MAE3.mean(), 2),
                "pooled WAPE, 3-month": round(v.abs3.sum() / v.units3.sum() * 100, 1) if v.units3.sum() else np.nan,
                "ARIMA(0,1,1) mean MAPE, 12-month test": round(v.MAPE12.mean(), 1),
                "pooled WAPE, 12-month": round(v.abs12.sum() / v.units12.sum() * 100, 1) if v.units12.sum() else np.nan}
    vol_tbl = pd.DataFrame([vrow(vol[(vol.avg >= lo) & (vol.avg < hi)], nm) for lo, hi, nm in bands] + [vrow(vol, "ALL")])
    log(f"product level done: {len(prod)} detailed, volume table over {len(vol)} products")

    app_tbl = None
    if args.app_accuracy_csv and os.path.exists(args.app_accuracy_csv):
        a_ = pd.read_csv(args.app_accuracy_csv)
        rows_ = []
        for lo, hi, nm in bands:
            v = a_[(a_.avg_monthly_units >= lo) & (a_.avg_monthly_units < hi)]
            rows_.append({"products selling": nm, "products": len(v), "mean MAPE": round(v.mape.mean(), 1),
                          "share": round(len(v) / len(a_) * 100, 1)})
        rows_.append({"products selling": "ALL", "products": len(a_), "mean MAPE": round(a_.mape.mean(), 1), "share": 100.0})
        app_tbl = pd.DataFrame(rows_)

    # ------------------------------------------------------------------ charts
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    plt.rcParams.update({"figure.dpi": 130, "font.size": 9, "axes.spines.top": False, "axes.spines.right": False})
    G, B, O, GR = "#0f6e56", "#185FA5", "#c2410c", "#94a3b8"
    def save(fig, name):
        fig.tight_layout()
        fig.savefig(os.path.join(charts, name))
        plt.close(fig)

    fig, ax = plt.subplots(figsize=(10, 3.8))
    ax.plot(y.index, y.values, color=G, marker="o", ms=3)
    ax.axvspan(test.index[0], test.index[-1], color=GR, alpha=0.25, label="final test (12 months)")
    ax.set_title("Chart 1. Monthly units sold, every complete month (Jun 2022 - Jul 2026)")
    ax.set_ylabel("units")
    ax.legend(frameon=False)
    save(fig, "chart1_monthly_demand.png")

    fig, axes = plt.subplots(1, 2, figsize=(11, 3.8))
    for yr, grp in y.groupby(y.index.year):
        axes[0].plot(grp.index.month, grp.values, marker="o", ms=3, label=str(yr))
    axes[0].set_xticks(range(1, 13))
    axes[0].set_xticklabels([pd.Timestamp(2000, m, 1).strftime("%b") for m in range(1, 13)])
    axes[0].legend(frameon=False, fontsize=8)
    axes[0].set_title("Each year's months (growth dominates)")
    axes[1].bar(seas_tbl["calendar month"], seas_tbl["seasonal index (100 = average)"], color=B)
    axes[1].axhline(100, color=GR, ls="--")
    axes[1].set_title(f"Seasonal index (trend removed); STL seasonal strength {seas_strength:.2f}")
    fig.suptitle("Chart 2. Monthly seasonal pattern")
    save(fig, "chart2_seasonal_pattern.png")

    fig, ax = plt.subplots(figsize=(8, 3.8))
    for tr, col in (("none", O), ("log1p", B)):
        d = grid[(grid.SARIMA == lab(REF)) & (grid.transformation == tr) & grid.MAPE.notna()]
        ax.plot(d.history.astype(str), d.MAPE, marker="o", color=col, label=f"{lab(REF)} {tr}")
    ax.set_title("Chart 3. Final-test MAPE by history window (42/48 months impossible: 38 precede the test)")
    ax.set_xlabel("training months")
    ax.set_ylabel("MAPE %")
    ax.legend(frameon=False)
    save(fig, "chart3_mape_by_window.png")

    fig, ax = plt.subplots(figsize=(11, 4.2))
    d = grid[(grid.history == "all")].pivot_table(index="SARIMA", columns="transformation", values="MAPE")
    d = d.reindex([lab(c) for c in CONFIGS])
    xs = np.arange(len(d))
    ax.bar(xs - 0.2, d["none"], 0.4, color=O, label="original")
    ax.bar(xs + 0.2, d["log1p"], 0.4, color=B, label="log(1+units)")
    ax.set_xticks(xs)
    ax.set_xticklabels(d.index, rotation=30, ha="right")
    ax.set_ylabel("final-test MAPE %")
    ax.set_title("Chart 4. MAPE by SARIMA configuration (all 38 training months, 12-month test)")
    ax.legend(frameon=False)
    save(fig, "chart4_mape_by_configuration.png")

    fig, ax = plt.subplots(figsize=(10, 3.8))
    ax.plot(y.index[-30:], y.values[-30:], color=G, marker="o", ms=3, label="actual")
    for k, col in ((list(final_specs)[0], O), ("Naive (last month)", GR)):
        f = finals[k]["forecast"] if k in finals else baselines(train, 12)[k]
        m_ = metrics(test, f)
        ax.plot(test.index, f, ls="--", marker="o", ms=3, color=col, label=f"{k.split(',')[0] if k in finals else k}: MAPE {m_['MAPE']:.1f}%")
    ax.set_title("Chart 5. Actual vs predicted, final 12-month test: reference SARIMA(1,1,1)(0,1,1,12) vs naive")
    ax.legend(frameon=False, fontsize=8)
    save(fig, "chart5_actual_vs_predicted.png")

    fig, axes = plt.subplots(1, 2, figsize=(12, 4))
    for ax, (dname, _) in zip(axes, designs.items()):
        d = roll[roll.design == dname]
        show = ["SARIMA (1,1,1)(0,1,1,12) original", [k_ for k_ in roll_specs if k_.startswith("Fold-selected")][0], rec_label,
                "Naive (last month)", "3-month moving average"]
        for k, col in zip(show, [O, "#7c3aed", B, GR, "#64748b"]):
            dd = d[d.model == k]
            ax.plot(dd.test.str[:7], dd.MAPE, marker="o", ms=3, color=col, label=f"{k[:38]}: {dd.MAPE.mean():.1f}%")
        ax.set_title(f"Chart 6. Rolling-origin MAPE, {dname}")
        ax.tick_params(axis="x", rotation=45)
        ax.legend(frameon=False, fontsize=7)
    save(fig, "chart6_rolling_validation.png")

    fig, axes = plt.subplots(1, 2, figsize=(11, 3.6))
    axes[0].plot(ytr.index[-len(resid):], resid, color=B, marker="o", ms=3)
    axes[0].axhline(0, color=GR)
    axes[0].set_title("Residuals over time (training months)")
    band = 1.96 / np.sqrt(len(resid))
    axes[1].bar(range(1, len(racf)), racf[1:], color=B, width=0.4)
    axes[1].axhline(band, color=O, ls="--")
    axes[1].axhline(-band, color=O, ls="--")
    axes[1].set_title("Residual ACF (dashed = 95% band)")
    fig.suptitle(f"Chart 7. Residuals of the recommended model ({lab(recommended[0])}, {recommended[1]}), training months")
    save(fig, "chart7_residuals.png")

    fig, ax = plt.subplots(figsize=(10, 3.8))
    ax.plot(y.index[-30:], y.values[-30:], color=G, marker="o", ms=3, label="actual")
    m_ = metrics(test, finals[chosen_key]["forecast"])
    ax.plot(test.index, finals[chosen_key]["forecast"], color=B, ls="--", marker="o", ms=3,
            label=f"recommended: {lab(recommended[0])} {recommended[1]}, MAPE {m_['MAPE']:.1f}%, WAPE {m_['WAPE']:.1f}%")
    mf = metrics(test, finals[fold_key]["forecast"])
    ax.plot(test.index, finals[fold_key]["forecast"], color=O, ls=":", marker="o", ms=3,
            label=f"fold-selected {chosen.SARIMA} {chosen.transformation}+{chosen.exogenous}: MAPE {mf['MAPE']:.1f}%")
    ax.set_title("Chart 8. Actual vs forecast, recommended model vs the unrestricted fold winner (final 12-month test)")
    ax.legend(frameon=False, fontsize=8)
    save(fig, "chart8_best_model.png")

    # ------------------------------------------------------------------ Excel
    xlsx = os.path.join(args.out, "Monthly_SARIMA_MAPE_Optimization.xlsx")
    def put(xw, df, sheet, startrow=0, title=None):
        if title:
            pd.DataFrame({title: []}).to_excel(xw, sheet_name=sheet, index=False, startrow=startrow)
            startrow += 1
        df.to_excel(xw, sheet_name=sheet, index=False, startrow=startrow)
        return startrow + len(df) + 3
    with pd.ExcelWriter(xlsx, engine="openpyxl") as xw:
        r_ = put(xw, summary, "Data Summary")
        put(xw, big.groupby("Product Name").Quantity.agg(["count", "max"]).reset_index().rename(
            columns={"count": "lines with qty >= 50", "max": "largest line"}).sort_values("largest line", ascending=False),
            "Data Summary", r_, "Unusually large line quantities (kept: legitimate bulk sales)")
        put(xw, mdemand, "Monthly Demand")
        r_ = put(xw, final_tbl[final_tbl.Model.isin(baselines(train, 1).keys())], "Baseline Models", 0, "Final 12-month test")
        put(xw, pd.DataFrame(base_sel), "Baseline Models", r_, "Selection folds (inside training)")
        put(xw, hist_tbl, "History Windows", 0, "Final 12-month test, original scale (descriptive)")
        put(xw, grid, "SARIMA Results", 0, "Every configuration x transformation x window on the final test (descriptive, NOT used to choose)")
        r_ = put(xw, log_tbl, "Log Transformation", 0, "Final test, all 38 training months")
        put(xw, sel[(sel.exogenous == "none") & (sel.window == "all")].groupby("transformation")[["mean_MAPE", "mean_WAPE", "mean_MAE", "mean_RMSE"]].median().reset_index(),
            "Log Transformation", r_, "Selection folds: median over all configurations, by transformation")
        r_ = put(xw, ident, "Outlier Analysis", 0, "Identification on the training months (decides whether s=12 is used)")
        r_ = put(xw, outliers if len(outliers) else pd.DataFrame({"result": ["no month flagged"]}), "Outlier Analysis", r_,
                 "Months flagged (robust z of monthly log growth > 3.5, or growth outside 3 IQR); none removed")
        put(xw, seas_facts, "Outlier Analysis", r_, "Trend, seasonality and structural change")
        put(xw, seas_tbl, "Outlier Analysis", r_ + len(seas_facts) + 4, "Seasonal indices")
        r_ = put(xw, sel[sel.exogenous != "none"].drop(columns=["_cfg", "_w"]), "SARIMAX Results", 0,
                 "Selection folds: SARIMAX with days-in-month, a training-data step dummy, or forecast transactions")
        put(xw, final_tbl[final_tbl.Model.str.startswith("SARIMAX")], "SARIMAX Results", r_, "Final 12-month test")
        r_ = put(xw, roll_sum, "Rolling Validation", 0, "Summary")
        r_ = put(xw, sel_all.drop(columns=["_cfg", "_w"], errors="ignore"), "Rolling Validation", r_,
                 "Selection folds inside the training months (this table chose the model)")
        put(xw, roll, "Rolling Validation", r_, "Every fold")
        r_ = put(xw, prod, "Product Forecasting", 0, "Top 40 eligible products (>= 24 training months, >= 90% non-zero months)")
        r_ = put(xw, vol_tbl, "Product Forecasting", r_, f"All {len(vol)} products with >= 24 training months, ARIMA(0,1,1), by sales volume")
        if app_tbl is not None:
            put(xw, app_tbl, "Product Forecasting", r_, "The app's own per-product holdout (forecast_accuracy), by volume")
        put(xw, err, "Error Analysis", 0, "Final 12-month test, month by month")
        r_ = put(xw, diag, "Diagnostics")
        put(xw, params, "Diagnostics", r_)
        r_ = put(xw, final_tbl, "Final Comparison", 0, "Final 12-month test (Aug 2025 - Jul 2026)")
        put(xw, roll_sum, "Final Comparison", r_, "Rolling-origin validation")
        for sh in xw.sheets.values():
            for col in sh.columns:
                sh.column_dimensions[col[0].column_letter].width = min(60, max(10, max(len(str(c.value or "")) for c in col[:80]) + 2))

    pd.to_pickle({"summary": summary, "mdemand": mdemand, "sel": sel, "sel_all": sel_all, "chosen": chosen,
                  "final_tbl": final_tbl, "grid": grid, "err": err, "roll_sum": roll_sum, "roll": roll, "diag": diag,
                  "params": params, "prod": prod, "vol_tbl": vol_tbl, "app_tbl": app_tbl, "outliers": outliers,
                  "seas_facts": seas_facts, "seas_tbl": seas_tbl, "chosen_key": chosen_key, "fold_key": fold_key,
                  "ident": ident, "recommended": recommended},
                 os.path.join(args.out, "_monthly.pkl"))
    log(f"wrote {xlsx}")


if __name__ == "__main__":
    main()
