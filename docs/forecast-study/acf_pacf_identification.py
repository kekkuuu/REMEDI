"""
REMEDI -- choosing the SARIMA parameters from ACF and PACF.

What it does
------------
1. d and D (differencing). Each series is tested for a unit root:
       ADF   null = "not stationary"   -> stationary when p < 0.05
       KPSS  null = "stationary"       -> stationary when p > 0.05
   d is the fewest first differences that make both tests agree on
   "stationary". D (seasonal differencing at lag 12) is 1 only when the
   seasonal strength from an STL decomposition is above 0.64 -- the usual
   rule (Wang, Smith & Hyndman), since a weak yearly pattern is better
   modelled by a seasonal AR/MA term than by differencing it away.
2. p, q, P, Q from the correlograms of the differenced series, with the
   95% band +/-1.96/sqrt(n):
       PACF cuts off after lag p        -> AR order p
       ACF  cuts off after lag q        -> MA order q
       PACF spike at lag 12             -> seasonal AR, P = 1
       ACF  spike at lag 12             -> seasonal MA, Q = 1
   Counting significant lags is not enough on its own: the PACF at lag 1 always
   equals the ACF at lag 1, so the SHAPE decides (shape_rule) -- the plot whose
   next lags still carry weight is the one tailing off:
       ACF cuts off, PACF tails off  -> MA (p = 0)
       PACF cuts off, ACF tails off  -> AR (q = 0)
       both tail off                 -> ARMA
   The catalogue's order is read from the AVERAGE correlogram over every
   product, which is far less noisy than any one product's 4 years of months.
3. Done for the WHOLE STORE (one series, plotted, then confirmed by AIC and a
   Ljung-Box test of the residuals) and for EVERY PRODUCT (the model the app
   fits is per product, on log(1 + units)), with the per-product readings
   counted to find the order most products point to.
4. Choice by test -- no model is assumed. The candidates are the ACF/PACF
   reading and its simpler (or SARIMA) variants; each is scored on the same 3
   hidden months per product with the app's own fitting code
   (resources/python/generate_forecasts.py), and the most accurate is CHOSEN.
   Only then does the script say whether the app's code holds that model.

How to run
----------
    python docs/forecast-study/acf_pacf_identification.py
        reads the newest storage/app/exports/sales_history_*.csv
    python docs/forecast-study/acf_pacf_identification.py --csv <file>
    python docs/forecast-study/acf_pacf_identification.py --mysql
Needs: pandas, numpy, statsmodels, matplotlib.
Writes: docs/forecast-study/report/acf_pacf/ (charts, per-product CSV, summary JSON).
"""
import argparse
import glob
import json
import os
import sys
import time
import warnings
from collections import Counter
from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(REPO, "resources", "python"))
import generate_forecasts as gf  # noqa: E402  -- the app's own model and fitting code

OUT = os.path.join(HERE, "report", "acf_pacf")
LINE = "=" * 78
S = 12                      # seasonal period (months)
MAX_PQ = 2                  # largest p / q read off the plots
CURRENT = tuple(gf.SARIMA_CANDIDATES[0])  # the model the app actually uses
HOLDOUT = gf.HOLDOUT_MONTHS
MIN_MONTHS_FOR_READING = 27  # PACF to lag 12 needs n // 2 - 1 >= 12 after one difference


# --------------------------------------------------------------------------- data
def load_daily(args) -> pd.DataFrame:
    if args.mysql:
        return gf.load_from_mysql(os.path.join(REPO, ".env"))
    path = args.csv
    if not path:
        found = sorted(glob.glob(os.path.join(REPO, "storage", "app", "exports", "sales_history_*.csv")), key=os.path.getmtime)
        if not found:
            sys.exit("No CSV given and none in storage/app/exports. Use --csv <file> or --mysql.")
        path = found[-1]
    print(f"      source: {path}")
    df = pd.read_csv(path, dtype={"product_sku": str}, usecols=["product_sku", "sale_date", "quantity_sold"])
    return pd.DataFrame({"date": pd.to_datetime(df["sale_date"]), "product_sku": df["product_sku"],
                         "qty": df["quantity_sold"].astype(float)})


def product_series(daily):
    monthly = gf.monthly_series(daily)
    end = monthly["month"].max()
    out = {}
    for sku, g in monthly.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        out[str(sku)] = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0).astype(float)
    return out


# ------------------------------------------------------------------ identification
def stationary(y):
    """(adf_p, kpss_p, both agree it is stationary)."""
    from statsmodels.tsa.stattools import adfuller, kpss
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        adf_p = adfuller(y, autolag="AIC")[1]
        kpss_p = kpss(y, regression="c", nlags="auto")[1]
    return float(adf_p), float(kpss_p), adf_p < 0.05 and kpss_p > 0.05


def choose_d(y, max_d=2):
    tests = []
    for d in range(max_d + 1):
        w = np.diff(y, n=d) if d else y
        if len(w) < 10 or np.std(w) == 0:
            break
        a, k, ok = stationary(w)
        tests.append({"d": d, "adf_p": round(a, 4), "kpss_p": round(k, 4), "stationary": bool(ok)})
        if ok:
            return d, tests
    return min(1, max_d), tests  # never settled: one difference, the usual for sales


def seasonal_strength(y):
    """STL seasonal strength F_s in [0, 1]; None when under two full years."""
    if len(y) < 2 * S + 1:
        return None
    from statsmodels.tsa.seasonal import STL
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        r = STL(pd.Series(y, index=pd.date_range("2000-01-01", periods=len(y), freq="MS")), period=S, robust=True).fit()
    rem, sea = np.asarray(r.resid), np.asarray(r.seasonal)
    v = np.var(sea + rem)
    return float(max(0.0, 1 - np.var(rem) / v)) if v > 0 else 0.0


def correlograms(w, nlags):
    from statsmodels.tsa.stattools import acf, pacf
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        r = acf(w, nlags=min(nlags, len(w) - 1), fft=False)
        phi = pacf(w, nlags=min(nlags, len(w) // 2 - 1), method="ywm")
    return r, phi, 1.96 / np.sqrt(len(w))


def cutoff(values, band, max_lag):
    n = 0
    for lag in range(1, max_lag + 1):
        if lag >= len(values) or abs(values[lag]) <= band:
            break
        n += 1
    return n


TAIL_LAGS = 3      # lags just past a cut-off, inspected to tell "cuts off" from "tails off"
TAIL_RATIO = 1.5   # one plot's tail must be this much heavier than the other's to call it


def tail_mass(values, k):
    """Mean |value| over the TAIL_LAGS lags right after lag k."""
    vals = [abs(values[lag]) for lag in range(k + 1, k + 1 + TAIL_LAGS) if lag < len(values)]
    return float(np.mean(vals)) if vals else 0.0


def shape_rule(r, phi, band):
    """
    Which plot CUTS OFF and which TAILS OFF -> (p, q, pattern).

    Counting the significant lags alone is not enough: the PACF at lag 1 always
    equals the ACF at lag 1 (nothing lies between them to remove), so a
    significant lag-1 ACF spike always shows up in the PACF too and a plain
    count reads every MA(1) as ARMA(1,1). After the cut-offs, the plot whose
    next few lags still carry weight is the one tailing off:
        ACF cuts off after q, PACF tails off  -> MA(q):     p = 0
        PACF cuts off after p, ACF tails off  -> AR(p):     q = 0
        both tail off about equally           -> ARMA(p, q)
    """
    kp, kq = cutoff(phi, band, MAX_PQ), cutoff(r, band, MAX_PQ)
    if not kp and not kq:
        return 0, 0, "white noise", kp, kq
    if kq and not kp:
        return 0, kq, "MA", kp, kq
    if kp and not kq:
        return kp, 0, "AR", kp, kq
    ta, tp = tail_mass(r, kq), tail_mass(phi, kp)
    if tp >= TAIL_RATIO * ta:
        return 0, kq, "MA", kp, kq
    if ta >= TAIL_RATIO * tp:
        return kp, 0, "AR", kp, kq
    return kp, kq, "ARMA", kp, kq


def identify(y_units, log=True):
    """Full ACF/PACF reading of one monthly series -> dict with the suggested order."""
    y = np.log1p(np.clip(y_units, 0, None)) if log else np.asarray(y_units, float)
    d, tests = choose_d(y)
    fs = seasonal_strength(y)
    D = 1 if (fs is not None and fs > 0.64) else 0
    w = np.diff(y, n=d) if d else y
    if D:
        w = w[S:] - w[:-S]
    if len(w) < 4 or np.std(w) == 0:
        return None
    r, phi, band = correlograms(w, 2 * S)
    p, q, pattern, kp, kq = shape_rule(r, phi, band)
    acf12 = len(r) > S and abs(r[S]) > band
    pacf12 = len(phi) > S and abs(phi[S]) > band
    P, Q = int(pacf12), int(acf12)
    return {"d": d, "D": D, "p": p, "q": q, "P": P, "Q": Q, "pattern": pattern, "count_p": kp, "count_q": kq,
            "seasonal_strength": None if fs is None else round(fs, 3),
            "acf12": bool(acf12), "pacf12": bool(pacf12), "lag12_measured": bool(len(phi) > S),
            "order": (p, d, q), "seasonal_order": (P, D, Q, S if (P or D or Q) else 0),
            "acf": r, "pacf": phi, "band": band, "tests": tests}


SHAPES = {
    "MA": "ACF cuts off, PACF tails off (MA)",
    "AR": "PACF cuts off, ACF tails off (AR)",
    "ARMA": "both tail off (ARMA)",
    "white noise": "no significant lag (white noise)",
}


def label(order, seasonal):
    return f"({order[0]},{order[1]},{order[2]})({seasonal[0]},{seasonal[1]},{seasonal[2]},{seasonal[3]})"


# ----------------------------------------------------------------------- charts
def plot_pair(ax_acf, ax_pacf, r, phi, band, title):
    for ax, vals, name in ((ax_acf, r, "ACF"), (ax_pacf, phi, "PACF")):
        lags = np.arange(1, len(vals))
        ax.axhspan(-band, band, color="#93c5fd", alpha=0.35, lw=0)
        ax.axhline(0, color="#374151", lw=0.8)
        colours = ["#dc2626" if abs(v) > band else "#2563eb" for v in vals[1:]]
        ax.vlines(lags, 0, vals[1:], colors=colours, lw=2)
        ax.scatter(lags, vals[1:], c=colours, s=14, zorder=3)
        for k in (12, 24):
            if k < len(vals):
                ax.axvline(k, color="#9ca3af", ls=":", lw=0.9)
        ax.set_ylim(-1, 1)
        ax.set_xlim(0.3, len(vals) - 0.5)
        ax.set_title(f"{name} — {title}", fontsize=10.5, loc="left")
        ax.set_xlabel("lag (months)", fontsize=9)
        ax.tick_params(labelsize=8.5)
        ax.grid(axis="y", color="#e5e7eb", lw=0.6)


# ------------------------------------------------------------------- validation
def score(train, actual, candidates):
    rows, _ = gf.forecast_product_explained(train, len(actual), candidates=candidates)
    if not rows:
        return None
    f = np.array([float(r["forecast_value"]) for r in rows[:len(actual)]])
    a = actual.to_numpy(float)[:len(f)]
    e = f - a
    nz = a != 0
    return {"mae": float(np.mean(np.abs(e))), "rmse": float(np.sqrt(np.mean(e ** 2))),
            "mape": float(np.mean(np.abs(e[nz] / a[nz])) * 100) if nz.any() else None,
            "abs_error": float(np.abs(e).sum()), "actual": float(a.sum())}


def validate_task(task):
    warnings.filterwarnings("ignore")
    sku, values, start, candidates = task
    s = pd.Series(values, index=pd.date_range(start, periods=len(values), freq="MS"))
    out = {"sku": sku}
    for h in range(min(HOLDOUT, len(s) - gf.MIN_MONTHS_FOR_ANY_FORECAST), 0, -1):
        train, actual = s.iloc[:-h], s.iloc[-h:]
        if len(train) < gf.MIN_MONTHS_FOR_SARIMA:
            continue
        # The first candidate decides whether this product can be scored at this
        # holdout length; every candidate is then scored on the same months.
        first = score(train, actual, [candidates[0][2]])
        if first is None:
            continue
        out[candidates[0][0]] = first
        for key, _, order in candidates[1:]:
            out[key] = score(train, actual, [order])
        # The product's OWN reading, taken on the training months only (no peeking).
        own = identify(train.to_numpy(float)) if len(train) >= MIN_MONTHS_FOR_READING else None
        if own:
            o = (own["order"], own["seasonal_order"])
            out["own_order"] = label(*o)
            out["own"] = score(train, actual, [o])
        return out
    return None


def summarise(results, key):
    rows = [r[key] for r in results if r.get(key)]
    if not rows:
        return None
    mapes = [x["mape"] for x in rows if x["mape"] is not None]
    return {"products": len(rows), "mae": float(np.mean([x["mae"] for x in rows])),
            "rmse": float(np.mean([x["rmse"] for x in rows])), "mape": float(np.mean(mapes)),
            "median_mape": float(np.median(mapes)),
            "wape": float(sum(x["abs_error"] for x in rows) / sum(x["actual"] for x in rows) * 100)}


class _NoPlot:
    """Stands in for matplotlib where it is not installed: every chart call does nothing."""

    def __getattr__(self, _name):
        return self

    def __call__(self, *a, **k):
        return self

    def __getitem__(self, _key):
        return self

    def __iter__(self):
        return iter((self, self))


# ------------------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(description="ACF/PACF identification of the SARIMA orders")
    ap.add_argument("--csv")
    ap.add_argument("--mysql", action="store_true")
    ap.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) - 1))
    ap.add_argument("--no-validation", action="store_true")
    ap.add_argument("--no-ljung-box", action="store_true", help="skip the Ljung-Box residual test in the AIC check")
    args = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
    except ImportError:  # e.g. the live server's pinned venv: same numbers, no charts
        print("(matplotlib not installed: charts skipped, every figure below is unchanged)")
        plt = _NoPlot()

    t0 = time.time()
    print(LINE + "\nREMEDI — SARIMA PARAMETERS FROM ACF / PACF\n" + LINE)
    print("No model is assumed: the candidates come from the ACF/PACF reading and the test chooses.")
    print("\n[1/4] Loading sales history...")
    daily = load_daily(args)
    series = product_series(daily)
    store = pd.concat(series.values(), axis=1).sum(axis=1).sort_index()
    print(f"      {len(series):,} products, {store.index[0]:%b %Y} to {store.index[-1]:%b %Y} ({len(store)} complete months)")

    # ---------------- whole store
    print("\n[2/4] Whole store (all products added up), on log(1 + units)...")
    y = np.log1p(store.to_numpy(float))
    st = identify(store.to_numpy(float))
    for t in st["tests"]:
        print(f"      d={t['d']}: ADF p = {t['adf_p']:.4f}  KPSS p = {t['kpss_p']:.4f}  -> "
              f"{'stationary' if t['stationary'] else 'not stationary'}")
    print(f"      seasonal strength F_s = {st['seasonal_strength']}  -> D = {st['D']}"
          f"  ({'strong' if st['D'] else 'below 0.64: no seasonal differencing'})")
    print(f"      after d={st['d']}, D={st['D']}: significant lags in a row from lag 1 -- ACF {st['count_q']}, PACF {st['count_p']}")
    print(f"      shape: {SHAPES[st['pattern']]}  ->  p = {st['p']}, q = {st['q']}")
    print(f"      lag 12: ACF {'spike' if st['acf12'] else 'inside band'}, PACF {'spike' if st['pacf12'] else 'inside band'}"
          f"  ->  P = {st['P']}, Q = {st['Q']}")
    print(f"      ACF/PACF suggest SARIMA{label(st['order'], st['seasonal_order'])} for the store total")

    w_level = y
    w_diff = np.diff(y, n=st["d"]) if st["d"] else y
    if st["D"]:
        w_diff = w_diff[S:] - w_diff[:-S]
    fig, axes = plt.subplots(2, 2, figsize=(13, 7.2))
    r0, p0, b0 = correlograms(w_level, 2 * S)
    plot_pair(axes[0, 0], axes[0, 1], r0, p0, b0, "store total, log(1 + units), no differencing")
    plot_pair(axes[1, 0], axes[1, 1], st["acf"], st["pacf"], st["band"],
              f"store total after d = {st['d']}, D = {st['D']}")
    fig.suptitle("Whole store — ACF and PACF (red = outside the 95% band, dotted = lags 12 and 24)", fontsize=12.5, x=0.01, ha="left")
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "store_acf_pacf.png"), dpi=150)
    plt.close(fig)

    # AIC confirmation around the reading, on the store total
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    from statsmodels.stats.diagnostic import acorr_ljungbox
    grid = []
    for p in range(3):
        for q in range(3):
            for P in range(2):
                for Q in range(2):
                    o, so = (p, st["d"], q), (P, st["D"], Q, S)
                    try:
                        with warnings.catch_warnings():
                            warnings.simplefilter("ignore")
                            fit = SARIMAX(y, order=o, seasonal_order=so).fit(disp=False)
                        lb = None if args.no_ljung_box else float(acorr_ljungbox(
                            fit.resid[st["d"] + S * st["D"] + 1:], lags=[12], return_df=True)["lb_pvalue"].iloc[0])
                        grid.append({"order": label(o, so), "aic": float(fit.aic), "bic": float(fit.bic), "ljung_box_p": lb})
                    except Exception:  # noqa: BLE001
                        pass
    grid.sort(key=lambda g: g["aic"])
    if args.no_ljung_box:
        print("      AIC check around it (lowest first; Ljung-Box residual test skipped):")
    else:
        print("      AIC check around it (lowest first; Ljung-Box p > 0.05 = residuals are white noise):")
    for g in grid[:5]:
        lb_text = "" if g["ljung_box_p"] is None else f"  Ljung-Box p {g['ljung_box_p']:.3f}"
        print(f"        SARIMA{g['order']:<18} AIC {g['aic']:8.2f}  BIC {g['bic']:8.2f}{lb_text}")

    # ---------------- every product
    print("\n[3/4] Every product, on log(1 + units) (the series the app fits)...")
    readings, rows_csv = {}, []
    for sku, s in series.items():
        if len(s) < MIN_MONTHS_FOR_READING or s.std() == 0:
            continue
        r = identify(s.to_numpy(float))
        if r is None:
            continue
        readings[sku] = r
        rows_csv.append({"sku": sku, "months": len(s), "d": r["d"], "D": r["D"], "p": r["p"], "q": r["q"],
                         "P": r["P"], "Q": r["Q"], "pattern": r["pattern"], "count_p": r["count_p"], "count_q": r["count_q"],
                         "seasonal_strength": r["seasonal_strength"],
                         "acf_lag12_spike": r["acf12"], "pacf_lag12_spike": r["pacf12"],
                         "suggested": "SARIMA" + label(r["order"], r["seasonal_order"]),
                         **{f"acf_{k}": round(float(r["acf"][k]), 4) for k in range(1, min(13, len(r["acf"])))},
                         **{f"pacf_{k}": round(float(r["pacf"][k]), 4) for k in range(1, min(13, len(r["pacf"])))}})
    pd.DataFrame(rows_csv).to_csv(os.path.join(OUT, "product_acf_pacf_orders.csv"), index=False)
    n = len(readings)
    cnt = lambda k: Counter(r[k] for r in readings.values())  # noqa: E731
    pct = lambda c, v: f"{v} = {c[v]:,} ({c[v] / n * 100:.1f}%)"  # noqa: E731

    def show(title, key, order=None):
        c = cnt(key)
        vals = [v for v in (order or sorted(c)) if c[v]]
        print(f"      {title:<34} " + "   ".join(pct(c, v) for v in vals))

    print(f"      {n:,} products have enough history to read (>= {MIN_MONTHS_FOR_READING} months)")
    show("d (ADF + KPSS)", "d")
    show("D (seasonal strength > 0.64)", "D")
    show("shape of ACF / PACF", "pattern", ("MA", "AR", "ARMA", "white noise"))
    show("p (AR order, after the shape)", "p")
    show("q (MA order, after the shape)", "q")
    show("P (PACF spike at lag 12)", "P")
    show("Q (ACF spike at lag 12)", "Q")
    orders = Counter(label(r["order"], r["seasonal_order"]) for r in readings.values())
    print("      Most common orders, product by product:")
    for o, c in orders.most_common(6):
        print(f"        SARIMA{o:<18} {c:>6,} products ({c / n * 100:.1f}%)")

    # The catalogue's reading: the AVERAGE correlogram over every product (one
    # product's 4 years of months is noisy; averaging 2,400 of them is not).
    L = 2 * S
    acf_mat = np.array([np.pad(r["acf"][:L + 1], (0, max(0, L + 1 - len(r["acf"]))), constant_values=np.nan) for r in readings.values()])
    pacf_mat = np.array([np.pad(r["pacf"][:L + 1], (0, max(0, L + 1 - len(r["pacf"]))), constant_values=np.nan) for r in readings.values()])
    bands = np.array([r["band"] for r in readings.values()])[:, None]
    share_acf = np.nanmean(np.abs(acf_mat) > bands, axis=0)
    share_pacf = np.nanmean(np.abs(pacf_mat) > bands, axis=0)
    avg_acf, avg_pacf, mb = np.nanmean(acf_mat, axis=0), np.nanmean(pacf_mat, axis=0), float(bands.mean())
    p_c, q_c, shape_c, _, _ = shape_rule(avg_acf, avg_pacf, mb)
    d_c, D_c = cnt("d").most_common(1)[0][0], cnt("D").most_common(1)[0][0]
    print("")
    print("      Average ACF / PACF over all products (the catalogue's reading):")
    print("        lag      " + "".join(f"{k:>8}" for k in (1, 2, 3, 4, 12)))
    print("        ACF      " + "".join(f"{avg_acf[k]:>8.3f}" for k in (1, 2, 3, 4, 12)))
    print("        PACF     " + "".join(f"{avg_pacf[k]:>8.3f}" for k in (1, 2, 3, 4, 12)))
    print(f"        95% band +/-{mb:.3f}")
    print(f"        ACF lag 1 = PACF lag 1 = {avg_acf[1]:.3f} (always equal: nothing lies between a month and the one before)")
    print(f"        after lag 1 the ACF is ~0 while the PACF shrinks {avg_pacf[1]:.2f} -> {avg_pacf[2]:.2f} -> {avg_pacf[3]:.2f}")
    print(f"        shape: {SHAPES[shape_c]}  ->  p = {p_c}, q = {q_c}")
    # Lag 12: a seasonal term only where MORE products spike there than the 5%
    # a 95% band lets through by chance -- ACF -> seasonal MA (Q), PACF -> seasonal AR (P).
    Q_c, P_c = int(share_acf[S] > 0.05), int(share_pacf[S] > 0.05)
    print(f"        lag 12: a spike in the ACF for {share_acf[S] * 100:.1f}% of products, in the PACF for "
          f"{share_pacf[S] * 100:.1f}% (5% = chance level)  ->  P = {P_c}, Q = {Q_c}")
    reading = (p_c, d_c, q_c)
    seasonal_read = (P_c, D_c, Q_c, S if (P_c or D_c or Q_c) else 0)
    plain = (reading, seasonal_read)
    print(f"      => ACF/PACF give p = {p_c}, d = {d_c}, q = {q_c}, P = {P_c}, D = {D_c}, Q = {Q_c}:  SARIMA{label(*plain)}")
    # CANDIDATES, all built from the reading -- no model is taken as a default:
    #   the full reading;
    #   if it has both seasonal terms, each one alone (the simpler models);
    #   if it has one, the same without it;
    #   if it has none, each single seasonal term added (so a SARIMA form is tested too);
    #   and the order a plain count of significant lags gives (no cut-off / tail-off check).
    seas = [("reading", "ACF/PACF reading", seasonal_read)]
    if P_c and Q_c:
        seas += [("sar_only", "seasonal AR only", (1, D_c, 0, S)), ("sma_only", "seasonal MA only", (0, D_c, 1, S))]
    elif P_c or Q_c:
        seas += [("no_seasonal_term", "without its seasonal term", (0, D_c, 0, S if D_c else 0))]
    else:
        seas += [("plus_sar", "+ seasonal AR", (1, D_c, 0, S)), ("plus_sma", "+ seasonal MA", (0, D_c, 1, S))]
    candidates = [(k, f"{nm:<26}SARIMA{label(reading, so)}", (reading, so)) for k, nm, so in seas]
    count_p = Counter(r["count_p"] for r in readings.values()).most_common(1)[0][0]
    count_q = Counter(r["count_q"] for r in readings.values()).most_common(1)[0][0]
    if (count_p, d_c, count_q) != reading:
        cnt_order = ((count_p, d_c, count_q), seasonal_read)
        candidates.append(("count", f"{'count of lags only':<26}SARIMA{label(*cnt_order)}", cnt_order))

    fig, axes = plt.subplots(2, 2, figsize=(13, 7.2))
    plot_pair(axes[0, 0], axes[0, 1], avg_acf, avg_pacf, mb, f"average over {n:,} products (after their own d, D)")
    for ax, sh, name in ((axes[1, 0], share_acf, "ACF"), (axes[1, 1], share_pacf, "PACF")):
        lags = np.arange(1, L + 1)
        ax.bar(lags, sh[1:] * 100, color=["#dc2626" if k in (1, 12) else "#60a5fa" for k in lags])
        ax.axhline(5, color="#6b7280", ls="--", lw=0.9)
        ax.text(L, 6, "5% = chance level", ha="right", fontsize=8.5, color="#6b7280")
        ax.set_title(f"{name} — % of products with a significant spike at each lag", fontsize=10.5, loc="left")
        ax.set_xlabel("lag (months)", fontsize=9)
        ax.set_ylabel("% of products", fontsize=9)
        ax.tick_params(labelsize=8.5)
        ax.grid(axis="y", color="#e5e7eb", lw=0.6)
    fig.suptitle(f"Per product — ACF cuts off after lag 1, PACF tails off: SARIMA{label(*plain)}", fontsize=12.5, x=0.01, ha="left")
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "products_acf_pacf.png"), dpi=150)
    plt.close(fig)

    # three best sellers, each with its own reading
    top = sorted(series, key=lambda k: series[k].sum(), reverse=True)[:3]
    fig, axes = plt.subplots(3, 2, figsize=(13, 10))
    for i, sku in enumerate(top):
        r = readings.get(sku)
        if r:
            plot_pair(axes[i, 0], axes[i, 1], r["acf"], r["pacf"], r["band"],
                      f"SKU {sku} -> SARIMA{label(r['order'], r['seasonal_order'])}")
    fig.suptitle("Three best-selling products — their own ACF and PACF", fontsize=12.5, x=0.01, ha="left")
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "top_products_acf_pacf.png"), dpi=150)
    plt.close(fig)

    # ---------------- validation
    summary = {"data_months": [f"{store.index[0]:%Y-%m}", f"{store.index[-1]:%Y-%m}"], "products": len(series),
               "store": {k: st[k] for k in ("d", "D", "p", "q", "P", "Q", "seasonal_strength")} | {
                   "tests": st["tests"], "suggested": label(st["order"], st["seasonal_order"]), "aic_top5": grid[:5]},
               "products_read": n, "counts": {k: {str(v): c for v, c in cnt(k).items()} for k in "dDpqPQ"},
               "orders_top": orders.most_common(10), "reading": label(*plain),
               "average_acf": [round(float(v), 4) for v in avg_acf[:L + 1]],
               "average_pacf": [round(float(v), 4) for v in avg_pacf[:L + 1]]}
    if not args.no_validation:
        print(f"\n[4/4] Scoring on each product's last {HOLDOUT} months (hidden from the fit)...")
        tasks = [(sku, s.to_numpy(float), s.index[0], candidates) for sku, s in series.items()]
        results = []
        with ProcessPoolExecutor(max_workers=args.workers) as pool:
            for i, r in enumerate(pool.map(validate_task, tasks, chunksize=8), 1):
                if r:
                    results.append(r)
                if i % 500 == 0 or i == len(tasks):
                    print(f"      {i:,}/{len(tasks):,}  ({time.time() - t0:.0f}s)", flush=True)
        keys = [k for k, _, _ in candidates] + ["own"]
        both = [r for r in results if all(r.get(k) for k in keys)]
        print(f"      compared on the same {len(both):,} products (every order forecast them):")
        val = {}
        names = {k: nm for k, nm, _ in candidates} | {"own": "each product's own reading"}
        for key in keys:
            m = summarise(both, key)
            val[key] = m
            print(f"        {names[key]:<40} MAE {m['mae']:.4f}  RMSE {m['rmse']:.4f}  MAPE {m['mape']:.2f}%  "
                  f"median {m['median_mape']:.2f}%  WAPE {m['wape']:.2f}%")
        # The CHOICE: the most accurate single model on the hidden months (each
        # product's own order is shown for reference only -- it is not one model).
        # A tie goes to the model with fewer terms.
        orders_by_key = {k: o for k, _, o in candidates}
        n_terms = lambda o: o[0][0] + o[0][2] + o[1][0] + o[1][2]  # noqa: E731
        chosen = min(orders_by_key, key=lambda k: (round(val[k]["mae"], 4), n_terms(orders_by_key[k])))
        print(LINE)
        print(f"PARAMETERS FROM ACF / PACF:   p = {p_c}   d = {d_c}   q = {q_c}   |   P = {P_c}   D = {D_c}   Q = {Q_c}   s = 12")
        print(f"  d = {d_c}  " + ("already stationary (ADF + KPSS agree)" if d_c == 0 else
                                 (f"{d_c} differences make" if d_c > 1 else "one difference makes") + " the series stationary (ADF + KPSS agree)"))
        print(f"  q = {q_c}  " + (f"the ACF cuts off after lag {q_c}" if q_c else "no significant ACF lag"))
        print(f"  p = {p_c}  " + ("the PACF tails off: its lag-1 spike is the ACF's own (they are always equal), not an AR term"
                                 if p_c == 0 and q_c else (f"the PACF cuts off after lag {p_c}" if p_c else "no significant PACF lag")))
        print(f"  D = {D_c}  seasonal strength {'above' if D_c else 'below'} 0.64 on most products"
              f" ({'a strong' if D_c else 'no strong'} yearly pattern)")
        print(f"  Q = {Q_c}  ACF spike at lag 12 on {share_acf[S] * 100:.1f}% of products "
              f"({'above' if Q_c else 'under'} the 5% chance level)")
        print(f"  P = {P_c}  PACF spike at lag 12 on {share_pacf[S] * 100:.1f}% of products "
              f"({'above' if P_c else 'under'} the 5% chance level)")
        c_order = orders_by_key[chosen]
        (cp, cd, cq), (cP, cD, cQ, cs) = c_order
        print(LINE)
        print(f"CHOSEN BY THE TEST:   SARIMA{label(*c_order)}")
        print(f"  the most accurate of the {len(candidates)} candidates on the hidden months: "
              f"MAE {val[chosen]['mae']:.4f}, MAPE {val[chosen]['mape']:.2f}%, WAPE {val[chosen]['wape']:.2f}%")
        ranked = sorted(orders_by_key, key=lambda k: val[k]["mae"])
        for k in ranked:
            print(f"    {'->' if k == chosen else '  '} SARIMA{label(*orders_by_key[k]):<18} MAE {val[k]['mae']:.4f}  "
                  f"MAPE {val[k]['mape']:.2f}%")
        print(f"  p = {cp}  d = {cd}  q = {cq}   (from the ACF/PACF and ADF + KPSS above)")
        same = (cP, cD, cQ) == (P_c, D_c, Q_c)
        print(f"  P = {cP}  D = {cD}  Q = {cQ}  s = {cs or 12}   "
              + ("(as the plots read)" if same else
                 f"(the plots read P = {P_c}, D = {D_c}, Q = {Q_c}; this simpler/other form forecast better)"))
        app = tuple(CURRENT)
        print(f"  The app's code (resources/python/generate_forecasts.py) holds SARIMA{label(*app)}: "
              + ("the same model." if app == tuple(c_order) else "different -- update it to the chosen model to use it."))
        summary["chosen"] = {"model": label(*c_order), "mae": val[chosen]["mae"], "mape": val[chosen]["mape"],
                             "app_code": label(*app), "app_matches": app == tuple(c_order)}
        summary["validation"] = val | {"products_compared": len(both)}
        pd.DataFrame([{"sku": r["sku"], "own_order": r.get("own_order"),
                       **{f"{k}_mae": round(r[k]["mae"], 4) for k in keys if r.get(k)},
                       **{f"{k}_mape": (None if r[k]["mape"] is None else round(r[k]["mape"], 4)) for k in keys if r.get(k)}}
                      for r in results]).to_csv(os.path.join(OUT, "validation_by_product.csv"), index=False)

    with open(os.path.join(OUT, "summary.json"), "w") as f:
        json.dump(summary, f, indent=2, default=str)
    print(LINE + f"\nCharts and CSVs: {OUT}\nDone in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
