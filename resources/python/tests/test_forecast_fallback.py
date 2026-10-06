"""
The forecast fallback and SARIMA failure handling (2026-10-06).

    python -m unittest discover -s resources/python/tests -v

No database: series are built in memory, and the end-to-end case runs the real
script on a small receiving-report CSV with --source=csv.
"""
import csv
import os
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

import numpy as np
import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT_DIR = os.path.dirname(HERE)
sys.path.insert(0, SCRIPT_DIR)

import generate_forecasts as gf  # noqa: E402

HORIZON = 6


def series(values, start="2022-08-01"):
    return pd.Series([float(v) for v in values], index=pd.date_range(start, periods=len(values), freq="MS"))


def seasonal(months=48, base=60, seed=7):
    """A steady seller with a yearly swing -- the case SARIMA is for."""
    rng = np.random.default_rng(seed)
    t = np.arange(months)
    return series(np.round(base + 15 * np.sin(2 * np.pi * t / 12) + rng.normal(0, 4, months)))


# 4805225370325 from the real record, May 2023 - Jul 2026: 8 months with a sale
# in 39. Every SARIMA order was rejected for it before 2026-10-06 ("negative",
# -0.01 to -0.16 units on the log scale), so it had no forecast at all.
NEAR_ZERO_REAL = [1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0,
                  0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 0, 1, 0, 2, 2, 0, 0, 0, 0]


class InsufficientHistory(unittest.TestCase):
    def test_short_history_gets_the_recent_mean_without_trying_sarima(self):
        s = series([4, 5, 6, 5, 4, 6, 7, 5, 6, 4, 5, 6])  # 12 months
        with mock.patch.object(gf, "_sarimax_rows", side_effect=AssertionError("SARIMA must not be tried")):
            rows, info = gf.forecast_product_explained(s, HORIZON)

        self.assertEqual(len(rows), HORIZON)
        self.assertFalse(info["sarima_attempted"])
        self.assertEqual(info["sarima_status"], "skipped")
        self.assertIn("short history", info["failure_reason"])
        self.assertEqual(info["fallback_method"], gf.FALLBACK_RECENT_MEAN)
        self.assertEqual({r["method"] for r in rows}, {gf.FALLBACK_RECENT_MEAN})
        self.assertEqual(rows[0]["forecast_value"], 5.0)  # mean of the last 3: 4, 5, 6

    def test_short_intermittent_history_gets_croston(self):
        s = series([3, 0, 0, 2, 0, 0, 0, 4, 0, 0, 3, 0, 0, 0])  # 14 months, 4 with a sale
        rows, info = gf.forecast_product_explained(s, HORIZON)

        self.assertEqual(info["fallback_method"], gf.FALLBACK_CROSTON)
        self.assertIn("many_zero_months", info["flags"])
        self.assertEqual(len(rows), HORIZON)
        # Croston forecasts the rate: 12 units over 14 months is under 1 a month.
        self.assertEqual(rows[0]["forecast_value"], 1.0)

    def test_even_two_months_of_history_get_a_forecast(self):
        rows, info = gf.forecast_product_explained(series([5, 7]), HORIZON)
        self.assertEqual(len(rows), HORIZON)
        self.assertEqual(info["fallback_method"], gf.FALLBACK_RECENT_MEAN)


class SarimaFailures(unittest.TestCase):
    def test_a_fit_error_is_logged_and_the_product_falls_back(self):
        with mock.patch.object(gf, "_sarimax_rows", side_effect=ValueError("degenerate SARIMA fit")):
            rows, info = gf.forecast_product_explained(seasonal(), HORIZON)

        self.assertTrue(info["sarima_attempted"])
        self.assertEqual(info["sarima_status"], "failed")
        self.assertIn("degenerate SARIMA fit", info["failure_reason"])
        self.assertEqual(info["fallback_method"], gf.FALLBACK_RECENT_MEAN)
        self.assertEqual(len(rows), HORIZON)

    def test_the_sanity_check_still_rejects_an_unreasonable_forecast(self):
        s = seasonal()

        def absurd(monthly, dates, horizon, order, seasonal_order, method):
            return [{"forecast_date": d, "forecast_value": 1e6, "lower_ci": 0.0, "upper_ci": 2e6, "method": method}
                    for d in dates]

        with mock.patch.object(gf, "_sarimax_rows", side_effect=absurd):
            rows, info = gf.forecast_product_explained(s, HORIZON)

        self.assertEqual(info["sarima_status"], "failed")
        self.assertIn("above the ceiling", info["failure_reason"])
        self.assertTrue(all(r["method"] == gf.FALLBACK_RECENT_MEAN for r in rows))
        self.assertLess(max(r["forecast_value"] for r in rows), 1e6)

    def test_outliers_are_capped_and_sarima_retried_before_falling_back(self):
        values = list(seasonal())
        values[30] = 2000.0  # one wild month
        s = series(values)
        self.assertIn("outliers", gf.describe_series(s)["flags"])

        def sensitive(monthly, dates, horizon, order, seasonal_order, method):
            # Stands in for a fit thrown off by the outlier: absurd while it is
            # in the series, sensible once it has been capped.
            value = 1e6 if monthly.max() > 1000 else 60.0
            return [{"forecast_date": d, "forecast_value": value, "lower_ci": 40.0, "upper_ci": 80.0, "method": method}
                    for d in dates]

        with mock.patch.object(gf, "_sarimax_rows", side_effect=sensitive):
            rows, info = gf.forecast_product_explained(s, HORIZON)

        self.assertEqual(info["sarima_status"], "passed")
        self.assertEqual(info["repair"], "outliers capped: passed")
        self.assertEqual(rows[0]["forecast_value"], 60.0)

    def test_a_near_zero_log_scale_forecast_is_no_longer_a_failure(self):
        rows, info = gf.forecast_product_explained(series(NEAR_ZERO_REAL, "2023-05-01"), HORIZON)

        self.assertEqual(info["sarima_status"], "passed")
        self.assertEqual(rows[0]["method"], "sarima")
        self.assertTrue(all(r["forecast_value"] >= 0 for r in rows))

    def test_the_negative_tolerance_only_covers_what_rounds_to_zero(self):
        self.assertTrue(gf._plausible([-0.3, 1.0], ceiling=10))
        self.assertFalse(gf._plausible([-0.7, 1.0], ceiling=10))
        with mock.patch.object(gf, "TRANSFORM", "none"):  # raw units: any negative is a broken fit
            self.assertFalse(gf._plausible([-0.3, 1.0], ceiling=10))


class OrderTieBreak(unittest.TestCase):
    def test_a_whole_unit_tie_is_broken_on_the_unrounded_error_not_list_order(self):
        # Both orders forecast 5 once rounded -- a tie on whole-unit MAE. The
        # SECOND is closer before rounding (5.10 against 5.45 for an actual of 5)
        # and must win, whatever sMAPE or the list order would have said.
        far, near = ((1, 1, 1), (1, 0, 0, 12)), ((0, 1, 1), (1, 0, 0, 12))

        def fit(monthly, dates, horizon, order, seasonal_order, method):
            value = 5.45 if (order, seasonal_order) == far else 5.10
            return [{"forecast_date": d, "forecast_value": value, "lower_ci": 4.0, "upper_ci": 7.0, "method": method}
                    for d in dates]

        with mock.patch.object(gf, "_sarimax_rows", side_effect=fit):
            winner = gf._pick_sarima_order(series([5] * 36), 3, ceiling=50, candidates=[far, near])

        self.assertEqual(winner, near)


class SarimaSuccess(unittest.TestCase):
    def test_a_regular_seasonal_seller_keeps_sarima(self):
        rows, info = gf.forecast_product_explained(seasonal(), HORIZON)

        self.assertEqual(info["sarima_status"], "passed")
        self.assertIsNone(info["fallback_method"])
        self.assertEqual({r["method"] for r in rows}, {"sarima"})
        self.assertEqual(len(rows), HORIZON)


class NoProductDropped(unittest.TestCase):
    def test_even_a_crash_inside_the_forecast_still_yields_a_fallback(self):
        with mock.patch.object(gf, "forecast_product_explained", side_effect=RuntimeError("boom")):
            sku, rows, score, error, info = gf._forecast_task(("SKU-X", seasonal(), HORIZON))

        self.assertIsNone(error)
        self.assertEqual(len(rows), HORIZON)
        self.assertIn("crashed: RuntimeError: boom", info["failure_reason"])

    def test_every_product_is_in_the_output_and_the_total_counts_the_fallbacks(self):
        products = {
            "SEASONAL": list(seasonal(48)),                          # SARIMA
            "SHORT": [4, 5, 6, 5, 4, 6, 7, 5, 6, 4, 5, 6],            # too short: recent mean
            "SHORT-INTERMITTENT": [3, 0, 0, 2, 0, 0, 0, 4, 0, 0, 3, 0, 0, 0],  # too short: Croston
            "NEAR-ZERO": NEAR_ZERO_REAL,                               # used to be dropped
        }
        end = pd.Timestamp("2026-07-01")

        with tempfile.TemporaryDirectory() as tmp:
            source = os.path.join(tmp, "sales.csv")
            with open(source, "w", newline="", encoding="utf-8") as f:
                w = csv.writer(f)
                w.writerow(["Supplier >>", "TEST", ""])
                w.writerow(["DATE", "INVENTORY", "QTY RCV"])
                for sku, values in products.items():
                    start = end - pd.DateOffset(months=len(values) - 1)
                    for k, qty in enumerate(values):
                        if qty:
                            # Mid-month, and every month ends on its last day below,
                            # so the incomplete-month guard keeps July.
                            w.writerow([(start + pd.DateOffset(months=k)).strftime("%Y-%m-15"), sku, qty])
                w.writerow([end.strftime("%Y-%m-31"), "SEASONAL", 1])

            out, log = os.path.join(tmp, "forecast.csv"), os.path.join(tmp, "log.csv")
            done = subprocess.run(
                [sys.executable, os.path.join(SCRIPT_DIR, "generate_forecasts.py"), "--source=csv",
                 f"--csv-path={source}", f"--output={out}", f"--log={log}", "--workers=1"],
                capture_output=True, text=True)
            self.assertEqual(done.returncode, 0, done.stderr[-2000:])

            forecast = pd.read_csv(out)
            logged = pd.read_csv(log)

        self.assertEqual(set(forecast["product_sku"]), set(products))
        self.assertTrue((forecast.groupby("product_sku").size() == HORIZON).all())
        methods = forecast.groupby("product_sku")["method"].first().to_dict()
        self.assertEqual(methods["SEASONAL"], "sarima")
        self.assertEqual(methods["SHORT"], gf.FALLBACK_RECENT_MEAN)
        self.assertEqual(methods["SHORT-INTERMITTENT"], gf.FALLBACK_CROSTON)

        # The store total is the sum over EVERY product, fallback rows included.
        by_product = forecast.groupby("product_sku")["forecast_value"].sum()
        self.assertGreater(by_product["SHORT"], 0)
        self.assertAlmostEqual(forecast["forecast_value"].sum(), by_product.sum())

        self.assertEqual(set(logged["product_sku"]), set(products))
        self.assertEqual(logged.set_index("product_sku").loc["SHORT", "fallback_method"], gf.FALLBACK_RECENT_MEAN)


if __name__ == "__main__":
    unittest.main()
