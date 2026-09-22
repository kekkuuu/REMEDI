<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Sale;
use App\Models\SalesHistory;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Services\AlertService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with('user', 'items.product');

        // Staff can only see their own transactions; Admin sees all
        if ($request->user()->isStaff()) {
            $query->where('user_id', $request->user()->id);
        }

        // Snapshot the query while it carries the ROLE SCOPE ONLY, before any
        // of the user's filters go on. The "today" cards below are built from
        // this.
        //
        // They used to clone $query *after* the filters, which made two cards
        // labelled "Total sales today" and "Transactions today" report the
        // filter instead of today: narrowing the list to last week showed
        // "₱0.00 / 0" while the till had actually taken ₱1,459.76 across 7
        // transactions. Searching a transaction number did the same. The
        // numbers were only ever right when no filter was set — or when the
        // filter happened to be today.
        //
        // The scope itself is deliberately kept: a staff member's card is their
        // own takings, an admin's is the whole register. That is a property of
        // who is looking, not of what they typed in the search box.
        // payment_voided excluded here only -- the LIST below still shows a
        // voided sale (with its own badge), since hiding it from the list
        // entirely would look like the transaction never happened. Only the
        // "today" figures need to stop counting it.
        $scopedToday = (clone $query)->whereDate('created_at', today())->where('payment_voided', false);

        if ($request->filled('search')) {
            // Reject anything but a plain string before it reaches likeTerm(),
            // which is typed ?string -- ?search[]=x resolves to an array here
            // (filled() is true for a non-empty array too), and PHP does not
            // coerce an array to a typed string parameter: uncaught TypeError,
            // 500, for any signed-in user including via the AJAX live search.
            $request->validate(['search' => 'string|max:255']);

            // likeTerm() escapes the user's own % and _ — see Controller.
            $query->where('transaction_no', 'like', $this->likeTerm($request->search));
        }

        // Validate the range, then order it. Neither was happening here, and
        // all three failures were silent -- the page just showed a list, so
        // there was nothing to tell the user their filter had not been applied
        // the way they meant:
        //
        //   ?start_date=banana        -> MySQL cannot compare against it, the
        //                                predicate dropped, and all 15 rows came
        //                                back as though no filter were set
        //   ?end_date=2026-13-45      -> matched nothing: "No transactions found."
        //   start after end           -> matched nothing, same empty state, and
        //                                this one is reachable straight from the
        //                                UI by picking the two dates the wrong
        //                                way round
        //
        // ReportController and AuditTrailController were both fixed for exactly
        // this; the sales list was missed. Same reasoning as
        // ReportController::clampRange -- the range queried and the range shown
        // must be the same range, or the page states something untrue.
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        [$startDate, $endDate] = $this->orderedRange(
            $request->get('start_date'),
            $request->get('end_date')
        );

        // Default to TODAY when nothing was asked for -- landing on the
        // entire sales history (hundreds of rows, growing every day) is not
        // a useful first view, and "today" is what the KPI cards above the
        // table already assume matters most. ?all=1 (the "Show All" control)
        // or an explicit start_date/end_date bypasses this.
        if (! $request->boolean('all') && $startDate === null && $endDate === null) {
            $startDate = $endDate = today()->toDateString();
        }

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // transaction_no, not created_at: two sales created within the same
        // second (backfilled data especially) tie on created_at with no
        // defined order between them, so the list could show an older
        // transaction number above a newer one. transaction_no is zero-padded
        // (TXN-YYYYMMDD-NNNNN), so ordering the string orders the number it
        // encodes -- the same reasoning Sale::nextTransactionNo() already
        // relies on to find the latest row.
        $sales = $query->orderByDesc('transaction_no')->paginate(15)->withQueryString();

        // Today's sales summary — aggregate directly rather than
        // fetching every row (with its eager-loaded user/items/product
        // relations) just to throw the models away after summing two
        // numbers. Built from $scopedToday (see above), so it answers the
        // question the cards actually ask.
        $todayTotal = (clone $scopedToday)->sum('total_amount');
        $todayCount = (clone $scopedToday)->count();

        // Realtime search: same AJAX partial-swap pattern used by
        // Inventory, POS, and Forecasting. "Today's summary" above isn't
        // filter-dependent, so only the table + pagination need to come
        // back — no need to also resend those two cards on every keystroke.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('sales._rows', compact('sales'))->render(),
                'pagination' => (string) $sales->links(),

                // The range actually queried, so the two date inputs can correct
                // themselves when a reversed pair has been reordered. Without
                // this the fields would keep showing the reversed dates while
                // the results below them came from the ordered range.
                'range' => ['start' => $startDate, 'end' => $endDate],
            ]);
        }

        return view('sales.index', compact('sales', 'todayTotal', 'todayCount', 'startDate', 'endDate'));
    }

    public function show(Request $request, Sale $sale)
    {
        // Staff can only view their own transaction details. Same rule as the
        // receipt permalink and the suggest endpoint — see Sale::isVisibleTo().
        abort_unless($sale->isVisibleTo($request->user()), 403);

        $sale->load('items.product', 'user', 'voidedBy');

        return view('sales.show', compact('sale'));
    }

    /**
     * Void a completed sale. Reverses everything checkout did: every line's
     * batch gets its quantity back, one `StockMovement::TYPE_VOID` row per
     * line so the stock card shows exactly where it came from, and the sale
     * is flagged `payment_voided` so it stops counting in the revenue
     * aggregates that filter on it (DashboardController, ReportController,
     * SalesHistory — see the sweep through those) while staying fully
     * visible on its own receipt and in Sales History, with who voided it,
     * when, and why.
     *
     * SHARED, not role:admin, as of 2026-09-22 — staff may void a sale too,
     * given the correct manager passcode. Two gates, checked in this order:
     *
     *  1. Sale::isVisibleTo() — an admin may void anything; staff only a
     *     sale they themselves rang up. Checked HERE, not just on the button
     *     that links here (sales/show.blade.php only ever renders that
     *     button on a sale isVisibleTo() already let the viewer see, but a
     *     gated button is not a gated endpoint — see pos.receipt and
     *     suggest.sales for what happens when only the page checks).
     *  2. The passcode (Setting::checkVoidPasscode(), set by an admin at
     *     /settings) — required for staff, not for an admin, who does not
     *     need to prove themselves to themselves.
     *
     * The reason is chosen the same way User::ARCHIVE_REASONS is: the
     * confirm dialog's reason-picker (data-confirm-reasons) replaces the
     * single generic Confirm button with one per Sale::VOID_REASONS entry,
     * so picking one both answers "are you sure?" and supplies the field
     * this validates — see the shared confirmModal handler in
     * layouts/app.blade.php. Staff additionally see a passcode field first
     * (data-confirm-passcode), gating those same buttons until 6 digits are
     * entered.
     */
    public function void(Request $request, Sale $sale)
    {
        abort_unless($sale->isVisibleTo($request->user()), 403);

        $isAdmin = $request->user()->isAdmin();

        $rules = [
            'reason' => 'required|in:'.implode(',', array_keys(Sale::VOID_REASONS)),
        ];

        if (! $isAdmin) {
            $rules['passcode'] = ['required', 'digits:6'];
        }

        $validated = $request->validate($rules);

        if (! $isAdmin) {
            if (! Setting::voidPasscodeIsSet()) {
                return $this->actionFailed(
                    $request,
                    'No manager passcode has been set yet. Ask an admin to set one before voiding a sale.',
                    'passcode'
                );
            }

            if (! Setting::checkVoidPasscode($validated['passcode'])) {
                return $this->actionFailed($request, 'Incorrect passcode.', 'passcode');
            }
        }

        $sale->load('items.batch');

        // Locked and re-checked inside the transaction, not just the plain
        // property read above: two admins voiding the same sale at once
        // would otherwise both pass an unlocked check and both restock it,
        // the same double-restock race markBatchReturned's own lock exists
        // to close.
        $alreadyVoided = DB::transaction(function () use ($sale, $validated, $request) {
            $locked = Sale::whereKey($sale->getKey())->lockForUpdate()->first();

            if ($locked->payment_voided) {
                return true;
            }

            foreach ($sale->items as $item) {
                // withTrashed() on the relation: an archived batch or
                // product still gets its stock back. Nothing to restock
                // onto if the batch row itself is somehow gone (never
                // happens in practice — batches are archived, not deleted).
                $batch = $item->batch;

                if (! $batch) {
                    continue;
                }

                $batch->increment('quantity', $item->quantity);

                StockMovement::record(
                    $batch->fresh(),
                    StockMovement::TYPE_VOID,
                    $item->quantity,
                    'Voided sale '.$sale->transaction_no,
                    $sale->id,
                    $request->user()->id
                );
            }

            $locked->update([
                'payment_voided' => true,
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $validated['reason'],
            ]);

            return false;
        });

        if ($alreadyVoided) {
            return $this->actionFailed(
                $request,
                "Transaction {$sale->transaction_no} was already voided.",
                'reason'
            );
        }

        // A void moves stock and retires revenue the same way a checkout
        // does, so it needs the same two cache invalidations checkout makes
        // itself: the POS-dependent SalesHistory aggregates, and the alert
        // payload (restocking can pull a product back OUT of low stock).
        SalesHistory::bumpCacheVersion();
        AlertService::forget();

        $reasonLabel = Sale::VOID_REASONS[$validated['reason']];

        AuditTrail::log(
            'Updated',
            "Voided sale {$sale->transaction_no} ({$reasonLabel}) — ₱".number_format($sale->total_amount, 2).' reversed and stock restocked'
        );

        return $this->actionOk(
            $request,
            "Transaction {$sale->transaction_no} voided. Stock has been returned to the shelf.",
            redirect()->route('sales.show', $sale)
        );
    }

    /**
     * Put a user-supplied date range the right way round.
     *
     * A reversed pair is a slip, not a request for nothing: the two date
     * pickers sit side by side and picking the later one first is an easy
     * mistake. Answering it with an empty table -- which is what an unordered
     * `>= start AND <= end` does -- tells the user there were no sales in a
     * period where there may have been plenty.
     *
     * Ordering rather than rejecting keeps the AJAX contract intact too: the
     * live list re-runs on every change to either field, so a validation error
     * here would break the page mid-edit for something the user is about to
     * finish typing anyway.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function orderedRange(?string $start, ?string $end): array
    {
        // Normalise to Y-m-d before comparing. `nullable|date` accepts far more
        // than the date inputs emit -- "August 25, 2026" validates fine -- and
        // comparing those as plain strings orders them alphabetically, which is
        // not the same thing as chronologically. Parsing first also means the
        // value echoed back into the two <input type="date"> fields is one they
        // can actually display; a format they do not understand renders blank,
        // so the filter would appear to have cleared itself.
        $start = $start ? Carbon::parse($start)->toDateString() : null;
        $end = $end ? Carbon::parse($end)->toDateString() : null;

        if ($start && $end && $start > $end) {
            return [$end, $start];
        }

        return [$start, $end];
    }
}
