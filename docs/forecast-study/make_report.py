#!/usr/bin/env python3
"""
Turn sarima_study.py's results into the Excel workbook and the charts.

    python make_report.py --out output
"""

import argparse
import os

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
from statsmodels.graphics.tsaplots import plot_acf, plot_pacf
from statsmodels.tsa.seasonal import STL
from statsmodels.tsa.stattools import acf

GREEN, BLUE, ORANGE, GREY, RED = "#0f6e56", "#2a78d6", "#eb6834", "#898781", "#d03b3b"
plt.rcParams.update({"figure.dpi": 130, "axes.spines.top": False, "axes.spines.right": False,
                     "axes.grid": True, "grid.color": "#e1e0d9", "font.size": 9})


def save(fig, out, name):
    path = os.path.join(out, "charts", name)
    fig.tight_layout()
    fig.savefig(path)
    plt.close(fig)
    return path


def public(df):
    return df[[c for c in df.columns if not c.startswith("_")]]


def why_weekly(monthly, weekly):
    rows = []
    for name, y, s in (("Monthly", monthly, 12), ("Weekly", weekly, 52)):
        r = acf(y.diff().dropna(), nlags=s, fft=False)
        recent = y.iloc[-24:] if name == "Monthly" else y.iloc[-104:]
        rows.append({
            "series": name, "complete periods": len(y), "periods in the last 2 years": len(recent),
            "mean demand per period (last 2 yrs)": recent.mean(),
            "coefficient of variation (last 2 yrs)": recent.std() / recent.mean(),
            "mean |period-on-period change| % (last 2 yrs)": (recent.pct_change().abs().mean() * 100),
            "ACF of first difference, lag 1": r[1], f"ACF of first difference, seasonal lag": r[s],
            "periods with zero demand": int((y == 0).sum()),
        })
    return pd.DataFrame(rows)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    out = args.out
    os.makedirs(os.path.join(out, "charts"), exist_ok=True)
    S = pd.read_pickle(os.path.join(out, "_series.pkl"))
    R = pd.read_pickle(os.path.join(out, "_results.pkl"))
    monthly, weekly = S["monthly"], S["weekly"]

    # ---- 1-2 series
    fig, ax = plt.subplots(figsize=(9, 3.2))
    ax.plot(monthly.index, monthly.values, color=GREEN, marker="o", ms=3)
    ax.set_title("Monthly units sold (complete months, Jun 2022 - Jul 2026)")
    ax.set_ylabel("units")
    save(fig, out, "01_monthly_series.png")
    fig, ax = plt.subplots(figsize=(9, 3.2))
    ax.plot(weekly.index, weekly.values, color=BLUE, lw=1.2)
    ax.set_title("Weekly units sold (complete Sunday-ending weeks, Jun 2022 - Aug 16 2026)")
    ax.set_ylabel("units")
    save(fig, out, "02_weekly_series.png")

    # ---- 3 decomposition
    for name, y, p in (("weekly", weekly, 52), ("monthly", monthly, 12)):
        res = STL(y, period=p, robust=True).fit()
        fig, axes = plt.subplots(4, 1, figsize=(9, 6.5), sharex=True)
        for a, (lab, v) in zip(axes, (("observed", y), ("trend", res.trend), ("seasonal", res.seasonal), ("remainder", res.resid))):
            a.plot(v.index, v.values, color=BLUE if lab != "remainder" else GREY, lw=1)
            a.set_ylabel(lab)
        axes[0].set_title(f"STL decomposition of {name} units (period {p})")
        save(fig, out, f"03_decomposition_{name}.png")

    # ---- 4 ACF / PACF
    for name, y, s in (("weekly", weekly, 52), ("monthly", monthly, 12)):
        d1 = y.diff().dropna()
        fig, axes = plt.subplots(1, 2, figsize=(9, 3))
        lags = min(s + 4, len(d1) // 2 - 1)
        plot_acf(d1, lags=lags, ax=axes[0], color=BLUE, vlines_kwargs={"colors": BLUE})
        plot_pacf(d1, lags=lags, ax=axes[1], method="ywm", color=BLUE, vlines_kwargs={"colors": BLUE})
        axes[0].set_title(f"ACF, first difference of {name} units")
        axes[1].set_title(f"PACF, first difference of {name} units")
        save(fig, out, f"04_acf_pacf_{name}.png")

    # ---- 5-6 actual vs predicted on the test block, and errors
    for key, train, test, chosen, label, s in (("hw", R["trw"], R["tew"], R["cw"], "weekly", 52),
                                                ("hm", R["trm"], R["tem"], R["cm"], "monthly", 12)):
        df = R[key]
        sn = df[df.SARIMA == "Seasonal naive (t - s)"].iloc[0]
        nv = df[df.SARIMA == "Naive (last period)"].iloc[0]
        fig, ax = plt.subplots(figsize=(9, 3.4))
        tail = train.iloc[-(3 * len(test)):]
        ax.plot(tail.index, tail.values, color=GREY, lw=1, label="training data")
        ax.plot(test.index, test.values, color=GREEN, lw=2, marker="o", ms=3, label="actual (test)")
        ax.plot(test.index, chosen._forecast, color=BLUE, lw=2, ls="--",
                label=f"SARIMA {chosen.SARIMA}, {chosen.history} (MAPE {chosen.test_MAPE:.1f}%)")
        ax.plot(test.index, sn._forecast, color=ORANGE, lw=1.2, ls=":", label=f"seasonal naive (MAPE {sn.test_MAPE:.1f}%)")
        ax.plot(test.index, nv._forecast, color=RED, lw=1, ls="-.", label=f"naive (MAPE {nv.test_MAPE:.1f}%)")
        ax.set_title(f"{label.title()} holdout: last {len(test)} periods, configuration chosen on validation")
        ax.legend(fontsize=7, loc="upper left")
        save(fig, out, f"05_actual_vs_predicted_{label}.png")
        fig, ax = plt.subplots(figsize=(9, 2.8))
        err = np.asarray(chosen._forecast) - test.values
        ax.bar(test.index, err / test.values * 100, width=5 if label == "weekly" else 20,
               color=[BLUE if e >= 0 else ORANGE for e in err])
        ax.axhline(0, color="#444")
        ax.set_title(f"{label.title()} forecast error by period, % of actual (positive = over-forecast)")
        ax.set_ylabel("% error")
        save(fig, out, f"06_forecast_error_{label}.png")

    # ---- 7 MAPE by model (test MAPE of the best-on-validation per config family, well-determined only)
    for key, label in (("hw", "weekly"), ("hm", "monthly")):
        df = R[key]
        ok = df[df.fit_ok & ~df.underdetermined]
        sar = ok[ok._order.notna()].copy()
        sar["label"] = sar.SARIMA + " / " + sar.history.astype(str)
        top = sar.sort_values("val_MAPE").head(10)
        base = ok[ok._order.isna()].copy()
        base["label"] = base.SARIMA
        show = pd.concat([top, base])
        fig, ax = plt.subplots(figsize=(9, 0.32 * len(show) + 1.2))
        colors = [BLUE if o is not None else ORANGE for o in show._order]
        ax.barh(show.label, show.test_MAPE, color=colors)
        for i, (v, vv) in enumerate(zip(show.test_MAPE, show.val_MAPE)):
            ax.text(v, i, f"  test {v:.1f}% | val {vv:.1f}%", va="center", fontsize=7)
        ax.invert_yaxis()
        ax.set_xlabel("test MAPE %")
        ax.set_title(f"{label.title()}: 10 best SARIMA configurations by VALIDATION MAPE, with their test MAPE; baselines in orange")
        save(fig, out, f"07_mape_by_model_{label}.png")

    # ---- 8 MAPE by window
    fig, axes = plt.subplots(1, 2, figsize=(9, 3))
    for a, (key, label) in zip(axes, (("hw", "Weekly"), ("hm", "Monthly"))):
        df = R[key]
        sar = df[df._order.notna() & df.fit_ok & ~df.underdetermined]
        best = sar.sort_values("val_MAPE").groupby("history", sort=False).head(1)
        allw = df[df._order.notna()].history.astype(str).unique()
        x = [str(w) for w in allw]
        y = [best[best.history.astype(str) == w].test_MAPE.iloc[0] if (best.history.astype(str) == w).any() else np.nan for w in x]
        a.bar(x, [0 if np.isnan(v) else v for v in y], color=BLUE)
        for i, v in enumerate(y):
            a.text(i, (0 if np.isnan(v) else v), "not feasible" if np.isnan(v) else f"{v:.1f}%", ha="center", va="bottom", fontsize=7)
        a.set_title(f"{label}: best-on-validation SARIMA per history window")
        a.set_ylabel("test MAPE %")
    save(fig, out, "08_mape_by_window.png")

    # ---- 9 walk-forward
    wf = R["wf"]
    for freq in ("Weekly", "Monthly"):
        w = wf[wf.frequency == freq]
        fig, ax = plt.subplots(figsize=(9, 3.2))
        for model, g in w.groupby("model"):
            is_base = not model.startswith("(")
            ax.plot(g.test_from, g.MAPE, marker="o", ms=3, lw=1 if is_base else 1.6,
                    ls=":" if is_base else "-", label=model)
        ax.set_title(f"{freq} walk-forward: MAPE per fold")
        ax.set_ylabel("MAPE %")
        ax.tick_params(axis="x", rotation=45)
        ax.legend(fontsize=6, ncol=2)
        save(fig, out, f"09_walk_forward_{freq.lower()}.png")

    # ---- 10 residual diagnostics
    resid = np.asarray(R["resid"])
    fig, axes = plt.subplots(1, 2, figsize=(9, 3))
    axes[0].plot(resid, color=BLUE, lw=1)
    axes[0].axhline(0, color="#444")
    axes[0].set_title(f"Residuals, {R['diag']['model']}")
    plot_acf(resid, lags=min(30, len(resid) // 2 - 1), ax=axes[1], color=BLUE, vlines_kwargs={"colors": BLUE})
    axes[1].set_title("ACF of residuals")
    save(fig, out, "10_residual_diagnostics.png")

    # ---- 11 per product
    pp = R["pp"]
    if len(pp):
        p = pp.sort_values("total_units", ascending=False).head(20)
        fig, ax = plt.subplots(figsize=(9, 5.5))
        yv = np.arange(len(p))
        ax.barh(yv - 0.2, p.test_MAPE, height=0.4, color=BLUE, label="SARIMA (chosen on validation)")
        ax.barh(yv + 0.2, p.mean3_test_MAPE, height=0.4, color=ORANGE, label="mean of last 3 weeks")
        ax.set_yticks(yv, [n[:34] for n in p["Product Name"]], fontsize=7)
        ax.invert_yaxis()
        ax.set_xlabel("test MAPE %")
        ax.set_title("Per-product weekly test MAPE, top 20 by volume")
        ax.legend(fontsize=7)
        save(fig, out, "11_per_product_mape.png")

    ww = why_weekly(monthly, weekly)

    # ---- Excel
    xl = os.path.join(out, "sarima_study_results.xlsx")
    with pd.ExcelWriter(xl, engine="openpyxl") as w:
        R["q"].to_excel(w, sheet_name="data_quality", index=False)
        pd.DataFrame({"frequency": ["Monthly", "Weekly"],
                      "first complete period": [monthly.index[0].date(), weekly.index[0].date()],
                      "last complete period": [monthly.index[-1].date(), weekly.index[-1].date()],
                      "complete periods": [len(monthly), len(weekly)],
                      "excluded (incomplete)": [", ".join(S["excluded"]["monthly_excluded"]), ", ".join(S["excluded"]["weekly_excluded"])],
                      "reason": ["Aug 2026 holds only Aug 1-16", "week ending 2022-06-05 holds only Wed Jun 1 - Sun Jun 5"]}
                     ).to_excel(w, sheet_name="period_handling", index=False)
        monthly.rename("units").to_frame().to_excel(w, sheet_name="monthly_series")
        weekly.rename("units").to_frame().to_excel(w, sheet_name="weekly_series")
        public(R["hm"]).sort_values("val_MAPE").to_excel(w, sheet_name="holdout_monthly_12m", index=False)
        public(R["hm3"]).sort_values("val_MAPE").to_excel(w, sheet_name="holdout_monthly_3m", index=False)
        public(R["hw"]).sort_values("val_MAPE").to_excel(w, sheet_name="holdout_weekly_12w", index=False)
        R["wfs"].to_excel(w, sheet_name="walk_forward_summary", index=False)
        R["wf"].to_excel(w, sheet_name="walk_forward_folds", index=False)
        pp.to_excel(w, sheet_name="per_product", index=False)
        R["elig"].sort_values("total_units", ascending=False).to_excel(w, sheet_name="product_eligibility", index=False)
        pd.DataFrame([R["diag"]]).to_excel(w, sheet_name="diagnostics", index=False)
        R["params"].to_excel(w, sheet_name="diagnostic_parameters", index=False)
        ww.to_excel(w, sheet_name="why_weekly", index=False)
        R["big"].drop(columns=["dt"]).to_excel(w, sheet_name="lines_qty_50_plus", index=False)
    for name, df in (("holdout_weekly_12w", public(R["hw"])), ("holdout_monthly_12m", public(R["hm"])),
                     ("walk_forward_summary", R["wfs"]), ("per_product", pp)):
        df.to_csv(os.path.join(out, f"{name}.csv"), index=False)
    print("wrote", xl)


if __name__ == "__main__":
    main()
