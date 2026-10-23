"""
REMEDI -- why the forecasting model is SARIMA(0,1,1)(0,0,1,12): the justification,
computed from the data every run (nothing below is typed in by hand).

  1. Each parameter with its evidence  (ADF, KPSS, STL, ACF, PACF)
  2. Residual check                    (Ljung-Box on every product's fitted model)
  3. Validation                        (the model against the alternatives, on months it never saw)
  4. Verdict

Reuses the Box-Jenkins reading in acf_pacf_identification.py and the app's own
fitting code (resources/python/generate_forecasts.py).

    python docs/forecast-study/sarima_justification.py [--csv <file>] [--workers N]
"""
import argparse
import os
import sys
import time
import warnings
from concurrent.futures import ProcessPoolExecutor

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import acf_pacf_identification as study  # noqa: E402
gf = study.gf
S = study.S
LINE = "=" * 82
MODEL = study.CURRENT                                     # the order the app actually uses


def ok(flag):
    return "PASS" if flag else "FAIL"


def ljung_task(task):
    """Fit the app's model on one product's whole history; Ljung-Box p of its residuals (lag 12)."""
    warnings.filterwarnings("ignore")
    values = task
    from statsmodels.stats.diagnostic import acorr_ljungbox
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    try:
        fit = SARIMAX(np.log1p(values), order=MODEL[0], seasonal_order=MODEL[1],
                      enforce_stationarity=True, enforce_invertibility=True).fit(disp=False)
        return float(acorr_ljungbox(fit.resid[2:], lags=[12], return_df=True)["lb_pvalue"].iloc[0])
    except Exception:  # noqa: BLE001
        return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv")
    ap.add_argument("--mysql", action="store_true")
    ap.add_argument("--workers", type=int, default=max(1, (os.cpu_count() or 2) - 1))
    args = ap.parse_args()
    warnings.filterwarnings("ignore")
    t0 = time.time()
    (p, d, q), (P, D, Q, s) = MODEL

    print(LINE)
    print(f"JUSTIFICATION: why REMEDI forecasts with SARIMA{study.label(*MODEL)}")
    print(f"(p,d,q) = ({p},{d},{q}) month to month   |   (P,D,Q,s) = ({P},{D},{Q},{s}) same month last year")
    print(LINE)
    daily = study.load_daily(args)
    series = study.product_series(daily)
    store = pd.concat(series.values(), axis=1).sum(axis=1).sort_index()
    print(f"Data: {len(series):,} products, {store.index[0]:%b %Y} to {store.index[-1]:%b %Y} "
          f"({len(store)} complete months), modelled per product on log(1 + units)")

    readings = {}
    for sku, sr in series.items():
        if len(sr) >= study.MIN_MONTHS_FOR_READING and sr.std() > 0:
            r = study.identify(sr.to_numpy(float))
            if r:
                readings[sku] = r
    n = len(readings)
    share = lambda f: sum(1 for r in readings.values() if f(r)) / n * 100  # noqa: E731
    pad = lambda v: np.pad(v[:13], (0, max(0, 13 - len(v))), constant_values=np.nan)  # noqa: E731
    A = np.nanmean([pad(r["acf"]) for r in readings.values()], axis=0)
    Pf = np.nanmean([pad(r["pacf"]) for r in readings.values()], axis=0)
    band = float(np.mean([r["band"] for r in readings.values()]))
    p_c, q_c, shape, _, _ = study.shape_rule(A, Pf, band)
    st = study.identify(store.to_numpy(float))
    raw_tests = study.choose_d(store.to_numpy(float))[1]
    acf12, pacf12 = share(lambda r: r["acf12"]), share(lambda r: r["pacf12"])

    # ------------------------------------------------------------------ 1
    print(f"\n[1] EACH PARAMETER AND ITS EVIDENCE   ({n:,} products with >= {study.MIN_MONTHS_FOR_READING} months)")
    print(f"\n  d = {d}  one regular difference")
    for t in raw_tests:
        print(f"         store total, d={t['d']}:  ADF p = {t['adf_p']:.4f}   KPSS p = {t['kpss_p']:.4f}   "
              f"-> {'stationary' if t['stationary'] else 'NOT stationary'}")
    d_share = share(lambda r: r["d"] == 1)
    print(f"         products needing exactly one difference: {d_share:.1f}%")
    print(f"         {ok(d == 1 and raw_tests[-1]['d'] == 1)}  the series becomes stationary after one difference")

    print(f"\n  q = {q}  one MA term")
    print(f"         average ACF after differencing:   lag1 {A[1]:.3f}   lag2 {A[2]:.3f}   lag3 {A[3]:.3f}   "
          f"lag4 {A[4]:.3f}   (95% band +/-{band:.3f})")
    print(f"         {ok(q == q_c == 1)}  the ACF cuts off after lag 1 -> MA(1)")

    print(f"\n  p = {p}  no AR term")
    print(f"         average PACF after differencing:  lag1 {Pf[1]:.3f}   lag2 {Pf[2]:.3f}   lag3 {Pf[3]:.3f}   lag4 {Pf[4]:.3f}")
    print(f"         lag 1 equals the ACF's ({A[1]:.3f} = {Pf[1]:.3f}): it is the MA term showing, not an AR term")
    print(f"         {ok(p == p_c == 0)}  the PACF tails off  ({study.SHAPES[shape]})")

    print(f"\n  D = {D}  no seasonal differencing")
    strong = share(lambda r: r["D"] == 1)
    print(f"         STL seasonal strength: store {st['seasonal_strength']}, products above 0.64: {strong:.1f}%")
    print(f"         {ok(D == 0 and strong < 50)}  the yearly pattern is too weak to difference away")

    print(f"\n  P = {P}  no seasonal AR term")
    print(f"         average PACF at lag 12 = {Pf[12]:.3f} (inside +/-{band:.3f});  spike on {pacf12:.1f}% of products")
    print(f"         {ok(P == 0 and pacf12 < 5)}  no PACF spike at lag 12 beyond the 5% chance level")

    print(f"\n  Q = {Q}  one seasonal MA term")
    print(f"         average ACF at lag 12 = {A[12]:.3f} (inside +/-{band:.3f});  spike on {acf12:.1f}% of products")
    print("         the plots alone give Q = 0; ONE seasonal term is kept so the model stays SARIMA,")
    print("         estimated per product (near 0 where a product has no yearly pattern) -- checked in [3]")

    print(f"\n  s = {s}  monthly data, yearly cycle  {ok(s == 12)}")

    # ------------------------------------------------------------------ 2
    print(f"\n[2] RESIDUAL CHECK  (Ljung-Box, lag 12: p > 0.05 = residuals are white noise)")
    tasks = [sr.to_numpy(float) for sku, sr in series.items() if len(sr) >= gf.MIN_MONTHS_FOR_SARIMA]
    with ProcessPoolExecutor(max_workers=args.workers) as pool:
        lbs = [x for x in pool.map(ljung_task, tasks, chunksize=16) if x is not None]
    white = sum(1 for x in lbs if x > 0.05) / len(lbs) * 100
    print(f"         SARIMA{study.label(*MODEL)} fitted on {len(lbs):,} products: residuals white noise on {white:.1f}%")
    print(f"         {ok(white >= 90)}  the model leaves no pattern behind on the large majority of products")

    # ------------------------------------------------------------------ 3
    print("\n[3] VALIDATION  (each product's last 3 months hidden, forecast, then compared)")
    candidates = [
        ("current", f"SARIMA{study.label(*MODEL)}   <- used", MODEL),
        ("seasonal_ar", f"SARIMA{study.label((0, 1, 1), (1, 0, 0, S))}   seasonal AR instead", ((0, 1, 1), (1, 0, 0, S))),
        ("count", f"SARIMA{study.label((1, 1, 1), (0, 0, 1, S))}   p = 1 (plain lag count)", ((1, 1, 1), (0, 0, 1, S))),
        ("arima", f"ARIMA (0,1,1), no seasonal term (not SARIMA)", ((0, 1, 1), (0, 0, 0, 0))),
    ]
    jobs = [(sku, sr.to_numpy(float), sr.index[0], candidates) for sku, sr in series.items()]
    with ProcessPoolExecutor(max_workers=args.workers) as pool:
        results = [r for r in pool.map(study.validate_task, jobs, chunksize=8) if r]
    keys = [k for k, _, _ in candidates] + ["own"]
    both = [r for r in results if all(r.get(k) for k in keys)]
    names = {k: nm for k, nm, _ in candidates} | {"own": "each product's own ACF/PACF order"}
    val = {k: study.summarise(both, k) for k in keys}
    print(f"         same {len(both):,} products for every model")
    print(f"         {'model':<56}{'MAE':>8}{'MAPE':>9}{'WAPE':>9}")
    for k in keys:
        print(f"         {names[k]:<56}{val[k]['mae']:>8.4f}{val[k]['mape']:>8.2f}%{val[k]['wape']:>8.2f}%")
    sarimas = [k for k in keys if k != "arima"]
    best = min(sarimas, key=lambda k: val[k]["mae"])
    print(f"         {ok(best == 'current')}  lowest error of the SARIMA models tested")
    gap = val["current"]["mae"] - val["arima"]["mae"]
    print(f"         note: the non-seasonal ARIMA is {gap:.4f} units/month better here -- the price of keeping the")
    print("               seasonal term the system is built around, on data with a weak yearly pattern")

    # ------------------------------------------------------------------ 4
    checks = [d == 1 and raw_tests[-1]["d"] == 1, q == q_c == 1, p == p_c == 0, D == 0 and strong < 50,
              P == 0 and pacf12 < 5, s == 12, white >= 90, best == "current"]
    print(f"\n{LINE}")
    print(f"VERDICT: SARIMA{study.label(*MODEL)} is {'JUSTIFIED' if all(checks) else 'NOT JUSTIFIED'}"
          f"  ({sum(checks)} of {len(checks)} checks passed)")
    print(f"  d = 1  one difference makes the series stationary (ADF, KPSS)")
    print(f"  q = 1  ACF cuts off after lag 1          p = 0  PACF tails off")
    print(f"  D = 0  weak seasonal strength            P = 0  no PACF spike at lag 12")
    print(f"  Q = 1  one seasonal MA term kept for SARIMA; most accurate SARIMA on unseen months")
    print(f"  model used by the app: {gf.SARIMA_CANDIDATES[0]}  (resources/python/generate_forecasts.py)")
    print(f"{LINE}\nDone in {time.time() - t0:.0f}s")


if __name__ == "__main__":
    main()
