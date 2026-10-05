"""The whole forecasting pipeline for ONE product, step by step, with the real numbers -- for a demo:

    python docs/forecast-study/explain_metrics.py [SKU]

Default SKU: SODIUM ASCORBATE 568.18 (CEVITA). Every step calls the live code in
resources/python/generate_forecasts.py on the local database; nothing is written anywhere.

  1  Data              daily sales -> calendar months (incomplete month dropped)
  2  Candidates        the four seasonal SARIMA orders every product chooses from
  3  Model selection   each candidate order scored on 3 hidden 3-month windows (MAE)
  4  Fit               the winning order on log(1 + units); its coefficients
  5  Demand forecast   next 6 months, back-transformed to units, 80% range
  6  Sales forecast    units x selling price = revenue
  7  Accuracy test     last 3 months hidden, forecast, compared with actual
  8  Metrics           MAE, RMSE, MAPE, WAPE worked out by hand
  9  Whole system      the same over every product; store totals per month
"""
import math
import os
import sys
import warnings

warnings.filterwarnings("ignore")
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "resources", "python"))
import numpy as np  # noqa: E402
import pandas as pd  # noqa: E402
import pymysql  # noqa: E402
from statsmodels.tsa.statespace.sarimax import SARIMAX  # noqa: E402

import generate_forecasts as gf  # noqa: E402

SKU = sys.argv[1] if len(sys.argv) > 1 else "4804625528389"
ENV = os.path.join(HERE, "..", "..", ".env")
W = 76
LINE, BAR = "-" * W, "=" * W


def head(n, title):
    print(f"\n{BAR}\nSTEP {n}  {title}\n{BAR}")


def label(order, seasonal):
    return f"SARIMA{order}{seasonal[:3]}x{seasonal[3]}" if seasonal[3] else f"ARIMA{order}"


# ---------------------------------------------------------------- database
e = gf.db_credentials(ENV)
conn = pymysql.connect(host=e.get("DB_HOST", "127.0.0.1"), port=int(e.get("DB_PORT", 3306)),
                       user=e.get("DB_USERNAME", "root"), password=e.get("DB_PASSWORD", ""), database=e["DB_DATABASE"])
with conn.cursor() as cur:
    cur.execute("SELECT name, selling_price FROM products WHERE sku=%s", (SKU,))
    found = cur.fetchone()
if not found:
    sys.exit(f"No product with SKU {SKU}")
name, price = found[0], float(found[1])
acc = pd.read_sql("SELECT mae, rmse, mape, abs_error, actual_units, avg_monthly_units, method FROM forecast_accuracy", conn)
demand_db = pd.read_sql("SELECT forecast_date, forecast_value FROM demand_forecasts", conn)
sales_db = pd.read_sql("SELECT forecast_date, forecast_units, forecast_revenue FROM sales_forecasts", conn)
conn.close()

print(BAR)
print(f"REMEDI forecast walkthrough: {name}")
print(f"SKU {SKU}   selling price PHP {price:,.2f}")
print(BAR)

# ---------------------------------------------------------------- 1. data
head(1, "Data: daily sales -> calendar months")
raw = gf.load_from_mysql(ENV)
mine = raw[raw.product_sku == SKU]
monthly = gf.monthly_series(raw)
end = monthly["month"].max()
s = monthly[monthly.product_sku == SKU].set_index("month")["qty"].sort_index()
if s.empty:
    sys.exit(f"No sales history for SKU {SKU}")
s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
print(f"Daily rows read  : {len(mine):,} (sales_history, {mine.date.min():%d %b %Y} - {mine.date.max():%d %b %Y})")
print(f"Last data day    : {raw.date.max():%d %b %Y} -> {raw.date.max():%b %Y} is incomplete and dropped")
print(f"Monthly series   : {len(s)} months, {s.index[0]:%b %Y} - {s.index[-1]:%b %Y}, "
      f"{int(s.sum()):,} units, {int((s == 0).sum())} months with 0")
print("\nUnits sold per month:")
years = sorted(set(s.index.year))
print("  " + "year " + "".join(f"{pd.Timestamp(2000, m, 1):%b}".rjust(6) for m in range(1, 13)))
for y in years:
    cells = "".join((f"{int(s[pd.Timestamp(y, m, 1)]):6,}" if pd.Timestamp(y, m, 1) in s.index else "     .")
                    for m in range(1, 13))
    print(f"  {y} {cells}")

# ---------------------------------------------------------------- 2. candidates
head(2, "Candidates: seasonal SARIMA only")
fam = gf.SARIMA_CANDIDATES
print("Every product chooses from these SARIMA(p,d,q)(P,D,Q,12) orders:")
for o, so in fam:
    print(f"  {label(o, so)}")
print("D = 0: the yearly term is one estimated coefficient, so a product with no")
print("yearly pattern gets a coefficient near zero instead of last year's swings.")

# ---------------------------------------------------------------- 3. selection
head(3, "Model selection: score each candidate on 3 hidden windows")
ceiling = max(float(s.max()) * 2.5, float(s.mean()) * 4, 1.0)
folds = gf._selection_folds(s, gf.SARIMA_SELECTION_HOLDOUT)
print("Each window hides 3 months, fits on the months before, scores MAE.")
print("  " + "candidate".ljust(30) + "".join(f"{f'hide {tr.index[-1] + pd.offsets.MonthBegin(1):%b %y}+':>13}" for tr, _ in folds) + "  mean")
best = None
for order, so in fam:
    maes = []
    for tr, obs in folds:
        try:
            rows = gf._sarimax_rows(tr, gf._future_dates(tr, 3), 3, order, so, "x")
            ok = rows and gf._plausible([r["forecast_value"] for r in rows], ceiling)
        except Exception:  # noqa: BLE001
            ok = False
        if not ok:
            maes = None
            break
        pred = np.array([r["forecast_value"] for r in gf._clamp_rows(rows, ceiling)[:len(obs)]])
        maes.append(float(np.mean(np.abs(pred - obs[:len(pred)]))))
    if maes is None:
        print(f"  {label(order, so):30}{'rejected (fit failed or implausible)':>39}")
        continue
    mean = sum(maes) / len(maes)
    print(f"  {label(order, so):30}" + "".join(f"{v:13,.1f}" for v in maes) + f"{mean:6,.1f}")
    if best is None or mean < best[2]:
        best = (order, so, mean)
print(f"Winner (lowest mean MAE): {label(best[0], best[1])}")

# ---------------------------------------------------------------- 4. fit
head(4, "Fit the winner on all months, on log(1 + units)")
fit = SARIMAX(np.log1p(s), order=best[0], seasonal_order=best[1],
              enforce_stationarity=bool(best[1][3]), enforce_invertibility=bool(best[1][3])).fit(disp=False)
print("y' = ln(1 + units); the model is fitted on y', forecasts come back as e^y' - 1")
for k, v in zip(fit.param_names, fit.params):
    print(f"  {k:12} = {v:+.4f}")
print(f"  AIC = {fit.aic:.1f}   BIC = {fit.bic:.1f}")

# ---------------------------------------------------------------- 5-6. forecast + revenue
future = gf.forecast_product(s, 6)
method = future[0]["method"].upper()
head(5, f"Demand forecast, next 6 months ({method})")
pm = fit.get_forecast(1).predicted_mean.iloc[0]
print(f"Example: first month y' = {pm:.4f} -> units = e^{pm:.4f} - 1 = {math.expm1(pm):,.1f} "
      f"-> rounded {round(math.expm1(pm)):,}")
print(f"\n  {'Month':9}{'Units':>8}   80% range")
for r in future:
    print(f"  {r['forecast_date']:%b %Y}  {r['forecast_value']:>8,.0f}   {r['lower_ci']:,.0f} - {r['upper_ci']:,.0f}")

head(6, f"Sales forecast: units x PHP {price:,.2f}")
tot_u = tot_r = 0.0
print(f"  {'Month':9}{'Units':>8}   x price  = Revenue (PHP)   80% range (PHP)")
for r in future:
    u = r["forecast_value"]
    tot_u += u
    tot_r += u * price
    print(f"  {r['forecast_date']:%b %Y}  {u:>8,.0f}  x {price:>6,.2f} = {u * price:>13,.2f}   "
          f"{r['lower_ci'] * price:,.2f} - {r['upper_ci'] * price:,.2f}")
print(f"  {'TOTAL':9}{tot_u:>8,.0f}{'':10} = {tot_r:>13,.2f}")

# ---------------------------------------------------------------- 7-8. accuracy
head(7, "Accuracy test: hide the last 3 months, forecast them")
train, test = s.iloc[:-3], s.iloc[-3:]
rows = gf.forecast_product(train, 3)
print(f"Train {train.index[0]:%b %Y} - {train.index[-1]:%b %Y} -> forecast {test.index[0]:%b %Y} - "
      f"{test.index[-1]:%b %Y} ({rows[0]['method'].upper()})\n")
print(f"  {'Month':9}{'Actual':>8}{'Forecast':>10}{'F-A':>8}{'|F-A|':>8}{'(F-A)^2':>12}{'|F-A|/A':>10}")
abs_e, sq_e, ape = [], [], []
for d, a, r in zip(test.index, test.to_numpy(float), rows):
    f = float(r["forecast_value"])
    err = f - a
    abs_e.append(abs(err))
    sq_e.append(err ** 2)
    ape.append(abs(err) / a * 100 if a > 0 else None)
    pct = f"{ape[-1]:.2f}%" if ape[-1] is not None else "A=0"
    print(f"  {d:%b %Y}  {a:>8,.0f}{f:>10,.0f}{err:>+8,.0f}{abs(err):>8,.0f}{err ** 2:>12,.0f}{pct:>10}")
print(f"  {'TOTAL':9}{test.sum():>8,.0f}{'':18}{sum(abs_e):>8,.0f}{sum(sq_e):>12,.0f}")

head(8, "Metrics for this product")
n = len(abs_e)
mae = sum(abs_e) / n
rmse = math.sqrt(sum(sq_e) / n)
used = [p for p in ape if p is not None]
print("MAE  = sum|F-A| / n")
print(f"     = ({' + '.join(f'{v:,.0f}' for v in abs_e)}) / {n} = {mae:,.2f} units")
print("RMSE = sqrt( sum(F-A)^2 / n )")
print(f"     = sqrt( {sum(sq_e):,.0f} / {n} ) = sqrt( {sum(sq_e) / n:,.2f} ) = {rmse:,.2f} units")
print("MAPE = average of |F-A| / A x 100   (months that sold 0 are skipped)")
if used:
    print(f"     = ({' + '.join(f'{v:.2f}%' for v in used)}) / {len(used)} = {sum(used) / len(used):.2f}%")
if test.sum():
    print("WAPE = sum|F-A| / sum A x 100")
    print(f"     = {sum(abs_e):,.0f} / {test.sum():,.0f} x 100 = {sum(abs_e) / test.sum() * 100:.2f}%")

# ---------------------------------------------------------------- 9. system
head(9, f"Whole system: steps 1-8 for all {len(acc):,} products")
print(f"MAE  = average of the products' MAE   = {acc.mae.mean():.2f} units")
print(f"RMSE = average of the products' RMSE  = {acc.rmse.mean():.2f} units")
print(f"MAPE = average of the products' MAPE  = {acc.mape.mean():.1f}%  ({acc.mape.notna().sum():,} sold something)")
print(f"WAPE = all |errors| / all units sold  = {acc.abs_error.sum():,.0f} / {acc.actual_units.sum():,.0f}"
      f" = {acc.abs_error.sum() / acc.actual_units.sum() * 100:.1f}%")
print("Models: " + ", ".join(f"{m.upper()} {c:,}" for m, c in acc.method.value_counts().items()))
print("\nBy sales volume (average monthly units before the test):")
for lo, hi, nm in [(0, 5, "under 5"), (5, 20, "5-20"), (20, 100, "20-100"), (100, 1e12, "100+")]:
    b = acc[(acc.avg_monthly_units >= lo) & (acc.avg_monthly_units < hi)]
    print(f"  {nm:8} {len(b):6,} products   MAE {b.mae.mean():6.2f}   MAPE {b.mape.mean():5.1f}%   "
          f"WAPE {b.abs_error.sum() / b.actual_units.sum() * 100:5.1f}%")
d = demand_db.groupby("forecast_date").forecast_value.sum()
sa = sales_db.groupby("forecast_date")[["forecast_units", "forecast_revenue"]].sum()
print("\nStore totals (every product's forecast added up):")
print(f"  {'Month':9}{'Demand units':>14}{'Sales units':>13}{'Revenue (PHP)':>17}")
for m in d.index:
    print(f"  {pd.Timestamp(m):%b %Y}  {d[m]:>14,.0f}{sa.loc[m, 'forecast_units']:>13,.0f}"
          f"{sa.loc[m, 'forecast_revenue']:>17,.2f}")
print(f"  {'TOTAL':9}{d.sum():>14,.0f}{sa.forecast_units.sum():>13,.0f}{sa.forecast_revenue.sum():>17,.2f}")
print(BAR)
