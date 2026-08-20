<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditTrail::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
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

    public function export()
    {
        $logs = AuditTrail::with('user')->orderByDesc('created_at')->get();

        $filename = 'audit-trail-'.now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'User', 'Action', 'Description', 'IP Address']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->created_at,
                    $log->user->name ?? 'System',
                    $log->action,
                    $log->description,
                    $log->ip_address,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
