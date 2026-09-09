<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        $scopedToday = (clone $query)->whereDate('created_at', today());

        if ($request->filled('search')) {
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

        $sale->load('items.product', 'user');

        return view('sales.show', compact('sale'));
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
