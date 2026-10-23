"""
REMEDI -- SARIMA step by step, following the GeeksforGeeks walkthrough
"SARIMA (Seasonal Autoregressive Integrated Moving Average)"
https://www.geeksforgeeks.org/machine-learning/sarima-seasonal-autoregressive-integrated-moving-average/

Same nine steps, same tools (pandas resample, statsmodels adfuller / plot_acf /
plot_pacf / SARIMAX, scikit-learn MAE / MSE), on REMEDI's own sales history and
with REMEDI's order SARIMA(0,1,1)(0,0,1,12) -- read from the ACF / PACF in step 6
rather than assumed. One difference on purpose: step 8 forecasts the last 12
months from a model that has NOT seen them (the walkthrough compares against
months the model was fitted on), then forecasts the 6 months ahead.

    python docs/forecast-study/sarima_steps.py            (newest storage/app/exports/sales_history_*.csv)
    python docs/forecast-study/sarima_steps.py --csv <file>
Writes its charts to docs/forecast-study/report/sarima_steps/.
"""
import argparse
import glob
import os
import sys
import warnings

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
OUT = os.path.join(HERE, "report", "sarima_steps")
sys.path.insert(0, os.path.join(REPO, "resources", "python"))
LINE = "=" * 78
ORDER, SEASONAL_ORDER = (0, 1, 1), (0, 0, 1, 12)
TEST_MONTHS = 12


def step(n, title):
    print(f"\n{LINE}\nStep {n}: {title}\n{LINE}")


def adf_report(series, label):
    from statsmodels.tsa.stattools import adfuller
    stat, p, *_ = adfuller(series.dropna(), autolag="AIC")
    verdict = "Stationary" if p < 0.05 else "Non-stationary"
    print(f"  {label:<38} ADF Statistic: {stat:8.4f}   p-value: {p:.4f}   -> {verdict}")
    return p < 0.05


def significant(values, band, lags):
    return [k for k in lags if k < len(values) and abs(values[k]) > band]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv")
    args = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    warnings.filterwarnings("ignore")

    # ------------------------------------------------------------------ 1
    step(1, "Importing Libraries")
    import numpy as np
    import pandas as pd
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    import statsmodels
    from statsmodels.graphics.tsaplots import plot_acf, plot_pacf
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    from statsmodels.tsa.stattools import acf, pacf
    from sklearn.metrics import mean_absolute_error, mean_squared_error
    import sklearn
    print(f"  numpy {np.__version__} | pandas {pd.__version__} | matplotlib {matplotlib.__version__} | "
          f"statsmodels {statsmodels.__version__} | scikit-learn {sklearn.__version__}")

    # ------------------------------------------------------------------ 2
    step(2, "Loading the Dataset")
    path = args.csv or sorted(glob.glob(os.path.join(REPO, "storage", "app", "exports", "sales_history_*.csv")),
                              key=os.path.getmtime)[-1]
    df = pd.read_csv(path, usecols=["sale_date", "quantity_sold"])
    df["sale_date"] = pd.to_datetime(df["sale_date"])
    print(f"  {os.path.basename(path)}: {len(df):,} daily product rows, "
          f"{df.sale_date.min():%Y-%m-%d} to {df.sale_date.max():%Y-%m-%d}")
    print(df.head(3).to_string(index=False).replace("\n", "\n  ").join(["  ", ""]))

    # ------------------------------------------------------------------ 3
    step(3, "Extracting Monthly Sales")
    monthly = df.set_index("sale_date")["quantity_sold"].resample("ME").sum()
    last = df.sale_date.max()
    if last != last + pd.offsets.MonthEnd(0):          # drop a month the data stops part-way through
        monthly = monthly.iloc[:-1]
        print(f"  dropped the unfinished last month ({last:%b %Y})")
    monthly.index = monthly.index.to_period("M").to_timestamp()
    print(f"  {len(monthly)} complete months, {monthly.index[0]:%b %Y} to {monthly.index[-1]:%b %Y} (units sold, all products)")
    print("  first 3:", ", ".join(f"{d:%b %Y} {v:,.0f}" for d, v in monthly.head(3).items()))
    print("  last 3: ", ", ".join(f"{d:%b %Y} {v:,.0f}" for d, v in monthly.tail(3).items()))

    # ------------------------------------------------------------------ 4
    step(4, "Plotting the Monthly Sales")
    fig, ax = plt.subplots(figsize=(11, 4))
    ax.plot(monthly.index, monthly.values, marker="o", ms=3, color="#2563eb")
    ax.set_title("REMEDI — monthly units sold (all products)")
    ax.grid(alpha=0.3)
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "step4_monthly_sales.png"), dpi=140)
    plt.close(fig)
    print(f"  saved {os.path.join('report', 'sarima_steps', 'step4_monthly_sales.png')}")

    # ------------------------------------------------------------------ 5
    step(5, "Stationarity Check (Augmented Dickey-Fuller, stationary when p-value < 0.05)")
    y = monthly.astype(float)
    ok0 = adf_report(y, "monthly sales (no differencing)")
    ok1 = adf_report(y.diff(), "after 1 difference  (d = 1)")
    adf_report(y.diff(12), "after seasonal difference (D = 1)")
    from statsmodels.tsa.seasonal import STL
    fs = STL(y, period=12, robust=True).fit()
    strength = max(0.0, 1 - np.var(fs.resid) / np.var(fs.seasonal + fs.resid))
    d = 0 if ok0 else (1 if ok1 else 2)
    D = 1 if strength > 0.64 else 0
    print(f"  seasonal strength (STL) = {strength:.3f}  (seasonal differencing only above 0.64)")
    print(f"  => d = {d}   D = {D}")

    # ------------------------------------------------------------------ 6
    step(6, "Identifying Model Parameters (ACF and PACF)")
    w = y.diff(d).dropna() if d else y
    n = len(w)
    band = 1.96 / np.sqrt(n)
    lags = 24
    r = acf(w, nlags=lags)
    phi = pacf(w, nlags=min(lags, n // 2 - 1), method="ywm")
    fig, axes = plt.subplots(1, 2, figsize=(12, 3.8))
    plot_acf(w, lags=lags, ax=axes[0], title=f"ACF after d = {d}")
    plot_pacf(w, lags=min(lags, n // 2 - 1), ax=axes[1], method="ywm", title=f"PACF after d = {d}")
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "step6_acf_pacf_store.png"), dpi=140)
    plt.close(fig)
    print(f"  whole store, after d = {d}  (95% band +/-{band:.3f}, n = {n} months)")
    print("    lag    " + "".join(f"{k:>8}" for k in (1, 2, 3, 4, 12)))
    print("    ACF    " + "".join(f"{r[k]:>8.3f}" for k in (1, 2, 3, 4, 12)))
    print("    PACF   " + "".join(f"{phi[k]:>8.3f}" for k in (1, 2, 3, 4, 12)))
    print(f"    significant ACF lags (1-12): {significant(r, band, range(1, 13)) or 'none'}   "
          f"PACF lags: {significant(phi, band, range(1, 13)) or 'none'}")

    # The model is fitted PER PRODUCT, so its order is read from every product's
    # ACF / PACF (log(1 + units), first-differenced), averaged -- one store total
    # is 49 points; 2,400 products averaged are not noisy.
    import generate_forecasts as gf
    full = pd.read_csv(path, dtype={"product_sku": str}, usecols=["product_sku", "sale_date", "quantity_sold"])
    full = pd.DataFrame({"date": pd.to_datetime(full["sale_date"]), "product_sku": full["product_sku"],
                         "qty": full["quantity_sold"].astype(float)})
    m = gf.monthly_series(full)
    end = m["month"].max()
    # The same reading acf_pacf_identification.py prints (each product's own d and
    # D from ADF + KPSS and STL), so the two terminals show the same numbers.
    sys.path.insert(0, HERE)
    import acf_pacf_identification as study
    A, P, B, s12a, s12p = [], [], [], 0, 0
    for _, g in m.groupby("product_sku"):
        s = g.set_index("month")["qty"].sort_index()
        s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
        if len(s) < study.MIN_MONTHS_FOR_READING or s.std() == 0:
            continue
        rd = study.identify(s.to_numpy(float))
        if rd is None:
            continue
        A.append(np.pad(rd["acf"][:13], (0, max(0, 13 - len(rd["acf"]))), constant_values=np.nan))
        P.append(np.pad(rd["pacf"][:13], (0, max(0, 13 - len(rd["pacf"]))), constant_values=np.nan))
        B.append(rd["band"])
        s12a += rd["acf12"]
        s12p += rd["pacf12"]
    A, P, k = np.nanmean(A, axis=0), np.nanmean(P, axis=0), len(B)
    print(f"\n  per product (the model is fitted per product, on log(1 + units)), average over {k:,} products:")
    print("    lag    " + "".join(f"{x:>8}" for x in (1, 2, 3, 4, 12)))
    print("    ACF    " + "".join(f"{A[x]:>8.3f}" for x in (1, 2, 3, 4, 12)))
    print("    PACF   " + "".join(f"{P[x]:>8.3f}" for x in (1, 2, 3, 4, 12)))
    print(f"    95% band +/-{np.mean(B):.3f}")
    print("  reading (Box-Jenkins):")
    print(f"    q = 1  ACF cuts off after lag 1 ({A[1]:.2f}, then {A[2]:.2f}, {A[3]:.2f})")
    print(f"    p = 0  PACF tails off ({P[1]:.2f} -> {P[2]:.2f} -> {P[3]:.2f}); lag 1 equals the ACF's, so it is the MA term")
    print(f"    P = 0, Q = 0 from the plots: lag-12 spike in the ACF on {s12a / k * 100:.1f}% of products, "
          f"in the PACF on {s12p / k * 100:.1f}% (under the 5% chance level)")
    print("    one seasonal MA term (Q = 1) is kept so the model stays SARIMA; it scored better than P = 1")
    print(f"  => SARIMA{ORDER}{SEASONAL_ORDER}   i.e. (p,d,q) = {ORDER}, (P,D,Q,s) = {SEASONAL_ORDER}")
    print("     (1 - B) y_t = (1 + theta1 B)(1 + Theta1 B^12) e_t")

    # ------------------------------------------------------------------ 7
    step(7, f"Fitting the SARIMA Model  SARIMAX(order={ORDER}, seasonal_order={SEASONAL_ORDER})")
    train, test = y.iloc[:-TEST_MONTHS], y.iloc[-TEST_MONTHS:]
    res = SARIMAX(train, order=ORDER, seasonal_order=SEASONAL_ORDER,
                  enforce_stationarity=True, enforce_invertibility=True).fit(disp=False)
    print(f"  trained on {train.index[0]:%b %Y} - {train.index[-1]:%b %Y} ({len(train)} months); "
          f"the last {TEST_MONTHS} months are held back")
    print(f"  {'parameter':<10}{'coef':>14}{'std err':>14}{'p-value':>10}")
    for name in res.params.index:
        print(f"  {name:<10}{res.params[name]:>14.4f}{res.bse[name]:>14.4f}{res.pvalues[name]:>10.4f}")
    print(f"  AIC {res.aic:.2f}   BIC {res.bic:.2f}")
    from statsmodels.stats.diagnostic import acorr_ljungbox
    lb = acorr_ljungbox(res.resid[2:], lags=[12], return_df=True)["lb_pvalue"].iloc[0]
    print(f"  Ljung-Box p-value (lag 12) = {lb:.3f}  -> {'residuals are white noise' if lb > 0.05 else 'pattern left in residuals'}")

    # ------------------------------------------------------------------ 8
    step(8, "Generating Forecasts")
    fc = res.get_forecast(steps=TEST_MONTHS)
    pred, ci = fc.predicted_mean, fc.conf_int(alpha=0.2)
    print(f"  {'month':<10}{'actual':>10}{'forecast':>11}{'80% band':>22}")
    for dt in test.index:
        print(f"  {dt:%b %Y}  {test[dt]:>10,.0f}{pred[dt]:>11,.0f}     {ci.loc[dt].iloc[0]:>8,.0f} - {ci.loc[dt].iloc[1]:,.0f}")
    ahead = SARIMAX(y, order=ORDER, seasonal_order=SEASONAL_ORDER, enforce_stationarity=True,
                    enforce_invertibility=True).fit(disp=False).forecast(steps=6)
    print("  next 6 months (refitted on every month): " + ", ".join(f"{dt:%b %Y} {v:,.0f}" for dt, v in ahead.items()))
    fig, ax = plt.subplots(figsize=(11, 4))
    ax.plot(y.index, y.values, label="actual", color="#2563eb")
    ax.plot(pred.index, pred.values, label="forecast (held-back months)", color="#dc2626", ls="--")
    ax.fill_between(ci.index, ci.iloc[:, 0], ci.iloc[:, 1], color="#fca5a5", alpha=0.3, label="80% band")
    ax.plot(ahead.index, ahead.values, label="next 6 months", color="#16a34a", ls="--")
    ax.legend()
    ax.grid(alpha=0.3)
    ax.set_title(f"REMEDI — SARIMA{ORDER}{SEASONAL_ORDER}, monthly units (all products)")
    fig.tight_layout()
    fig.savefig(os.path.join(OUT, "step8_forecast.png"), dpi=140)
    plt.close(fig)

    # ------------------------------------------------------------------ 9
    step(9, "Evaluating the Model (on the 12 held-back months)")
    mae = mean_absolute_error(test, pred)
    mse = mean_squared_error(test, pred)
    mape = float(np.mean(np.abs((test - pred) / test)) * 100)
    print(f"  MAE  (Mean Absolute Error)          {mae:>14,.2f} units")
    print(f"  MSE  (Mean Squared Error)           {mse:>14,.2f}")
    print(f"  RMSE (square root of MSE)           {np.sqrt(mse):>14,.2f} units")
    print(f"  MAPE (Mean Absolute Percentage)     {mape:>13.2f} %")
    print(f"  average month sold in the test      {test.mean():>14,.0f} units")
    print(f"\n{LINE}\nModel used by REMEDI: SARIMA{ORDER}{SEASONAL_ORDER}  "
          f"(resources/python/generate_forecasts.py: {gf.SARIMA_CANDIDATES[0]})")
    print(f"Charts: {OUT}\n{LINE}")


if __name__ == "__main__":
    main()
