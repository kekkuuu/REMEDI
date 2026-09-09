<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    /**
     * The filter set shared by the table and the CSV export.
     *
     * Extracted so the two cannot drift: export() used to apply none of this
     * and always dumped the whole table, so filtering the page to one user or
     * one day and hitting "Export CSV" handed back all 623 rows — the opposite
     * of what the button appears to do, and easy to miss because the file
     * still downloads and still looks plausible.
     */
    private function applyFilters(Request $request, $query)
    {
        // Validate here, in the shared helper, so the table and the CSV export
        // cannot disagree about what a valid filter is.
        //
        // Unvalidated, an invalid date reached MySQL and failed SILENTLY -- and
        // in opposite directions depending on which end it was, which is what
        // made it hard to notice:
        //
        //   ?date_from=banana      -> filter did nothing, all 617 rows returned
        //   ?date_to=2026-13-45    -> filter matched nothing, "Total logs: 0"
        //
        // The export shares this method, so `?date_to=2026-13-45` produced a
        // CSV that downloaded cleanly, carried its header row, and contained no
        // data. For a pharmacy's audit trail that is the worst outcome
        // available: a well-formed compliance artifact asserting that nothing
        // ever happened.
        //
        // after_or_equal also rejects a reversed range outright rather than
        // returning an empty table the user has to work out for themselves.
        $request->validate([
            'search' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:50',
            'role' => 'nullable|string|max:20',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        if ($request->filled('search')) {
            // likeTerm() escapes the user's own % and _ — see Controller.
            $like = $this->likeTerm($request->search);
            $query->where(function ($q) use ($like) {
                $q->where('username', 'like', $like)
                    ->orWhere('details', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('ip_address', 'like', $like);
            });
        }

        // Through canonicalAction(), so a link carrying the old singular
        // spelling ("Create") still finds its rows rather than returning an
        // empty table — see the note on AuditTrail::ACTIONS.
        if ($request->filled('action')) {
            $query->where('action', AuditTrail::canonicalAction($request->action));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Date range. whereDate on both ends, so "to" includes everything that
        // happened on that day rather than stopping at 00:00 — the usual
        // off-by-one that makes a same-day filter return nothing.
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    /**
     * Default the range to TODAY when nothing was asked for.
     *
     * Landing on the full history (1,400+ rows and counting, append-only) is
     * not a useful first view -- "what happened today" is. `?all=1` (the
     * "Show All" control) or an explicit date_from/date_to bypasses this.
     * Merged into the request rather than threaded through as extra
     * parameters, so applyFilters() and the view's `request('date_from')`
     * bindings both see the same effective range without a second copy of
     * this decision. Called from both index() and export() so the CSV can
     * never disagree with what the table is showing -- the same reason
     * applyFilters() itself is shared.
     */
    private function applyTodayDefault(Request $request): void
    {
        if (! $request->boolean('all') && ! $request->filled('date_from') && ! $request->filled('date_to')) {
            $today = today()->toDateString();
            $request->merge(['date_from' => $today, 'date_to' => $today]);
        }
    }

    public function index(Request $request)
    {
        $this->applyTodayDefault($request);

        $query = $this->applyFilters($request, AuditTrail::query());

        $logs = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $loginCount = AuditTrail::where('action', 'Login')->count();
        $logoutCount = AuditTrail::where('action', 'Logout')->count();
        $viewCount = AuditTrail::where('action', 'Viewed')->count();

        // Live filtering swaps the table only; same shape the other list pages
        // return (see the AJAX partial pattern in REMEDI.md). Pagination is
        // rendered inside the partial here rather than separately, because this
        // page draws its own pager instead of using $paginator->links().
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'html' => view('admin.audit._rows', compact('logs'))->render(),
                'total' => $logs->total(),
            ]);
        }

        return view('admin.audit.index', compact('logs', 'loginCount', 'logoutCount', 'viewCount'));
    }

    public function export(Request $request)
    {
        $this->applyTodayDefault($request);

        // Same filters the table is showing. The button sits beside them, so
        // "Export CSV" has to mean "export this", not "export everything".
        $query = $this->applyFilters($request, AuditTrail::query())
            ->orderByDesc('created_at');

        $filename = 'audit-trail-'.now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'User', 'Role', 'Action', 'Description', 'IP Address']);

            // lazy(), not get(): this is an append-only log with no ceiling, and
            // the response is already streamed. Materialising every row into a
            // collection first defeats that and puts the whole table in memory.
            foreach ($query->lazy() as $log) {
                // Every cell goes through csvCell(). The audit trail is the one
                // export whose contents are attacker-influenced by design: it
                // records names, and names are user input.
                fputcsv($handle, [
                    $this->csvCell($log->created_at),
                    // The denormalised `username` column, NOT $log->user->name.
                    // user_id is `onDelete('set null')`, and AuditTrail::log()
                    // stores the name precisely so it survives the account
                    // being deleted. Reading it through the relation threw that
                    // away and printed "System" — 78 of 623 rows here, real
                    // actions by "Staff One" attributed to nobody. Falling back
                    // to the relation keeps rows written before the column.
                    $this->csvCell($log->username ?: ($log->user->name ?? 'System')),
                    $this->csvCell($log->role),
                    $this->csvCell($log->action),
                    // `details` is the column. This said $log->description,
                    // which is not a column and has no accessor, so it resolved
                    // to null and the Description cell was empty on EVERY row —
                    // the export shipped 623 rows of timestamps with the one
                    // field that says what happened blank.
                    $this->csvCell($log->details),
                    $this->csvCell($log->ip_address),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Neutralise spreadsheet formula triggers in one CSV cell.
     *
     * `fputcsv` quotes for CSV, which is a different problem from this one: a
     * correctly quoted cell whose first character is `=`, `+`, `-` or `@` is
     * still a live formula when Excel, LibreOffice or Sheets opens the file.
     * The audit trail is the one export whose content is attacker-influenced by
     * design -- it records usernames and product names, and both are typed in
     * by someone -- so a user named `=HYPERLINK("http://…","Payroll")` ships a
     * clickable link straight into an admin's spreadsheet. Verified before the
     * fix: the exported User and Description cells came out as live formulas.
     *
     * Prefixing with an apostrophe marks the cell as text. That is the standard
     * mitigation, and its usual objection -- that it also alters legitimate
     * values, e.g. a negative number reading `-5` -- does not apply to THIS
     * file, which was the open question in REMEDI.md. None of its six columns
     * are numeric (date, user, role, action, description, IP), and across the
     * 709 rows here exactly zero legitimate values begin with a trigger
     * character, as do zero of the product and user names that feed them. So
     * the prefix can only ever appear on a value that was trying to be a
     * formula.
     *
     * Escaping at the point of export, not on write: the stored row must stay a
     * faithful record of what was actually entered. A log that quietly rewrites
     * its own contents is worse than one that needs escaping on the way out.
     */
    private function csvCell($value): string
    {
        $value = (string) $value;

        // Tab and CR join the four formula characters because a leading
        // whitespace character lets the trigger hide behind something the
        // reader strips before it evaluates the cell.
        //
        // chr(9) / chr(13) rather than escape sequences or literal control
        // characters, deliberately. The first version of this guard carried a
        // real tab and a real CR in the source: invisible in a diff, and any
        // editor that trims trailing whitespace would have silently weakened
        // it with nothing to show for it. The CR did worse than hide -- PHP
        // ends a // comment at a bare CR, so the one sitting in the comment
        // above turned the rest of that line into code and the file stopped
        // parsing outright.
        $first = substr($value, 0, 1);

        // chr(9)/chr(13) only for the two that have no readable literal.
        return in_array($first, ['=', '+', '-', '@', chr(9), chr(13)], true)
            ? "'".$value
            : $value;
    }
}
