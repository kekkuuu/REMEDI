#!/usr/bin/env python3
"""
Monthly SARIMA on Jul 2022 - Jul 2026 (the user's chosen range), monthly data only.

    python monthly_sarima_full.py --data <folder with the 3 CSVs> --out <output folder>

1. Walk-forward, one month ahead: every month from the first one with 24 months of history before it
   is forecast from the months before it only, then compared with what sold.
2. The 12-month test (Aug 2025 - Jul 2026, trained on Jul 2022 - Jul 2025) for comparison.
3. The final model fitted on all of Jul 2022 - Jul 2026, its diagnostics, and its forecast for
   Aug 2026 - Jul 2027 with an 80% interval.
Nothing in the data is changed. MAPE uses months with actual > 0 (all of them here).
"""

import argparse
import os
import warnings

import numpy as np
import pandas as pd

warnings.filterwarnings("ignore")

START, END = "2022-07-01", "2026-07-01"
CONFIGS = [((1, 1, 1), (0, 1, 1, 12)), ((0, 1, 1), (0, 1, 1, 12))]
MIN_TRAIN = 24


def lab(c):
    return f"SARIMA{c[0]}{c[1]}".replace(" ", "")


def metrics(a, f):
    a, f = np.asarray(a, float), np.asarray(f, float)
    e = f - a
    nz = a > 0
    return {"MAPE": np.mean(np.abs(e[nz] / a[nz])) * 100, "WAPE": np.abs(e).sum() / a.sum() * 100,
            "MAE": np.mean(np.abs(e)), "RMSE": np.sqrt(np.mean(e ** 2)), "months": len(a)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)
    from statsmodels.stats.diagnostic import acorr_ljungbox
    from statsmodels.tsa.statespace.sarimax import SARIMAX

    it = pd.read_csv(os.path.join(args.data, "transaction_items_2022_2026.csv"), usecols=["Date / Time", "Quantity"])
    it["dt"] = pd.to_datetime(it["Date / Time"], format="%m/%d/%Y %I:%M:%S %p")
    month = it.dt.dt.to_period("M").dt.to_timestamp()
    y = it.groupby(month).Quantity.sum().astype(float).loc[START:END]   # calendar months only
    y.index.freq = "MS"
    n = len(y)
    print(f"{n} months {y.index[0]:%Y-%m} .. {y.index[-1]:%Y-%m} (Aug 2026 excluded: 16 of 31 days)")

    fit = lambda v, c: SARIMAX(np.asarray(v, float), order=c[0], seasonal_order=c[1]).fit(disp=False, maxiter=300)

    # 1. walk-forward, one month ahead
    rows = []
    for t in range(MIN_TRAIN, n):
        row = {"month": y.index[t].strftime("%Y-%m"), "train": f"{y.index[0]:%Y-%m}..{y.index[t - 1]:%Y-%m}",
               "actual": int(y.iloc[t])}
        for c in CONFIGS:
            row[lab(c)] = round(float(fit(y.iloc[:t], c).forecast(1)[0]))
        row["Naive (last month)"] = int(y.iloc[t - 1])
        row["Seasonal naive"] = int(y.iloc[t - 12])
        rows.append(row)
    wf = pd.DataFrame(rows)
    models = [lab(c) for c in CONFIGS] + ["Naive (last month)", "Seasonal naive"]
    for mdl in models:
        wf[f"{mdl} APE %"] = ((wf[mdl] - wf.actual).abs() / wf.actual * 100).round(2)
    wf_sum = pd.DataFrame([{"model": mdl, **metrics(wf.actual, wf[mdl]),
                            "median_APE": wf[f"{mdl} APE %"].median(),
                            **{f"MAPE {yr}": wf[wf.month.str[:4] == yr][f"{mdl} APE %"].mean() for yr in sorted(wf.month.str[:4].unique())}}
                           for mdl in models]).round(2)

    # 2. 12-month test
    tr, te = y.iloc[:-12], y.iloc[-12:]
    h12 = pd.DataFrame({"month": te.index.strftime("%Y-%m"), "actual": te.astype(int).values})
    h_rows = []
    for c in CONFIGS:
        f = np.asarray(fit(tr, c).forecast(12))
        h12[lab(c)] = f.round().astype(int)
        h_rows.append({"model": lab(c), **metrics(te, f)})
    for name, f in (("Naive (last month)", np.repeat(tr.iloc[-1], 12)), ("Seasonal naive", tr.iloc[-12:].to_numpy())):
        h12[name] = np.round(f).astype(int)
        h_rows.append({"model": name, **metrics(te, f)})
    h_sum = pd.DataFrame(h_rows).round(2)

    # 3. final model on all months
    final = []
    fc_tbl = pd.DataFrame({"month": pd.date_range(y.index[-1] + pd.offsets.MonthBegin(1), periods=12, freq="MS").strftime("%Y-%m")})
    for c in CONFIGS:
        r = fit(y, c)
        p = r.get_forecast(12)
        ci = np.asarray(p.conf_int(alpha=0.2))
        fc_tbl[lab(c)] = np.round(p.predicted_mean).astype(int)
        fc_tbl[f"{lab(c)} lower 80%"] = np.round(np.maximum(ci[:, 0], 0)).astype(int)
        fc_tbl[f"{lab(c)} upper 80%"] = np.round(ci[:, 1]).astype(int)
        resid = r.resid[1 + 12:]
        lb = acorr_ljungbox(resid, lags=[6, 12], return_df=True).lb_pvalue
        final.append({"model": lab(c), "months fitted": n, "converged": bool(r.mle_retvals.get("converged")),
                      "AIC": r.aic, "BIC": r.bic, "residual mean": float(np.mean(resid)),
                      "Ljung-Box p lag 6": float(lb.iloc[0]), "Ljung-Box p lag 12": float(lb.iloc[1]),
                      **{f"param {k}": float(v) for k, v in zip(r.param_names, r.params)}})
    final = pd.DataFrame(final).round(4)

    # chart
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    fig, ax = plt.subplots(figsize=(11, 4.2), dpi=130)
    ax.plot(y.index, y.values, color="#0f6e56", marker="o", ms=3, label="actual")
    idx = pd.to_datetime(wf.month)
    c0 = lab(CONFIGS[0])
    ax.plot(idx, wf[c0], color="#185FA5", ls="--", marker="o", ms=3,
            label=f"{c0}, one month ahead: MAPE {wf_sum.set_index('model').loc[c0, 'MAPE']:.1f}%")
    fi = pd.to_datetime(fc_tbl.month)
    ax.plot(fi, fc_tbl[c0], color="#c2410c", ls="--", marker="o", ms=3, label=f"{c0} forecast Aug 2026 - Jul 2027")
    ax.fill_between(fi, fc_tbl[f"{c0} lower 80%"], fc_tbl[f"{c0} upper 80%"], color="#c2410c", alpha=0.12, label="80% interval")
    ax.set_title("Monthly SARIMA on Jul 2022 - Jul 2026: one-month-ahead walk-forward and the next 12 months")
    ax.set_ylabel("units")
    ax.spines[["top", "right"]].set_visible(False)
    ax.legend(frameon=False, fontsize=8, loc="upper left")
    fig.tight_layout()
    fig.savefig(os.path.join(args.out, "monthly_sarima_jul2022_jul2026.png"))

    xlsx = os.path.join(args.out, "Monthly_SARIMA_Jul2022_Jul2026.xlsx")
    with pd.ExcelWriter(xlsx, engine="openpyxl") as xw:
        pd.DataFrame({"month": y.index.strftime("%Y-%m"), "units": y.astype(int).values}).to_excel(xw, sheet_name="Monthly Demand", index=False)
        wf_sum.to_excel(xw, sheet_name="Walk-forward summary", index=False)
        wf.to_excel(xw, sheet_name="Walk-forward months", index=False)
        h_sum.to_excel(xw, sheet_name="12-month test", index=False)
        h12.to_excel(xw, sheet_name="12-month test", index=False, startrow=len(h_sum) + 3)
        final.to_excel(xw, sheet_name="Final model", index=False)
        fc_tbl.to_excel(xw, sheet_name="Forecast Aug26-Jul27", index=False)
        for sh in xw.sheets.values():
            for col in sh.columns:
                sh.column_dimensions[col[0].column_letter].width = min(40, max(10, max(len(str(c.value or "")) for c in col) + 2))
    pd.set_option("display.width", 250)
    print(wf_sum.to_string())
    print(h_sum.to_string())
    print(final.to_string())
    print(fc_tbl.to_string())
    print(wf[["month", "actual", c0, f"{c0} APE %", "Naive (last month)", "Naive (last month) APE %"]].to_string())
    print("wrote", xlsx)


if __name__ == "__main__":
    main()
