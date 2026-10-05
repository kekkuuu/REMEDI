#!/usr/bin/env python3
"""
SARIMA forecasting study on the RED pharmacy transaction dataset (Jun 2022 - Aug 2026).

    python sarima_study.py --data <folder with the 3 CSVs> --out <output folder> --workers 15

Target: units sold (the Quantity column of transaction_items_2022_2026.csv). Nothing in the data is
edited, removed or invented: every figure is computed from the file as supplied.

Conventions (all documented in the report):
  * Weeks end on SUNDAY (pandas "W-SUN"), months are calendar months.
  * Only COMPLETE periods are modelled or scored. The data runs 2022-06-01 18:18 to 2026-08-16 22:53:
    the first week (ending 2022-06-05, Wed-Sun only) and the month of August 2026 (1-16 only) are
    incomplete and are excluded. 2026-08-16 is a Sunday and a full trading day, so the week ending
    2026-08-16 IS complete.
  * MAPE is computed on periods whose actual is > 0 (never divides by zero); MAE, RMSE and WAPE
    (sum of absolute errors / sum of actuals) are reported beside it.
  * No leakage: a model's parameters are estimated on its training window only; the CONFIGURATION is
    chosen on a validation block carved from the END OF THE TRAINING DATA (the last 12 periods before
    the test block), and the 12-period test block is used only to score the chosen configuration.
    Test-set scores of every other configuration are listed for transparency, never used to choose.
  * A configuration is "underdetermined" when the series left after differencing
    (n - d - D*s) is not longer than the lags it must estimate (p + q + P*s + Q*s); such fits are
    reported but never selected.
  * Trend term: a constant when d = 0 (the seasonally differenced series has a non-zero mean while the
    store grows); none when d = 1.
"""

import argparse
import json
import os
import time
import warnings

for _v in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ.setdefault(_v, "1")

from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

TEST_N = 12          # periods in the holdout test block
VAL_N = 12           # periods in the validation block (end of training data)
NONSEASONAL = [(0, 1, 1), (1, 1, 0), (1, 1, 1), (2, 1, 1), (1, 0, 1), (2, 0, 1)]
SEASONAL = [(0, 1, 1), (1, 1, 0), (1, 1, 1), (2, 1, 1)]
WINDOWS = {"M": [24, 36, None], "W": [52, 78, 104, None]}   # None = all available
SEASON = {"M": 12, "W": 52}


# ----------------------------------------------------------------------------- metrics
def metrics(actual, forecast):
    a = np.asarray(actual, dtype=float)
    f = np.asarray(forecast, dtype=float)
    e = f - a
    nz = a > 0
    return {
        "MAPE": float(np.mean(np.abs(e[nz] / a[nz])) * 100) if nz.any() else np.nan,
        "MAE": float(np.mean(np.abs(e))),
        "RMSE": float(np.sqrt(np.mean(e ** 2))),
        "WAPE": float(np.sum(np.abs(e)) / np.sum(a) * 100) if a.sum() > 0 else np.nan,
        "MAPE_points": int(nz.sum()),
    }


def underdetermined(n, order, sorder, s):
    """Too little data left after differencing: fewer points than the AR lags reach back, or fewer
    than five spare points beyond the parameters estimated (p + q + P + Q, plus the variance)."""
    p, d, q = order
    P, D, Q = sorder
    n_eff = n - d - D * s
    return n_eff <= p + P * s or n_eff < (p + q + P + Q + 1) + 5


# ----------------------------------------------------------------------------- one SARIMA fit
def fit_forecast(task):
    """Worker: fit SARIMA on `y` and forecast `h` steps. Never raises."""
    y, freq, order, sorder, s, h, tag = task
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    out = {"tag": tag, "ok": False, "converged": False, "aic": np.nan, "bic": np.nan,
           "forecast": [np.nan] * h, "error": ""}
    try:
        trend = "c" if order[1] == 0 else "n"
        model = SARIMAX(np.asarray(y, dtype=float), order=order, seasonal_order=(*sorder, s), trend=trend)
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            res = model.fit(disp=False, maxiter=200)
        fc = np.asarray(res.forecast(h), dtype=float)
        out.update(ok=bool(np.all(np.isfinite(fc))), converged=bool(res.mle_retvals.get("converged", False)),
                   aic=float(res.aic), bic=float(res.bic), forecast=fc.tolist())
    except Exception as exc:  # noqa: BLE001 - a failed fit is a result, not a crash
        out["error"] = str(exc)[:200]
    return out


def run_tasks(tasks, workers):
    if workers <= 1:
        return [fit_forecast(t) for t in tasks]
    with ProcessPoolExecutor(max_workers=workers) as pool:
        return list(pool.map(fit_forecast, tasks, chunksize=1))


# ----------------------------------------------------------------------------- data
def load(data_dir):
    it = pd.read_csv(os.path.join(data_dir, "transaction_items_2022_2026.csv"),
                     dtype={"SKU / Barcode": str, "Product ID": str})
    it["dt"] = pd.to_datetime(it["Date / Time"], format="%m/%d/%Y %I:%M:%S %p", errors="coerce")
    return it


def quality(it, data_dir):
    rows = []
    add = lambda k, v, note="": rows.append({"check": k, "value": v, "note": note})
    add("item lines", len(it))
    add("unparsed dates", int(it.dt.isna().sum()))
    add("first transaction", str(it.dt.min()))
    add("last transaction", str(it.dt.max()), "2026-08-16 is a Sunday; the last day has a normal order count")
    days = pd.Series(1, index=pd.to_datetime(it.dt.dt.date.unique())).sort_index()
    cal = pd.date_range(days.index.min(), days.index.max(), freq="D")
    missing = cal.difference(days.index)
    add("calendar days", len(cal))
    add("days with no sales", len(missing), ", ".join(d.strftime("%Y-%m-%d") for d in missing))
    add("missing quantity", int(it.Quantity.isna().sum()))
    add("negative quantity", int((it.Quantity < 0).sum()))
    add("zero quantity", int((it.Quantity == 0).sum()))
    add("exact duplicate lines", int(it.duplicated(subset=[c for c in it.columns if c != "dt"]).sum()))
    add("same product twice in one order", int(it.duplicated(["Order#", "Product ID"]).sum()))
    add("line total != qty x price", int((abs(it.Quantity * it["Unit Price"] - it["Line Total"]) > 0.011).sum()))
    add("orders", int(it["Order#"].nunique()))
    add("products", int(it["Product ID"].nunique()))
    add("total units", int(it.Quantity.sum()))
    add("max quantity on one line", int(it.Quantity.max()))
    big = it[it.Quantity >= 50]
    add("lines with quantity >= 50", len(big),
        f"{big['Product Name'].nunique()} products; kept (bulk purchases are legitimate sales)")
    # Daily-total outliers (IQR rule on the log of daily units): reported, never removed.
    daily = it.groupby(it.dt.dt.date).Quantity.sum()
    l = np.log(daily)
    q1, q3 = l.quantile([0.25, 0.75])
    out_days = daily[(l < q1 - 3 * (q3 - q1)) | (l > q3 + 3 * (q3 - q1))]
    add("extreme days (3xIQR on log daily units)", len(out_days),
        ", ".join(f"{d}:{int(v)}" for d, v in out_days.items()) or "none")
    tx = pd.read_csv(os.path.join(data_dir, "transactions_2022_2026.csv"))
    add("transactions file rows", len(tx), "one row per order")
    add("orders in items but not in transactions",
        int(len(set(it["Order#"].astype(str)) - set(tx["Order#"].astype(str)))))
    pc = pd.read_csv(os.path.join(data_dir, "product_costs.csv"), dtype={"SKU / Barcode": str})
    add("product_costs rows", len(pc))
    return pd.DataFrame(rows), big


def series(it):
    q = it.set_index("dt").Quantity
    monthly = q.resample("MS").sum()
    weekly = q.resample("W-SUN").sum()            # index = the Sunday that ends the week
    first_day = it.dt.min().normalize()
    last_day = it.dt.max().normalize()
    # complete months: month start >= first day's month start AND month end <= last day
    m_complete = monthly[(monthly.index >= first_day.to_period("M").start_time)
                         & (monthly.index + pd.offsets.MonthEnd(0) <= last_day)]
    if first_day.day != 1:
        m_complete = m_complete.iloc[1:]
    # complete weeks: the week's Monday >= first day AND its Sunday <= last day
    w_complete = weekly[(weekly.index - pd.Timedelta(days=6) >= first_day) & (weekly.index <= last_day)]
    excluded = {
        "monthly_excluded": [str(i.date()) for i in monthly.index.difference(m_complete.index)],
        "weekly_excluded": [str(i.date()) for i in weekly.index.difference(w_complete.index)],
    }
    return m_complete.astype(float), w_complete.astype(float), excluded


# ----------------------------------------------------------------------------- experiment helpers
def baselines(train, h, s):
    """Naive (last value carried forward) and seasonal naive (same period one season earlier)."""
    naive = np.repeat(train.iloc[-1], h)
    hist = train.to_numpy()
    snaive = np.array([hist[len(hist) - s + (i % s)] for i in range(h)]) if len(hist) >= s else np.full(h, np.nan)
    mean3 = np.repeat(train.iloc[-3:].mean(), h)
    return {"Naive (last period)": naive, "Seasonal naive (t - s)": snaive, "Mean of last 3 periods": mean3}


def window_slice(train, window):
    return train if window is None else train.iloc[-window:]


def grid_tasks(freq, train, h, tag_prefix):
    s = SEASON[freq]
    tasks, meta = [], []
    for window in WINDOWS[freq]:
        y = window_slice(train, window)
        for order in NONSEASONAL:
            for sorder in SEASONAL:
                tag = f"{tag_prefix}|{freq}|{window}|{order}|{sorder}"
                meta.append({"freq": freq, "window": window if window else f"all ({len(y)})",
                             "window_n": len(y), "order": order, "sorder": sorder,
                             "underdetermined": underdetermined(len(y), order, sorder, s), "tag": tag})
                tasks.append((y.to_numpy(), freq, order, sorder, s, h, tag))
    return tasks, meta


def holdout(freq, y, workers, h=TEST_N):
    """Validation-selected holdout: returns the full results table and the chosen configuration."""
    s = SEASON[freq]
    train, test = y.iloc[:-h], y.iloc[-h:]
    vtrain, vtest = train.iloc[:-VAL_N], train.iloc[-VAL_N:]
    vt, vmeta = grid_tasks(freq, vtrain, VAL_N, "val")
    tt, tmeta = grid_tasks(freq, train, h, "test")
    res = {r["tag"]: r for r in run_tasks(vt + tt, workers)}
    rows = []
    for vm, tm in zip(vmeta, tmeta):
        rv, rt = res[vm["tag"]], res[tm["tag"]]
        mv = metrics(vtest, rv["forecast"]) if rv["ok"] else {}
        mt = metrics(test, rt["forecast"]) if rt["ok"] else {}
        rows.append({
            "frequency": "Weekly" if freq == "W" else "Monthly",
            "history": tm["window"], "train_periods": tm["window_n"],
            "SARIMA": f"{tm['order']}{(*tm['sorder'], s)}",
            "underdetermined": tm["underdetermined"] or vm["underdetermined"],
            "val_MAPE": mv.get("MAPE", np.nan), "val_WAPE": mv.get("WAPE", np.nan),
            "test_MAPE": mt.get("MAPE", np.nan), "test_MAE": mt.get("MAE", np.nan),
            "test_RMSE": mt.get("RMSE", np.nan), "test_WAPE": mt.get("WAPE", np.nan),
            "AIC": rt["aic"], "converged": rt["converged"] and rv["converged"],
            "fit_ok": rt["ok"] and rv["ok"], "error": rt["error"] or rv["error"],
            "_forecast": rt["forecast"], "_order": tm["order"], "_sorder": tm["sorder"], "_window": tm["window_n"],
        })
    df = pd.DataFrame(rows)
    for name, f in baselines(train, h, s).items():
        m = metrics(test, f)
        vb = baselines(vtrain, VAL_N, s)[name]
        df = pd.concat([df, pd.DataFrame([{
            "frequency": "Weekly" if freq == "W" else "Monthly", "history": "-", "train_periods": len(train),
            "SARIMA": name, "underdetermined": False, "val_MAPE": metrics(vtest, vb)["MAPE"],
            "val_WAPE": metrics(vtest, vb)["WAPE"], "test_MAPE": m["MAPE"], "test_MAE": m["MAE"],
            "test_RMSE": m["RMSE"], "test_WAPE": m["WAPE"], "AIC": np.nan, "converged": True, "fit_ok": True,
            "error": "", "_forecast": list(f), "_order": None, "_sorder": None, "_window": None}])],
            ignore_index=True)
    eligible = df[df._order.notna() & df.fit_ok & df.converged & ~df.underdetermined & df.val_MAPE.notna()]
    if eligible.empty:  # say so rather than crash; fall back to any fit that ran
        eligible = df[df._order.notna() & df.fit_ok & df.val_MAPE.notna()]
        print(f"WARNING: no well-determined, converged {freq} configuration; choosing among all fits", flush=True)
    chosen = eligible.sort_values(["val_MAPE", "AIC"]).iloc[0]
    return df, chosen, train, test


def walk_forward(freq, y, configs, train_n, h, step, workers):
    """Sliding-window rolling origin: train on `train_n` periods, forecast the next `h`, move by `step`."""
    s = SEASON[freq]
    folds = []
    start = 0
    while start + train_n + h <= len(y):
        folds.append((start, start + train_n, start + train_n + h))
        start += step
    tasks, meta = [], []
    for (a, b, c) in folds:
        for (order, sorder) in configs:
            tag = f"wf|{freq}|{a}|{order}|{sorder}"
            tasks.append((y.iloc[a:b].to_numpy(), freq, order, sorder, s, h, tag))
            meta.append((a, b, c, f"{order}{(*sorder, s)}", tag))
    res = {r["tag"]: r for r in run_tasks(tasks, workers)}
    rows = []
    for (a, b, c, label, tag) in meta:
        r = res[tag]
        m = metrics(y.iloc[b:c], r["forecast"]) if r["ok"] else {}
        rows.append({"frequency": "Weekly" if freq == "W" else "Monthly", "model": label,
                     "train_from": str(y.index[a].date()), "train_to": str(y.index[b - 1].date()),
                     "test_from": str(y.index[b].date()), "test_to": str(y.index[c - 1].date()),
                     "converged": r["converged"], **{k: m.get(k, np.nan) for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    for (a, b, c) in folds:
        for name, f in baselines(y.iloc[a:b], h, s).items():
            m = metrics(y.iloc[b:c], f)
            rows.append({"frequency": "Weekly" if freq == "W" else "Monthly", "model": name,
                         "train_from": str(y.index[a].date()), "train_to": str(y.index[b - 1].date()),
                         "test_from": str(y.index[b].date()), "test_to": str(y.index[c - 1].date()),
                         "converged": True, **{k: m[k] for k in ("MAPE", "MAE", "RMSE", "WAPE")}})
    return pd.DataFrame(rows)


def summarise_wf(wf):
    g = wf.groupby(["frequency", "model"])
    out = g.agg(folds=("MAPE", "size"), mean_MAPE=("MAPE", "mean"), median_MAPE=("MAPE", "median"),
                sd_MAPE=("MAPE", "std"), min_MAPE=("MAPE", "min"), max_MAPE=("MAPE", "max"),
                mean_MAE=("MAE", "mean"), mean_RMSE=("RMSE", "mean"), mean_WAPE=("WAPE", "mean"),
                all_converged=("converged", "all")).reset_index()
    return out.sort_values(["frequency", "mean_MAPE"])


# ----------------------------------------------------------------------------- per product
PRODUCT_CONFIGS = [((1, 1, 1), (0, 1, 1)), ((0, 1, 1), (0, 1, 1)), ((1, 1, 1), (0, 0, 0)), ((0, 1, 1), (0, 0, 0))]


def per_product(it, w_index, workers, top_n=30, history=104):
    """Weekly per-product SARIMA on products with enough history and few empty weeks."""
    q = it.set_index("dt")
    weekly = (q.groupby("Product ID").Quantity.resample("W-SUN").sum().unstack(0)
              .reindex(w_index).fillna(0.0))
    names = it.drop_duplicates("Product ID").set_index("Product ID")["Product Name"]
    first = q.reset_index().groupby("Product ID").dt.min()
    train_end = len(w_index) - TEST_N
    rows_elig = []
    for pid in weekly.columns:
        col = weekly[pid]
        first_week = col.index.searchsorted(first[pid])
        weeks_hist = train_end - first_week
        recent = col.iloc[max(0, train_end - history):train_end]
        rows_elig.append({"Product ID": pid, "Product Name": names[pid], "total_units": float(col.sum()),
                          "weeks_of_history_before_test": int(weeks_hist),
                          "nonzero_share_last_104": float((recent > 0).mean()),
                          "eligible": weeks_hist >= history + VAL_N and (recent > 0).mean() >= 0.9})
    elig = pd.DataFrame(rows_elig)
    chosen = elig[elig.eligible].sort_values("total_units", ascending=False).head(top_n)
    tasks, meta = [], []
    for pid in chosen["Product ID"]:
        y = weekly[pid]
        train, test = y.iloc[:train_end], y.iloc[train_end:]
        vtrain = train.iloc[:-VAL_N]
        for (order, sorder) in PRODUCT_CONFIGS:
            for phase, tr in (("val", vtrain.iloc[-history:]), ("test", train.iloc[-history:])):
                tag = f"pp|{pid}|{phase}|{order}|{sorder}"
                tasks.append((tr.to_numpy(), "W", order, sorder, 52, TEST_N, tag))
                meta.append((pid, phase, order, sorder, tag))
    res = {r["tag"]: r for r in run_tasks(tasks, workers)}
    rows = []
    for pid in chosen["Product ID"]:
        y = weekly[pid]
        train, test = y.iloc[:train_end], y.iloc[train_end:]
        vtest = train.iloc[-VAL_N:]
        cand = []
        for (order, sorder) in PRODUCT_CONFIGS:
            rv = res[f"pp|{pid}|val|{order}|{sorder}"]
            rt = res[f"pp|{pid}|test|{order}|{sorder}"]
            if rv["ok"] and rt["ok"]:
                cand.append((metrics(vtest, np.clip(rv["forecast"], 0, None))["MAPE"], order, sorder,
                             metrics(test, np.clip(rt["forecast"], 0, None)), rt["converged"]))
        if not cand:
            continue
        best = sorted(cand, key=lambda c: (np.nan_to_num(c[0], nan=1e9)))[0]
        b = baselines(train, TEST_N, 52)
        rows.append({
            "Product ID": pid, "Product Name": names[pid], "total_units": int(y.sum()),
            "weekly_observations": int(len(train.iloc[-history:])), "nonzero_weeks_in_window": int((train.iloc[-history:] > 0).sum()),
            "best_SARIMA (chosen on validation)": f"{best[1]}{(*best[2], 52)}", "history": f"{history} weeks",
            "val_MAPE": best[0], "test_MAPE": best[3]["MAPE"], "test_MAE": best[3]["MAE"],
            "test_RMSE": best[3]["RMSE"], "test_WAPE": best[3]["WAPE"], "converged": best[4],
            "naive_test_MAPE": metrics(test, b["Naive (last period)"])["MAPE"],
            "seasonal_naive_test_MAPE": metrics(test, b["Seasonal naive (t - s)"])["MAPE"],
            "mean3_test_MAPE": metrics(test, b["Mean of last 3 periods"])["MAPE"],
        })
    return pd.DataFrame(rows), elig


# ----------------------------------------------------------------------------- diagnostics
def diagnostics(y, order, sorder, s):
    from statsmodels.stats.diagnostic import acorr_ljungbox
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    trend = "c" if order[1] == 0 else "n"
    res = SARIMAX(y.to_numpy(dtype=float), order=order, seasonal_order=(*sorder, s), trend=trend).fit(disp=False, maxiter=200)
    burn = order[1] + sorder[1] * s
    resid = res.resid[burn:]
    lb = acorr_ljungbox(resid, lags=[10, 20], return_df=True)
    params = pd.DataFrame({"parameter": res.param_names, "estimate": res.params, "std_err": res.bse, "p_value": res.pvalues})
    summary = {
        "model": f"{order}{(*sorder, s)}", "observations": len(y), "residuals_used": len(resid),
        "residual_mean": float(np.mean(resid)), "residual_sd": float(np.std(resid, ddof=1)),
        "mean_as_%_of_level": float(np.mean(resid) / y.mean() * 100),
        "ljung_box_lag10_p": float(lb.loc[10, "lb_pvalue"]), "ljung_box_lag20_p": float(lb.loc[20, "lb_pvalue"]),
        "converged": bool(res.mle_retvals.get("converged", False)), "AIC": float(res.aic), "BIC": float(res.bic),
    }
    return summary, params, resid


# ----------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--workers", type=int, default=1)
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)
    t0 = time.time()
    log = lambda m: print(f"[{time.time() - t0:6.0f}s] {m}", flush=True)

    it = load(args.data)
    q, big = quality(it, args.data)
    monthly, weekly, excluded = series(it)
    log(f"monthly {len(monthly)} complete ({monthly.index[0].date()}..{monthly.index[-1].date()}), "
        f"weekly {len(weekly)} complete ({weekly.index[0].date()}..{weekly.index[-1].date()}); excluded {excluded}")
    pd.to_pickle({"monthly": monthly, "weekly": weekly, "excluded": excluded}, os.path.join(args.out, "_series.pkl"))

    # Holdout, validation-selected (cached: the weekly grid is the slow part)
    cache = os.path.join(args.out, "_holdout.pkl")
    if os.path.exists(cache):
        hm, cm, trm, tem, hw, cw, trw, tew, hm3, cm3 = pd.read_pickle(cache)
        log("holdout results loaded from cache")
    else:
        hm, cm, trm, tem = holdout("M", monthly, args.workers)
        hw, cw, trw, tew = holdout("W", weekly, args.workers)
        # Same calendar horizon as 12 weeks: monthly, 3 months ahead
        hm3, cm3, _, _ = holdout("M", monthly, args.workers, h=3)
        pd.to_pickle((hm, cm, trm, tem, hw, cw, trw, tew, hm3, cm3), cache)
    log(f"monthly holdout chosen {cm.SARIMA} / {cm.history}: test MAPE {cm.test_MAPE:.2f}")
    log(f"weekly holdout chosen {cw.SARIMA} / {cw.history}: test MAPE {cw.test_MAPE:.2f}")
    log(f"monthly 3-month holdout chosen {cm3.SARIMA}: test MAPE {cm3.test_MAPE:.2f}")

    # Walk-forward
    def top_configs(df, n=3):
        e = df[df._order.notna() & df.fit_ok & df.converged & ~df.underdetermined & df.val_MAPE.notna()]
        e = e[e._window == e._window.max()] if False else e
        return [(tuple(r["_order"]), tuple(r["_sorder"])) for _, r in e.sort_values("val_MAPE").head(n).iterrows()]

    w_cfgs = list(dict.fromkeys(top_configs(hw[hw.history.astype(str) == "104"]) + [((1, 1, 1), (0, 1, 1)), ((1, 1, 1), (1, 1, 1)), ((0, 1, 1), (1, 1, 1))]))
    wf_w = walk_forward("W", weekly, w_cfgs, 104, 12, 12, args.workers)
    log(f"weekly walk-forward: {wf_w.test_from.nunique()} folds x {len(w_cfgs)} SARIMA configs")
    m_cfgs = list(dict.fromkeys(top_configs(hm) + [((1, 1, 1), (0, 1, 1))]))
    wf_m3 = walk_forward("M", monthly, m_cfgs, 36, 3, 3, args.workers)
    log(f"monthly walk-forward (3-month horizon): {wf_m3.test_from.nunique()} folds")
    wf = pd.concat([wf_w, wf_m3], ignore_index=True)
    wfs = summarise_wf(wf)

    # Per product
    pp, elig = per_product(it, weekly.index, args.workers)
    log(f"per-product: {len(pp)} products modelled of {int(elig.eligible.sum())} eligible")

    # Diagnostics on the walk-forward winner (weekly SARIMA), fitted on the 104 weeks before the test block
    wf_sarima = wfs[(wfs.frequency == "Weekly") & wfs.model.str.startswith("(")]
    best_label = wf_sarima.iloc[0].model
    best = next(c for c in w_cfgs if f"{c[0]}{(*c[1], 52)}" == best_label)
    diag, params, resid = diagnostics(trw.iloc[-104:], best[0], best[1], 52)
    log(f"diagnostics for {best_label}: {json.dumps(diag)}")

    pd.to_pickle({"hm": hm, "cm": cm, "hw": hw, "cw": cw, "hm3": hm3, "cm3": cm3, "trm": trm, "tem": tem,
                  "trw": trw, "tew": tew, "wf": wf, "wfs": wfs, "pp": pp, "elig": elig, "diag": diag,
                  "params": params, "resid": resid, "best": best, "q": q, "big": big, "excluded": excluded},
                 os.path.join(args.out, "_results.pkl"))
    log("done")


if __name__ == "__main__":
    main()
