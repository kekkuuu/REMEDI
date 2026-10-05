#!/usr/bin/env python3
"""
Diagnosis of the ~55% MAPE of weekly SARIMA(1,1,1)(0,1,1,52) on 52 weeks of history, on the RED pharmacy
transaction dataset (transaction_items_2022_2026.csv, Jun 2022 - Aug 2026).

    python mape_diagnosis.py --data <folder with the 3 CSVs> --out <output folder> --workers 8

Builds on sarima_study.py (same loader, same complete-period rules, same metrics). Nothing in the data is
edited, removed or invented. Every number in SARIMA_MAPE_Diagnosis.xlsx is computed by this script.

Conventions
  * Demand = Quantity (units). Weeks run Monday-Sunday (pandas "W-SUN", labelled by the Sunday).
  * Only complete weeks: the first week (ending 2022-06-05; trading starts Wed 2022-06-01) is excluded.
    The data ends Sunday 2026-08-16 22:53, so the week ending 2026-08-16 is complete and is the last week.
    August 2026 (1-16 only) is the incomplete month.
  * Test block = the last 12 complete weeks (2026-05-25 .. 2026-08-16), the same for every model.
  * MAPE only over weeks with actual > 0 (the count excluded is reported; it is 0 for the store total).
"""

import argparse
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
import sarima_study as ss  # noqa: E402

warnings.filterwarnings("ignore")

S = 52
H = 12
WINDOWS = [52, 78, 104, 130, 156, None]          # None = all complete weeks before the test block
REF = ((1, 1, 1), (0, 1, 1))                      # the configuration under diagnosis
GRID = [((0, 1, 1), (0, 1, 1)), ((1, 1, 0), (0, 1, 1)), ((1, 1, 1), (0, 1, 1)), ((2, 1, 1), (0, 1, 1)),
        ((0, 1, 1), (1, 1, 1)), ((1, 1, 1), (1, 1, 1)), ((2, 1, 1), (1, 1, 1)),
        # reasonable variations
        ((0, 1, 2), (0, 1, 1)), ((1, 1, 0), (1, 1, 1)), ((1, 1, 1), (1, 1, 0))]
ROLL_CFGS = [((1, 1, 1), (0, 1, 1)), ((0, 1, 1), (0, 1, 1)), ((1, 1, 1), (1, 1, 1))]
ROLL_STEP = 4
CLAIMED = [  # the benchmark the user was given, to verify independently
    ("Weekly + 52 weeks + (1,1,1)(0,1,1,52)", 52, ((1, 1, 1), (0, 1, 1)), 51.26),
    ("Weekly + 104 weeks + (1,1,1)(0,1,1,52)", 104, ((1, 1, 1), (0, 1, 1)), 6.49),
    ("Weekly + 104 weeks + (1,1,1)(1,1,1,52)", 104, ((1, 1, 1), (1, 1, 1)), 6.49),
    ("Weekly + 104 weeks + (0,1,1)(0,1,1,52)", 104, ((0, 1, 1), (0, 1, 1)), 6.84),
    ("Weekly + all history + (1,1,1)(0,1,1,52)", None, ((1, 1, 1), (0, 1, 1)), 5.71),
]


def label(cfg):
    return f"({','.join(map(str, cfg[0]))})({','.join(map(str, cfg[1]))},52)"


def wname(w, n):
    return f"All ({n})" if w is None else str(w)


# ----------------------------------------------------------------------------- one fit, with the evidence
def fit(task):
    """Fit SARIMA on y, forecast h. Returns the forecast plus what the optimiser actually did."""
    y, order, sorder, h, tag = task
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    out = {"tag": tag, "ok": False, "converged": False, "aic": np.nan, "bic": np.nan, "llf": np.nan,
           "nobs_effective": len(y) - order[1] - sorder[1] * S, "params": {}, "forecast": [np.nan] * h,
           "fitted": None, "error": ""}
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            res = SARIMAX(np.asarray(y, float), order=order, seasonal_order=(*sorder, S)).fit(disp=False, maxiter=200)
        fc = np.asarray(res.forecast(h), float)
        out.update(ok=bool(np.all(np.isfinite(fc))), converged=bool(res.mle_retvals.get("converged", False)),
                   aic=float(res.aic), bic=float(res.bic), llf=float(res.llf),
                   params={k: round(float(v), 4) for k, v in zip(res.param_names, res.params)},
                   forecast=fc.tolist())
    except Exception as exc:  # noqa: BLE001
        out["error"] = str(exc)[:200]
    # "Estimable": data left after differencing, and a likelihood that actually moved off its start.
    out["estimable"] = out["nobs_effective"] > 0 and not (out["llf"] == 0.0)
    return out


def run(tasks, workers):
    if workers <= 1:
        return {r["tag"]: r for r in map(fit, tasks)}
    with ProcessPoolExecutor(max_workers=workers) as pool:
        return {r["tag"]: r for r in pool.map(fit, tasks, chunksize=1)}


def m(actual, fc):
    r = ss.metrics(actual, fc)
    r["excluded_zero_actual"] = int(len(actual) - r["MAPE_points"])
    return r


def base_fc(train, h=H):
    hist = train.to_numpy(float)
    return {"Naive (last week, held for 12 weeks)": np.repeat(hist[-1], h),
            "Seasonal naive (same week last year)": np.array([hist[len(hist) - S + i] for i in range(h)])}


# ----------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--workers", type=int, default=8)
    args = ap.parse_args()
    charts = os.path.join(args.out, "charts")
    os.makedirs(charts, exist_ok=True)
    t0 = time.time()
    log = lambda s: print(f"[{time.time() - t0:6.0f}s] {s}", flush=True)

    # ------------------------------------------------------------------ 1-3. data, quality, complete weeks
    it = ss.load(args.data)
    q, big = ss.quality(it, args.data)
    missing = it.drop(columns="dt").isna().sum()
    extra = [{"check": f"missing values: {c}", "value": int(v), "note": ""} for c, v in missing.items()]
    lines_q = it.Quantity
    p99 = lines_q.quantile(0.99)
    per_prod = it.groupby("Product ID").Quantity
    z = (lines_q - per_prod.transform("median")) / per_prod.transform(lambda s: s.quantile(0.75) - s.quantile(0.25) + 1)
    extra += [
        {"check": "quantity per line: median / 99th pct / max", "value": f"{lines_q.median():.0f} / {p99:.0f} / {lines_q.max():.0f}", "note": ""},
        {"check": "lines more than 10 IQRs above their own product's median", "value": int((z > 10).sum()),
         "note": "checked: bulk purchases of ordinary stock (e.g. boxes of tablets); line total = qty x price on every one, so kept"},
    ]
    summary = pd.concat([q, pd.DataFrame(extra)], ignore_index=True)

    monthly, weekly, excluded = ss.series(it)
    y = weekly
    n = len(y)
    first, last = it.dt.min(), it.dt.max()
    summary = pd.concat([summary, pd.DataFrame([
        {"check": "week definition", "value": "Monday-Sunday (W-SUN)", "note": "labelled by the Sunday that ends the week"},
        {"check": "excluded weeks", "value": ", ".join(excluded["weekly_excluded"]),
         "note": f"first week: trading starts {first:%a %Y-%m-%d}, so that week is partial"},
        {"check": "last complete week", "value": str(y.index[-1].date()),
         "note": f"last sale {last:%a %Y-%m-%d %H:%M}; that Sunday is a full trading day"},
        {"check": "excluded months", "value": ", ".join(excluded["monthly_excluded"]), "note": "Aug 2026 has 16 of 31 days"},
        {"check": "last complete month", "value": str(monthly.index[-1].date())[:7], "note": ""},
        {"check": "complete weeks", "value": n, "note": f"{y.index[0].date()} .. {y.index[-1].date()}"},
        {"check": "test block", "value": f"{(y.index[-H] - pd.Timedelta(days=6)).date()} .. {y.index[-1].date()}",
         "note": "last 12 complete weeks; the same block for every model"},
    ])], ignore_index=True)

    orders_m = it.groupby(it.dt.dt.to_period("M"))["Order#"].nunique()
    orders_w = it.set_index("dt").resample("W-SUN")["Order#"].nunique()
    per_month = pd.DataFrame({"month": orders_m.index.astype(str), "transactions": orders_m.values,
                              "units": it.groupby(it.dt.dt.to_period("M")).Quantity.sum().values})
    wk = pd.DataFrame({"week_start": (y.index - pd.Timedelta(days=6)).date, "week_end": y.index.date,
                       "units": y.values.astype(int), "transactions": orders_w.reindex(y.index).values})
    wk["role"] = ["test" if i >= n - H else "train" for i in range(n)]
    for w in [52, 78, 104, 130, 156]:
        wk[f"in_{w}w_window"] = [(n - H - w) <= i < n - H for i in range(n)]
    log(f"{n} complete weeks; test {wk.week_start.iloc[-H]}..{wk.week_end.iloc[-1]}")

    train, test = y.iloc[:-H], y.iloc[-H:]

    # ------------------------------------------------------------------ 4-7. windows x grid on the fixed test block
    tasks = []
    for w in WINDOWS:
        tr = train if w is None else train.iloc[-w:]
        for cfg in GRID:
            tasks.append((tr.to_numpy(), cfg[0], cfg[1], H, ("test", w, cfg)))
            vtr = train.iloc[:-H] if w is None else train.iloc[:-H].iloc[-w:]  # validation: the 12 weeks before the test
            tasks.append((vtr.to_numpy(), cfg[0], cfg[1], H, ("val", w, cfg)))
    # origin sensitivity of the 52- and 104-week reference model (test block shifted back k weeks)
    for k in range(0, 53):
        yy = y.iloc[:n - k]
        for w in (52, 104):
            tasks.append((yy.iloc[:-H].iloc[-w:].to_numpy(), REF[0], REF[1], H, ("origin", w, k)))
    # rolling origin
    origins_long = list(range(104, n - H + 1, ROLL_STEP))
    origins_common = [o for o in origins_long if o >= 156]
    roll_plan = []
    for w in WINDOWS:
        origins = origins_long if w in (52, 78, 104) else origins_common
        for o in origins:
            tr = y.iloc[:o] if w is None else y.iloc[o - w:o]
            for cfg in ROLL_CFGS:
                tag = ("roll", w, o, cfg)
                roll_plan.append(tag)
                tasks.append((tr.to_numpy(), cfg[0], cfg[1], H, tag))
    log(f"fitting {len(tasks)} SARIMA models on {args.workers} workers...")
    R = run(tasks, args.workers)
    log("fits done")

    vtest = train.iloc[-H:]
    grid_rows = []
    for w in WINDOWS:
        tn = len(train) if w is None else w
        for cfg in GRID:
            r, rv = R[("test", w, cfg)], R[("val", w, cfg)]
            mt = m(test, r["forecast"]) if r["ok"] else {}
            mv = m(vtest, rv["forecast"]) if rv["ok"] else {}
            grid_rows.append({
                "History (weeks)": wname(w, tn), "train_weeks": tn, "SARIMA": label(cfg),
                "obs_left_after_differencing": r["nobs_effective"], "parameters_estimated": r["estimable"],
                "converged": r["converged"], "MAPE": mt.get("MAPE"), "MAE": mt.get("MAE"), "RMSE": mt.get("RMSE"),
                "WAPE": mt.get("WAPE"), "weeks_excluded_actual_0": mt.get("excluded_zero_actual"),
                "AIC": r["aic"], "BIC": r["bic"], "log_likelihood": r["llf"],
                "validation_MAPE (12 wks before test)": mv.get("MAPE"),
                "parameters": json.dumps(r["params"]), "_fc": r["forecast"],
            })
    grid = pd.DataFrame(grid_rows)
    hist_tbl = grid[grid.SARIMA == label(REF)].drop(columns=["_fc"]).reset_index(drop=True)

    base_rows = []
    for name, f in base_fc(train).items():
        base_rows.append({"Model": name, **{k: m(test, f)[k] for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    one_step = y.iloc[-H - 1:-1].to_numpy()  # previous week's ACTUAL, re-read every week
    base_rows.append({"Model": "Naive one-step (previous week's actual, updated weekly)",
                      **{k: m(test, one_step)[k] for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    for w in (52, 104):
        row = hist_tbl[hist_tbl.train_weeks == w].iloc[0]
        base_rows.append({"Model": f"SARIMA(1,1,1)(0,1,1,52), {w} weeks", "MAPE": row.MAPE, "MAE": row.MAE,
                          "RMSE": row.RMSE, "WAPE": row.WAPE})
    base_tbl = pd.DataFrame(base_rows)
    for k in ("MAPE", "MAE", "RMSE", "WAPE"):
        base_tbl[k] = base_tbl[k].astype(float)

    bench = []
    for name, w, cfg, claimed in CLAIMED:
        g = grid[(grid.SARIMA == label(cfg)) & (grid.train_weeks == (len(train) if w is None else w))].iloc[0]
        bench.append({"Configuration": name, "Claimed MAPE": claimed, "Measured MAPE": round(g.MAPE, 2),
                      "Difference (pp)": round(g.MAPE - claimed, 2),
                      "Reproduced (within 0.5 pp)": abs(g.MAPE - claimed) <= 0.5})
    bench = pd.DataFrame(bench)

    origin_rows = []
    for k in range(0, 53):
        yy = y.iloc[:n - k]
        tst = yy.iloc[-H:]
        row = {"test_from": str((tst.index[0] - pd.Timedelta(days=6)).date()), "test_to": str(tst.index[-1].date()),
               "weeks_shifted_back": k}
        for w in (52, 104):
            r = R[("origin", w, k)]
            row[f"MAPE_{w}w"] = m(tst, r["forecast"])["MAPE"] if r["ok"] else np.nan
            row[f"params_estimated_{w}w"] = r["estimable"]
        row["MAPE_naive"] = m(tst, np.repeat(yy.iloc[-H - 1], H))["MAPE"]
        origin_rows.append(row)
    origin = pd.DataFrame(origin_rows)

    # ------------------------------------------------------------------ 10. why 52 fails
    w52, w104 = train.iloc[-52:], train.iloc[-104:]
    yr1, yr2 = w104.iloc[:52].to_numpy(), w104.iloc[52:].to_numpy()

    def stats(s):
        x = np.arange(len(s))
        return {"weeks": len(s), "mean": s.mean(), "sd": s.std(ddof=1), "CV %": s.std(ddof=1) / s.mean() * 100,
                "min": s.min(), "max": s.max(), "trend (units/week, OLS)": np.polyfit(x, s.to_numpy(), 1)[0],
                "lag-1 autocorrelation": s.autocorr(1)}
    why = pd.DataFrame({"Last 52 weeks": stats(w52), "Last 104 weeks": stats(w104)}).T
    why["obs left after d=1, D=1 (s=52)"] = [52 - 1 - 52, 104 - 1 - 52]
    why["same-week-last-year correlation (lag 52)"] = [np.nan, float(np.corrcoef(yr1, yr2)[0, 1])]
    why["year-on-year growth % (2nd year vs 1st)"] = [np.nan, (yr2.mean() / yr1.mean() - 1) * 100]
    r52 = R[("test", 52, REF)]
    why_notes = pd.DataFrame([
        {"finding": "52-week fit: parameters", "value": json.dumps(r52["params"]),
         "meaning": "every coefficient is still at its starting value (0, 0, 0; sigma2 = 1): nothing was estimated"},
        {"finding": "52-week fit: log-likelihood", "value": r52["llf"],
         "meaning": "0 means no observation contributed to the likelihood"},
        {"finding": "52-week fit: observations after differencing", "value": r52["nobs_effective"],
         "meaning": "d=1 and D=1 with s=52 consume 53 weeks; a 52-week window leaves none"},
        {"finding": "52-week: distinct test MAPEs across all 10 configurations",
         "value": int(grid[grid.train_weeks == 52].MAPE.round(6).nunique()),
         "meaning": "every configuration gives the identical forecast, because none of them is estimated"},
        {"finding": "52-week forecast minus the same week last year (constant)",
         "value": float(np.round(np.mean(np.array(r52["forecast"]) - w52.iloc[:H].to_numpy()), 1)),
         "meaning": "the forecast is last year's shape shifted by a level that comes from the model's unestimated starting state, not from the data"},
        {"finding": "52-week MAPE range over 53 different test blocks",
         "value": f"{origin.MAPE_52w.min():.1f}% .. {origin.MAPE_52w.max():.1f}%",
         "meaning": "the 51-55% you saw is one draw from this range; the number depends on which 12 weeks are tested"},
        {"finding": "104-week MAPE range over the same 53 test blocks",
         "value": f"{origin.MAPE_104w.min():.1f}% .. {origin.MAPE_104w.max():.1f}%", "meaning": ""},
    ])

    # ------------------------------------------------------------------ 11-12. rolling origin
    roll_rows = []
    for (_, w, o, cfg) in roll_plan:
        r = R[("roll", w, o, cfg)]
        tst = y.iloc[o:o + H]
        mm = m(tst, r["forecast"]) if r["ok"] else {}
        roll_rows.append({"History (weeks)": wname(w, o), "window": "All" if w is None else w, "model": f"SARIMA {label(cfg)}",
                          "origin": o, "test_from": str((tst.index[0] - pd.Timedelta(days=6)).date()),
                          "test_to": str(tst.index[-1].date()), "converged": r["converged"],
                          "parameters_estimated": r["estimable"],
                          **{k: mm.get(k, np.nan) for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    for o in origins_long:
        tst = y.iloc[o:o + H]
        for name, f in base_fc(y.iloc[:o]).items():
            mm = m(tst, f)
            roll_rows.append({"History (weeks)": "-", "window": "baseline", "model": name, "origin": o,
                              "test_from": str((tst.index[0] - pd.Timedelta(days=6)).date()), "test_to": str(tst.index[-1].date()),
                              "converged": True, "parameters_estimated": True,
                              **{k: mm[k] for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    roll = pd.DataFrame(roll_rows)

    def summarise(df, label_):
        g = df.groupby(["window", "model"], sort=False)
        s = g.agg(folds=("MAPE", "size"), mean_MAPE=("MAPE", "mean"), median_MAPE=("MAPE", "median"),
                  sd_MAPE=("MAPE", "std"), min_MAPE=("MAPE", "min"), max_MAPE=("MAPE", "max"),
                  mean_MAE=("MAE", "mean"), mean_RMSE=("RMSE", "mean"), mean_WAPE=("WAPE", "mean"),
                  all_converged=("converged", "all"), all_estimated=("parameters_estimated", "all")).reset_index()
        s.insert(0, "fold set", label_)
        return s

    common = roll[roll.origin.isin(origins_common)]
    longset = roll[roll.origin.isin(origins_long) & roll.window.isin([52, 78, 104, "baseline"])]
    roll_sum = pd.concat([summarise(common, f"A: {len(origins_common)} folds, test blocks from "
                                             f"{common.test_from.min()} (all 6 windows comparable)"),
                          summarise(longset, f"B: {len(origins_long)} folds, test blocks from "
                                             f"{longset.test_from.min()} (52/78/104 only)")], ignore_index=True)
    log("rolling done")

    # single holdout vs rolling for the claimed 6.49% configuration
    h104 = hist_tbl[hist_tbl.train_weeks == 104].iloc[0]
    rs = roll_sum[(roll_sum["fold set"].str.startswith("B")) & (roll_sum.window == 104)
                  & (roll_sum.model == f"SARIMA {label(REF)}")].iloc[0]

    # ------------------------------------------------------------------ 16. final selection: rolling, set A
    cand = roll_sum[roll_sum["fold set"].str.startswith("A") & (roll_sum.window != "baseline") & roll_sum.all_estimated]
    final = cand.sort_values(["mean_MAPE", "sd_MAPE"]).iloc[0]
    fw = None if final.window == "All" else int(final.window)
    fcfg = next(c for c in ROLL_CFGS if f"SARIMA {label(c)}" == final.model)
    log(f"final (lowest rolling mean MAPE, set A): {final.model} on {final.window}: {final.mean_MAPE:.2f}%")

    # ------------------------------------------------------------------ 14. diagnostics of the final model (fitted on its window before the test)
    diag_y = train if fw is None else train.iloc[-fw:]
    diag, params, resid = ss.diagnostics(diag_y, fcfg[0], fcfg[1], S)
    from statsmodels.tsa.stattools import acf, pacf
    nl = min(20, len(resid) // 2 - 1)
    racf, rpacf = acf(resid, nlags=nl), pacf(resid, nlags=nl)
    diag_tbl = pd.DataFrame([{"item": k, "value": v} for k, v in diag.items()] +
                            [{"item": "residual variance", "value": float(np.var(resid, ddof=1))},
                             {"item": "ACF lags outside +-1.96/sqrt(n)", "value": int((np.abs(racf[1:]) > 1.96 / np.sqrt(len(resid))).sum())},
                             {"item": "lags checked", "value": nl}])

    # ------------------------------------------------------------------ 13. per product
    pp, elig = ss.per_product(it, y.index, args.workers, top_n=50, history=104)
    log(f"per product: {len(pp)} modelled of {int(elig.eligible.sum())} eligible")

    # ------------------------------------------------------------------ 17. final comparison table
    fc_rows = []
    for w in WINDOWS:
        tn = len(train) if w is None else w
        sub = grid[(grid.train_weeks == tn)]
        best_val = sub[sub.parameters_estimated & sub.converged].sort_values("validation_MAPE (12 wks before test)")
        ref = sub[sub.SARIMA == label(REF)].iloc[0]
        ra = roll_sum[roll_sum["fold set"].str.startswith("A") & (roll_sum.window == ("All" if w is None else w))
                      & (roll_sum.model == f"SARIMA {label(REF)}")]
        fc_rows.append({
            "Frequency": "Weekly", "History": wname(w, tn), "SARIMA": label(REF),
            "Single-holdout MAPE": ref.MAPE, "MAE": ref.MAE, "RMSE": ref.RMSE,
            "Rolling mean MAPE (set A)": ra.mean_MAPE.iloc[0] if len(ra) else np.nan,
            "Rolling SD (set A)": ra.sd_MAPE.iloc[0] if len(ra) else np.nan,
            "Validation": ("NOT ESTIMABLE (0 obs after differencing)" if not ref.parameters_estimated
                           else f"rolling {int(ra.folds.iloc[0])} folds"),
            "Config chosen on validation block": best_val.SARIMA.iloc[0] if len(best_val) else "none estimable",
            "Its test MAPE": best_val.MAPE.iloc[0] if len(best_val) else np.nan,
        })
    final_tbl = pd.DataFrame(fc_rows)
    final_tbl.insert(0, "Rank*", final_tbl["Rolling mean MAPE (set A)"].rank(method="min").astype("Int64"))

    # ------------------------------------------------------------------ charts
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    plt.rcParams.update({"figure.dpi": 130, "font.size": 9, "axes.spines.top": False, "axes.spines.right": False})
    C_ACT, C_52, C_104, C_GREY = "#0f6e56", "#c2410c", "#185FA5", "#94a3b8"
    paths = {}

    fig, axes = plt.subplots(3, 1, figsize=(10, 8.5))
    axes[0].plot(y.index, y.values, color=C_ACT, lw=1.3)
    axes[0].axvspan(train.index[-104], train.index[-1], color=C_104, alpha=0.08, label="104-week window")
    axes[0].axvspan(train.index[-52], train.index[-1], color=C_52, alpha=0.12, label="52-week window")
    axes[0].axvspan(test.index[0], test.index[-1], color=C_GREY, alpha=0.25, label="test (12 weeks)")
    axes[0].set_title("Chart 1. Weekly units sold, every complete week (Jun 2022 - Aug 2026)")
    axes[0].legend(loc="upper left", frameon=False)
    axes[1].plot(w52.index, w52.values, color=C_52, lw=1.5)
    axes[1].set_title(f"Last 52 training weeks: one year, one of each week -- nothing to compare a week with")
    axes[2].plot(w104.index[52:], yr2, color=C_104, lw=1.5, label="2nd year (most recent)")
    axes[2].plot(w104.index[52:], yr1, color=C_104, lw=1.2, ls="--", alpha=0.6, label="1st year, same weeks")
    axes[2].set_title(f"Last 104 training weeks, as two years overlaid: same-week correlation "
                      f"{why.loc['Last 104 weeks', 'same-week-last-year correlation (lag 52)']:.2f}, growth "
                      f"{why.loc['Last 104 weeks', 'year-on-year growth % (2nd year vs 1st)']:.1f}%")
    axes[2].legend(frameon=False)
    for a in axes:
        a.set_ylabel("units")
    fig.tight_layout()
    paths["chart1"] = os.path.join(charts, "chart1_weekly_demand_and_windows.png")
    fig.savefig(paths["chart1"])
    plt.close(fig)

    fig, ax = plt.subplots(figsize=(8, 4))
    xs = [wname(w, len(train)) for w in WINDOWS]
    vals = hist_tbl.MAPE.to_numpy()
    bars = ax.bar(xs, vals, color=[C_52 if not e else C_104 for e in hist_tbl.parameters_estimated])
    for b, v in zip(bars, vals):
        ax.text(b.get_x() + b.get_width() / 2, v + 0.5, f"{v:.1f}%", ha="center")
    nv = base_tbl.loc[0, "MAPE"]
    ax.axhline(nv, color=C_GREY, ls="--", lw=1)
    ax.text(len(xs) - 0.5, nv + 0.6, f"naive {nv:.1f}%", ha="right", color="#475569")
    ax.set_xlabel("training history (weeks)")
    ax.set_ylabel("test MAPE %")
    ax.set_title("Chart 2. MAPE by history window -- SARIMA(1,1,1)(0,1,1,52), same 12 test weeks\n"
                 "(orange = nothing estimable: 52 weeks leave 0 observations after differencing)")
    fig.tight_layout()
    paths["chart2"] = os.path.join(charts, "chart2_mape_by_history_window.png")
    fig.savefig(paths["chart2"])
    plt.close(fig)

    fig, ax = plt.subplots(figsize=(10, 4.6))
    piv = grid.pivot_table(index="SARIMA", columns="train_weeks", values="MAPE").reindex([label(c) for c in GRID])
    cols = [c for c in piv.columns if c != 52]
    width = 0.8 / len(cols)
    for i, c in enumerate(cols):
        ax.bar(np.arange(len(piv)) + i * width, piv[c], width, label=f"{c} weeks" if c != len(train) else f"all ({c})")
    ax.set_xticks(np.arange(len(piv)) + 0.4 - width / 2)
    ax.set_xticklabels(piv.index, rotation=30, ha="right")
    ax.set_ylabel("test MAPE %")
    ax.set_title(f"Chart 3. MAPE by SARIMA configuration and window (single 12-week test). "
                 f"52 weeks omitted: {piv[52].iloc[0]:.1f}% for every configuration")
    ax.legend(frameon=False, ncol=5, fontsize=8)
    fig.tight_layout()
    paths["chart3"] = os.path.join(charts, "chart3_mape_by_configuration.png")
    fig.savefig(paths["chart3"])
    plt.close(fig)

    for key, w, col in (("chart4", 52, C_52), ("chart5", 104, C_104)):
        r = R[("test", w, REF)]
        fig, ax = plt.subplots(figsize=(10, 3.8))
        ctx = train.iloc[-26:]
        ax.plot(ctx.index, ctx.values, color=C_ACT, lw=1.2, label="actual (training)")
        ax.plot(test.index, test.values, color=C_ACT, lw=2, marker="o", ms=3, label="actual (test)")
        ax.plot(test.index, r["forecast"], color=col, lw=2, ls="--", marker="o", ms=3, label=f"SARIMA forecast, {w} weeks")
        row = hist_tbl[hist_tbl.train_weeks == w].iloc[0]
        ax.set_title(f"Chart {key[-1]}. Actual vs forecast, {w}-week history: MAPE {row.MAPE:.1f}%, MAE {row.MAE:,.0f}, RMSE {row.RMSE:,.0f}"
                     + ("  (nothing estimated)" if not row.parameters_estimated else ""))
        ax.set_ylabel("units")
        ax.legend(frameon=False, loc="lower left")
        fig.tight_layout()
        paths[key] = os.path.join(charts, f"{key}_actual_vs_forecast_{w}w.png")
        fig.savefig(paths[key])
        plt.close(fig)

    fig, axes = plt.subplots(1, 2, figsize=(11, 4.2), gridspec_kw={"width_ratios": [1.25, 1]})
    ax = axes[0]
    for (w, mdl), col in (((104, f"SARIMA {label(REF)}"), C_104), ((52, f"SARIMA {label(REF)}"), C_52),
                          (("baseline", "Naive (last week, held for 12 weeks)"), C_GREY)):
        d = longset[(longset.window == w) & (longset.model == mdl)]
        ax.plot(pd.to_datetime(d.test_from), d.MAPE, marker="o", ms=3, color=col,
                label=f"{'52' if w == 52 else '104' if w == 104 else 'naive'}: mean {d.MAPE.mean():.1f}%")
    ax.axhline(h104.MAPE, color=C_104, lw=0.8, ls=":")
    ax.text(pd.to_datetime(longset.test_from.min()), h104.MAPE + 1, f"single holdout (104 w): {h104.MAPE:.1f}%", color=C_104, fontsize=8)
    ax.set_title(f"Chart 6a. Rolling 12-week MAPE per fold ({len(origins_long)} folds, step {ROLL_STEP} weeks)")
    ax.set_ylabel("MAPE %")
    ax.set_xlabel("start of the 12-week test block")
    ax.legend(frameon=False, fontsize=8)
    ax = axes[1]
    sa = roll_sum[roll_sum["fold set"].str.startswith("A") & (roll_sum.model == f"SARIMA {label(REF)}")]
    lbls = [("All" if w == "All" else str(w)) for w in sa.window]
    ax.bar(lbls, sa.mean_MAPE, yerr=sa.sd_MAPE, color=[C_52 if w == 52 else C_104 for w in sa.window], capsize=3)
    bA = roll_sum[roll_sum["fold set"].str.startswith("A") & (roll_sum.window == "baseline")]
    for _, b in bA.iterrows():
        ax.axhline(b.mean_MAPE, color=C_GREY, ls="--" if "Naive" in b.model else ":", lw=1)
        ax.text(len(lbls) - 0.5, b.mean_MAPE + 0.8, ("naive " if "Naive" in b.model else "seasonal naive ") + f"{b.mean_MAPE:.1f}%",
                ha="right", fontsize=8, color="#475569")
    ax.set_title(f"Chart 6b. Mean rolling MAPE +- SD by window\n(set A: the same {len(origins_common)} folds for every window)")
    ax.set_xlabel("training history (weeks)")
    fig.tight_layout()
    paths["chart6"] = os.path.join(charts, "chart6_rolling_validation.png")
    fig.savefig(paths["chart6"])
    plt.close(fig)

    fig, axes = plt.subplots(2, 2, figsize=(10, 6.5))
    axes[0, 0].plot(diag_y.index[-len(resid):], resid, color=C_104, lw=1)
    axes[0, 0].axhline(0, color="#64748b", lw=0.8)
    axes[0, 0].set_title("Residuals over time")
    axes[0, 1].hist(resid, bins=20, color=C_104, alpha=0.8)
    axes[0, 1].set_title(f"Residual distribution (mean {diag['residual_mean']:.0f}, sd {diag['residual_sd']:.0f})")
    band = 1.96 / np.sqrt(len(resid))
    for ax, vals_, t in ((axes[1, 0], racf, "Residual ACF"), (axes[1, 1], rpacf, "Residual PACF")):
        ax.bar(range(1, len(vals_)), vals_[1:], color=C_104, width=0.4)
        ax.axhline(band, color=C_52, ls="--", lw=0.8)
        ax.axhline(-band, color=C_52, ls="--", lw=0.8)
        ax.axhline(0, color="#64748b", lw=0.6)
        ax.set_title(t + " (dashed = 95% band)")
    fig.suptitle(f"Chart 7. Residual diagnostics, {final.model} on {final.window} weeks: Ljung-Box p (lag 10) "
                 f"{diag['ljung_box_lag10_p']:.2f}, (lag 20) {diag['ljung_box_lag20_p']:.2f}")
    fig.tight_layout()
    paths["chart7"] = os.path.join(charts, "chart7_residual_diagnostics.png")
    fig.savefig(paths["chart7"])
    plt.close(fig)

    fig, ax = plt.subplots(figsize=(10, 3.8))
    od = origin.iloc[::-1]
    ax.plot(pd.to_datetime(od.test_from), od.MAPE_52w, color=C_52, marker="o", ms=3, label="52 weeks (nothing estimated)")
    ax.plot(pd.to_datetime(od.test_from), od.MAPE_104w, color=C_104, marker="o", ms=3, label="104 weeks")
    ax.plot(pd.to_datetime(od.test_from), od.MAPE_naive, color=C_GREY, marker="o", ms=2, label="naive")
    ax.axhspan(51, 55, color=C_52, alpha=0.1)
    ax.text(pd.to_datetime(od.test_from.iloc[0]), 56, "your 51-55%", color=C_52, fontsize=8)
    ax.set_title("Chart 8. The same SARIMA(1,1,1)(0,1,1,52), test block moved back one week at a time (53 test blocks)")
    ax.set_ylabel("MAPE %")
    ax.set_xlabel("start of the 12-week test block")
    ax.legend(frameon=False, fontsize=8)
    fig.tight_layout()
    paths["chart8"] = os.path.join(charts, "chart8_origin_sensitivity.png")
    fig.savefig(paths["chart8"])
    plt.close(fig)

    # ------------------------------------------------------------------ Excel + CSV
    xlsx = os.path.join(args.out, "SARIMA_MAPE_Diagnosis.xlsx")
    sarima_res = grid.drop(columns=["_fc"])
    fc_detail = pd.DataFrame({"week_end": test.index.date, "actual": test.values})
    for w in WINDOWS:
        fc_detail[f"SARIMA(1,1,1)(0,1,1,52) {wname(w, len(train))}w"] = np.round(R[("test", w, REF)]["forecast"], 0)
    for name, f in base_fc(train).items():
        fc_detail[name] = f
    pp_out = pp.rename(columns={"total_units": "Total quantity sold", "weekly_observations": "Weekly observations (training)",
                                "best_SARIMA (chosen on validation)": "Best SARIMA (chosen on validation)",
                                "history": "Training window", "test_MAPE": "MAPE", "test_MAE": "MAE", "test_RMSE": "RMSE"})
    with pd.ExcelWriter(xlsx, engine="openpyxl") as xw:
        summary.to_excel(xw, sheet_name="Data Summary", index=False)
        per_month.to_excel(xw, sheet_name="Data Summary", index=False, startrow=len(summary) + 3)
        wk.to_excel(xw, sheet_name="Weekly Data", index=False)
        base_tbl.to_excel(xw, sheet_name="Baseline Results", index=False)
        fc_detail.to_excel(xw, sheet_name="Baseline Results", index=False, startrow=len(base_tbl) + 3)
        hist_tbl.to_excel(xw, sheet_name="History Window Results", index=False)
        bench.to_excel(xw, sheet_name="History Window Results", index=False, startrow=len(hist_tbl) + 3)
        sarima_res.to_excel(xw, sheet_name="SARIMA Results", index=False)
        roll_sum.to_excel(xw, sheet_name="Rolling Validation", index=False)
        roll.to_excel(xw, sheet_name="Rolling Validation", index=False, startrow=len(roll_sum) + 3)
        pp_out.to_excel(xw, sheet_name="Product Results", index=False)
        elig.to_excel(xw, sheet_name="Product Results", index=False, startrow=len(pp_out) + 3)
        diag_tbl.to_excel(xw, sheet_name="Diagnostics", index=False)
        params.to_excel(xw, sheet_name="Diagnostics", index=False, startrow=len(diag_tbl) + 3)
        final_tbl.to_excel(xw, sheet_name="Final Comparison", index=False)
        why.reset_index(names="window").to_excel(xw, sheet_name="Why 52 Weeks Fails", index=False)
        why_notes.to_excel(xw, sheet_name="Why 52 Weeks Fails", index=False, startrow=len(why) + 3)
        origin.to_excel(xw, sheet_name="Origin Sensitivity", index=False)
        for sh in xw.sheets.values():
            for col in sh.columns:
                sh.column_dimensions[col[0].column_letter].width = min(48, max(10, max(len(str(c.value or "")) for c in col[:60]) + 2))
    for name, df in (("history_window_results", hist_tbl), ("sarima_results", sarima_res), ("baseline_results", base_tbl),
                     ("rolling_validation_folds", roll), ("rolling_validation_summary", roll_sum), ("product_results", pp_out),
                     ("final_comparison", final_tbl), ("benchmark_check", bench), ("origin_sensitivity", origin),
                     ("weekly_data", wk)):
        df.to_csv(os.path.join(args.out, f"{name}.csv"), index=False)

    pd.to_pickle({"hist": hist_tbl, "grid": sarima_res, "base": base_tbl, "bench": bench, "origin": origin, "why": why,
                  "why_notes": why_notes, "roll_sum": roll_sum, "final": final, "final_tbl": final_tbl, "diag": diag,
                  "pp": pp_out, "elig_n": int(elig.eligible.sum()), "summary": summary, "per_month": per_month,
                  "h104": h104, "rs104": rs, "paths": paths, "origins": (len(origins_common), len(origins_long))},
                 os.path.join(args.out, "_diagnosis.pkl"))
    log(f"wrote {xlsx}")


if __name__ == "__main__":
    main()
