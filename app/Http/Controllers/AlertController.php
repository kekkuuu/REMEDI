<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON feed behind the topbar notification bell.
 *
 * Shared by admin and staff: every alert it returns links to an Inventory
 * filter both roles can already reach, so there is nothing here a staff account
 * could not see by browsing.
 */
class AlertController extends Controller
{
    /**
     * The full notifications page behind the panel's "View all" link.
     *
     * Lists more per kind than the bell does (PAGE_PER_KIND vs PER_KIND) and
     * keeps the per-kind totals linked, so the 645th low-stock product is still
     * one click away in Inventory rather than being dumped here.
     */
    public function page(Request $request, AlertService $alerts)
    {
        $payload = $alerts->payload(AlertService::PAGE_PER_KIND);

        return view('notifications.index', [
            'items' => $payload['items'],
            'totals' => $payload['alerts'],
            // Audit-derived rows are admin-only — same rule as the bell.
            'activity' => $request->user()?->isAdmin() ? $alerts->activity(30) : [],
        ]);
    }

    public function index(Request $request, AlertService $alerts): JsonResponse
    {
        $payload = $alerts->payload();

        // System/Updates come from the audit trail, which is admin-only — a
        // staff bell gets the inventory alerts and nothing else.
        $payload['activity'] = $request->user()?->isAdmin() ? $alerts->activity() : [];

        return response()->json($payload)
            // The bell polls this; a cached 304/200 from the browser would
            // freeze the badge at whatever it said when the tab opened.
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
