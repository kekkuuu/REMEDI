# -*- coding: utf-8 -*-
"""Regenerate Sales_Records_4Year.csv as a SMALL, NON-URBAN pharmacy.

The file it replaces described an urban chain branch: 151 transactions a day,
PHP 47,700 a day, 230 distinct SKUs crossing the counter daily. This one aims at
a single-branch town drugstore -- about 55 sales a day and PHP 9,000 a day, one
counter, two or three regular cashiers.

What is deliberately kept from the old generator, because the app depends on it:

  * A 12-month seasonal cycle per product. Without it, month-of-year explains no
    more of a product's variance than noise does, and seasonal SARIMA -- the
    whole point of the forecasting layer -- has nothing to find.
  * A Pareto popularity curve, so slow movers really are slow and the Analytics
    report's slow-moving section means something.
  * Weekday shape and the Philippine payday spike (the 15th and the end of the
    month), which is the strongest weekly signal a shop like this has.
  * One row per SALE LINE with a shared Sale ID, the shape SalesHistorySeeder
    reads. It aggregates to (sku, date, units); the money columns are for the
    file's own readability, since revenue in the app is always
    quantity_sold x products.selling_price.

What changes, beyond volume: a narrower daily assortment (a small shop sells
~50-70 distinct items a day, not 230), a longer tail of products that move a
handful of times a year, smaller baskets, and coverage running to TODAY rather
than stopping weeks back -- the gap is why the current month's Analytics report
had nothing to show.
"""
import csv
import io
import json
import math
import random
import statistics
import collections
import datetime

# ── Running it ──────────────────────────────────────────────────────────────
#
#   1. Export the catalogue this file has to reference (SKUs and prices must be
#      the ones in `products`, or SalesHistorySeeder skips the rows):
#
#      php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php";
#        $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
#        file_put_contents("database/data/products.json", App\Models\Product::with("category")->get()
#          ->map(fn($p)=>["sku"=>(string)$p->sku,"name"=>$p->name,"price"=>(float)$p->selling_price,
#            "unit"=>$p->unit,"category"=>$p->category->name ?? "Uncategorized"])->toJson());'
#
#   2. python database/data/generate_sales_history.py
#   3. Truncate `sales_history`, then: php artisan db:seed --class=SalesHistorySeeder
#   4. php artisan products:reorder-levels --apply   (levels are derived from this demand)
#   5. Regenerate BOTH forecast pipelines, or the forecast pages describe data
#      that no longer exists:
#        php artisan forecast:generate --source=mysql --python=python
#        php artisan sales-forecast:generate --source=mysql --python=python
#
# PREV is optional: it only carries the PRD- codes and printed names forward
# from an earlier file so a SKU keeps its identifiers. Without it the script
# mints its own.

import os

HERE = os.path.dirname(os.path.abspath(__file__))
CATALOGUE = os.environ.get("CATALOGUE", os.path.join(HERE, "products.json"))
PREV = os.environ.get("PREV", os.path.join(HERE, "Sales_Records_4Year.csv"))
OUT = os.environ.get("OUT", os.path.join(HERE, "Sales_Records_4Year.csv"))

# ── The shop ────────────────────────────────────────────────────────────────
START = datetime.date(2022, 9, 1)
# The imported record STOPS THE DAY BEFORE THE TERMINAL GOES LIVE.
#
# The first POS checkout on this install is 2026-08-16, so the CSV ends on the
# 15th. That is the whole point of the two records: `sales_history` is what the
# shop's books said before REMEDI was installed, and `sales` is what the
# terminal has recorded since. Generating imported rows for days the till was
# already running puts two sources on the same day, and every "Imported / This
# terminal" split on the Sales report then describes a shop that was somehow
# doing both.
#
# Check it before regenerating: SELECT MIN(created_at) FROM sales.
END = datetime.date(2026, 8, 15)

# The BASE, not the outcome. Every multiplier below (weekday, month, payday,
# growth, noise) has an expectation above 1, and together they lift the realised
# median by ~1.19x -- the first run came out at 64 sales a day against a base of
# 55. The base is therefore set so the DELIVERED median is the 55 that was
# actually asked for; check the printed stats, not this number.
TX_PER_DAY = 46.2                        # -> ~55 transactions on a median day
TARGET_BASKET = 164.0                    # PHP, so ~PHP 9,000 a day
GROWTH_PER_YEAR = 0.05                   # a small shop growing slowly

CASHIERS = ["A. Cruz", "L. Bautista", "M. Reyes"]
PAYMENTS = (["Cash"] * 90) + (["GCash"] * 8) + (["Card"] * 2)

# Sun..Sat. Saturday is market day; Sunday is short hours.
WEEKDAY = {0: 1.00, 1: 0.95, 2: 0.95, 3: 1.00, 4: 1.10, 5: 1.25, 6: 0.70}

# Cough-and-cold season and the December rush, against a quiet Feb-May.
MONTH = {1: 1.15, 2: 0.95, 3: 0.92, 4: 0.90, 5: 0.95, 6: 1.05,
         7: 1.10, 8: 1.12, 9: 1.05, 10: 1.02, 11: 1.05, 12: 1.20}

# How often a category is bought at all, relative to medicine.
CATEGORY_PULL = {
    "Medicine / Pharmaceutical": 1.00,
    "Vitamins & Supplements": 0.70,
    "Personal Care": 0.60,
    "Baby Care": 0.45,
    "Milk & Dairy": 0.45,
    "Medical Supplies & Devices": 0.35,
    "Snacks & Confectionery": 0.30,
    "Water & Beverages": 0.28,
    "Household": 0.20,
    "General Merchandise": 0.20,
}

LINES_PER_BASKET = ([1] * 45) + ([2] * 33) + ([3] * 16) + ([4] * 6)
QTY_PER_LINE = ([1] * 55) + ([2] * 25) + ([3] * 10) + ([4] * 5) + ([5] * 3) + ([10] * 2)

rng = random.Random(20260902)

# ── The catalogue ───────────────────────────────────────────────────────────
products = json.load(io.open(CATALOGUE, encoding="utf-8"))

# Keep the identifiers the old file used, so a SKU that appeared before still
# carries the same PRD- code and printed name.
codes = {}
if os.path.exists(PREV) and PREV != OUT:
    with io.open(PREV, encoding="utf-8", newline="") as fh:
        for r in csv.DictReader(fh):
            sku = r["SKU / Barcode"]
            if sku not in codes:
                codes[sku] = (r["Product ID"], r["Product Name"])

for i, p in enumerate(products):
    code, name = codes.get(p["sku"], ("PRD-%05d" % (i + 1), p["name"]))
    p["code"] = code
    p["printed_name"] = name

# Popularity: a Zipf curve over a fixed shuffle, tilted by category. The head is
# the paracetamol/amoxicillin/vitamins end of the shop; the tail moves a few
# times a year, which is what a small pharmacy's dead stock actually looks like.
order = list(range(len(products)))
rng.shuffle(order)

# A shop this size does not ACTIVELY stock 2,638 lines. It carries perhaps eight
# hundred, and the rest of the catalogue is either special-order or dead stock
# that moves once or twice a year. Spreading the same units across everything is
# what made the median product sell 0.73 a month -- and a demand of 0.73 cannot
# be forecast in percentage terms at all: one unit of error on a one-unit month
# is 100%. Concentrating the volume is the honest fix, not a kinder model.
# ACTIVE_SKUS is the shelf: the lines the shop actually keeps and reorders.
# ZIPF_EXP shapes the curve WITHIN that shelf, and it is the parameter that
# decides whether anything here is forecastable. At 1.02 the curve collapses so
# fast that even the 800th line moved 0.3 a month and only 53 products cleared
# 20/month -- a catalogue of noise. Flatter (0.75) over a shorter shelf (450)
# spreads the same units across lines that each move several times a week,
# which is what a small pharmacy's fast half actually looks like.
ACTIVE_SKUS = 450
ZIPF_EXP = 0.75
DORMANT_PULL = 0.010                     # the tail still moves, just rarely

for rank, idx in enumerate(order):
    p = products[idx]
    # Exponent 1.02: steeper than a chain's curve. A small shop's counter is a
    # handful of fast movers plus whatever today's customers happened to need,
    # so the head has to carry more and the tail has to be genuinely idle.
    p["base"] = (1.0 / (rank + 12) ** ZIPF_EXP) * CATEGORY_PULL.get(p["category"], 0.3)

    if rank >= ACTIVE_SKUS:
        p["base"] *= DORMANT_PULL
    p["amp"] = rng.uniform(0.15, 0.50)          # seasonal swing
    p["phase"] = rng.uniform(0.0, 12.0)         # ...and when it peaks


def weights_for(month, beta):
    """Selection weights for one month, tilted by price**beta."""
    out = []
    for p in products:
        season = 1.0 + p["amp"] * math.sin(2.0 * math.pi * (month - p["phase"]) / 12.0)
        # A price floor of 1.00 for the tilt only: a handful of catalogue rows
        # carry a price of 0.00, and 0 ** negative is a division by zero. They
        # still sell -- they are in the catalogue -- they just cannot steer the
        # basket value.
        out.append(p["base"] * max(0.05, season) * (max(p["price"], 1.0) ** beta))
    return out


def mean_basket(beta, trials=4000):
    """Average basket value at a given price tilt."""
    w = weights_for(6, beta)
    total = 0.0
    picker = random.Random(7)
    for _ in range(trials):
        for p in picker.choices(products, weights=w, k=picker.choice(LINES_PER_BASKET)):
            total += p["price"] * picker.choice(QTY_PER_LINE)
    return total / trials


# Solve for the price tilt that lands the average basket on target. Bisection,
# because mean_basket is monotonic in beta and this is cheaper than reasoning
# about a weighted expectation over 2,638 prices.
lo, hi = -0.6, 1.2
for _ in range(28):
    mid = (lo + hi) / 2.0
    if mean_basket(mid) < TARGET_BASKET:
        lo = mid
    else:
        hi = mid
BETA = (lo + hi) / 2.0
print("price tilt beta = %.4f  ->  mean basket PHP %.2f" % (BETA, mean_basket(BETA, 8000)))

# ── Generate ────────────────────────────────────────────────────────────────
rows = 0
sale_no = 0
day_stats = []
month_weights = {m: weights_for(m, BETA) for m in range(1, 13)}

with io.open(OUT, "w", encoding="utf-8", newline="") as fh:
    w = csv.writer(fh)
    w.writerow(["Sale ID", "Date", "Product ID", "SKU / Barcode", "Product Name",
                "Qty Sold", "Unit Price", "Subtotal", "Cashier", "Payment Method"])

    day = START
    while day <= END:
        years = (day - START).days / 365.25
        payday = 1.35 if day.day in (15, 16, 30, 31, 1) else 1.0

        expected = (TX_PER_DAY
                    * WEEKDAY[day.weekday()]
                    * MONTH[day.month]
                    * payday
                    * ((1.0 + GROWTH_PER_YEAR) ** years)
                    * rng.lognormvariate(0.0, 0.18))

        count = max(4, int(round(expected)))
        weights = month_weights[day.month]
        stamp = "%d/%d/%d" % (day.month, day.day, day.year)

        units = revenue = 0.0
        skus = set()

        for _ in range(count):
            sale_no += 1
            sale_id = "SALE-%06d" % sale_no
            cashier = rng.choice(CASHIERS)
            payment = rng.choice(PAYMENTS)

            picked = rng.choices(products, weights=weights, k=rng.choice(LINES_PER_BASKET))

            for p in dict((x["sku"], x) for x in picked).values():   # no repeated SKU in one basket
                qty = rng.choice(QTY_PER_LINE)
                subtotal = round(p["price"] * qty, 2)
                w.writerow([sale_id, stamp, p["code"], p["sku"], p["printed_name"],
                            qty, "%.2f" % p["price"], "%.2f" % subtotal, cashier, payment])
                rows += 1
                units += qty
                revenue += subtotal
                skus.add(p["sku"])

        day_stats.append((count, units, revenue, len(skus)))
        day += datetime.timedelta(days=1)

tx = [d[0] for d in day_stats]
rev = [d[2] for d in day_stats]
un = [d[1] for d in day_stats]
sk = [d[3] for d in day_stats]

print("\nwritten          : %s lines, %s baskets, %s .. %s (%d days)"
      % ("{:,}".format(rows), "{:,}".format(sale_no), START, END, len(day_stats)))
print("transactions/day : median %d   mean %.0f   max %d" % (statistics.median(tx), statistics.mean(tx), max(tx)))
print("revenue/day      : median PHP %s  mean PHP %s"
      % ("{:,.0f}".format(statistics.median(rev)), "{:,.0f}".format(statistics.mean(rev))))
print("units/day        : median %d" % statistics.median(un))
print("distinct SKUs/day: median %d" % statistics.median(sk))
print("monthly revenue  : ~PHP %s" % "{:,.0f}".format(statistics.mean(rev) * 30.4))
print("annual revenue   : ~PHP %s" % "{:,.0f}".format(statistics.mean(rev) * 365))
