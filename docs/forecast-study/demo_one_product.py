"""Run every forecast step for ONE product, for a demo: python docs/forecast-study/demo_one_product.py <SKU>
Reads the local database (MySQL must be running). Changes nothing."""
import sys, warnings; warnings.filterwarnings("ignore")
sys.path.insert(0, r"C:\xampp\htdocs\remedi.2\remedi\resources\python")
import pandas as pd, generate_forecasts as gf

ENV = r"C:\xampp\htdocs\remedi.2\remedi\.env"
SKU = sys.argv[1]
monthly = gf.monthly_series(gf.load_from_mysql(ENV))          # 1-2. read sales, total by month
end = monthly["month"].max()
s = monthly[monthly.product_sku == SKU].set_index("month")["qty"].sort_index()
s = s.reindex(pd.date_range(s.index.min(), end, freq="MS"), fill_value=0)
print(f"history: {len(s)} months, {s.index[0]:%Y-%m}..{s.index[-1]:%Y-%m}; last 3 months: {list(s.iloc[-3:].astype(int))}")
print("candidates:", ", ".join(f"SARIMA{o}{so}" for o, so in gf.SARIMA_CANDIDATES))   # 3. SARIMA only
rows = gf.forecast_product(s, 6)                                 # 4-6. choose order, fit, forecast
price = 0.0
import pymysql
e = gf.db_credentials(ENV)
c = pymysql.connect(host=e.get("DB_HOST","127.0.0.1"), port=int(e.get("DB_PORT",3306)), user=e.get("DB_USERNAME","root"), password=e.get("DB_PASSWORD",""), database=e["DB_DATABASE"])
with c.cursor() as cur:
    cur.execute("SELECT name, selling_price FROM products WHERE sku=%s", (SKU,)); name, price = cur.fetchone()
print(f"{name} @ PHP {float(price):,.2f}")
for r in rows:                                                   # sales forecast = units x price
    print(f"  {r['forecast_date']:%Y-%m}  {r['method']:6}  units {r['forecast_value']:>7.0f}  [{r['lower_ci']:.0f}-{r['upper_ci']:.0f}]  revenue PHP {r['forecast_value']*float(price):>12,.2f}")
m = gf.backtest_product(s)                                       # metrics on the 3 hidden months
print("holdout metrics:", {k: m[k] for k in ("holdout_months","mae","rmse","mape","smape","method")})
