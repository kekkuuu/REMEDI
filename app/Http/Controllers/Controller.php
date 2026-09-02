<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Upper bounds for numeric input, taken from the COLUMNS behind them.
     *
     * Every money column in this schema is decimal(10,2) and every count is a
     * signed int, and MySQL runs here with STRICT_TRANS_TABLES -- so a value
     * past either limit is not clamped, it raises SQLSTATE[22003] "Out of range
     * value" and the request dies as a 500 with SQL in the body.
     *
     * That was reachable from the till. `amount_paid` was validated
     * `numeric|min:0` with no ceiling, so a mistyped payment over 99,999,999.99
     * took checkout down with a server error rather than a message the cashier
     * could act on -- verified against MySQL, which refused the insert with
     * "Out of range value for column 'amount_paid'". The same shape existed on
     * selling_price, reorder_level and batch quantity.
     *
     * These are the column limits, not a business rule: the point is to turn a
     * 500 into a validation message, not to decide what a plausible price or
     * quantity is. If a column is ever widened, widen these with it.
     */
    protected const MAX_MONEY = '99999999.99';

    protected const MAX_COUNT = 2147483647;

    /**
     * Build a `LIKE %term%` binding with the user's own wildcards escaped.
     *
     * Every search box in the app interpolated the raw term straight into a
     * LIKE pattern, so `%` and `_` — LIKE's own wildcards — were executed
     * rather than searched for. Measured on this catalogue:
     *
     *   "70%150ML"    0 products contain it, LIKE returned 2
     *   "LEWIS_PEARL" 1 product contains it,  LIKE returned 5
     *
     * That is not a curiosity here: 9 products carry `%` in their name
     * (`G. CROSS ETHYL 70% W/ MOIST 150ML` and friends) and 3 carry `_`
     * (`LEWIS_PEARL COOL FANTASY 125ML`), so searching for a real product by
     * its real name returns rows that do not contain what was typed. A bare
     * `%` matched the entire 264-page catalogue.
     *
     * Backslash is escaped FIRST — doing it after would double-escape the
     * backslashes this method has just introduced.
     */
    protected function likeTerm(?string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term).'%';
    }

    /**
     * Answer a state-changing action in whichever shape the caller asked for.
     *
     * Delete / activate / return all go through the shared confirm dialog in
     * layouts/app.blade.php, which posts over fetch and wants JSON back. A
     * plain form post — what happens with JavaScript off — still gets the
     * redirect it always did, so none of these become dead buttons. Same
     * two-shapes contract PosController::checkout has had all along; this pair
     * just keeps the six call sites from each inventing their own.
     */
    protected function actionOk(Request $request, string $message, $redirect, array $extra = [])
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $message] + $extra);
        }

        return $redirect->with('success', $message);
    }

    /**
     * The refusal half: 422 + JSON for the dialog, withErrors() for a form post.
     *
     * 422 rather than 200-with-success-false so the fetch caller can branch on
     * res.ok, and so a plain client sees a real status.
     */
    protected function actionFailed(Request $request, string $error, string $field = 'error')
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => false, 'error' => $error], 422);
        }

        return back()->withErrors([$field => $error]);
    }
}
