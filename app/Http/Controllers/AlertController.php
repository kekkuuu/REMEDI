<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\JsonResponse;

/**
 * JSON feed behind the topbar notification bell.
 *
 * Shared by admin and staff: every alert it returns links to an Inventory
 * filter both roles can already reach, so there is nothing here a staff account
 * could not see by browsing.
 */
class AlertController extends Controller
{
    public function index(AlertService $alerts): JsonResponse
    {
        return response()->json($alerts->payload())
            // The bell polls this; a cached 304/200 from the browser would
            // freeze the badge at whatever it said when the tab opened.
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
