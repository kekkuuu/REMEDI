<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Store-wide settings an admin sets through the UI. The one thing here so
 * far is the POS void passcode -- see SaleController::void() for how staff
 * spend it, and Setting for how it's stored.
 */
class SettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings.edit', [
            'voidPasscodeSet' => Setting::voidPasscodeIsSet(),
        ]);
    }

    /**
     * Set (or replace) the manager passcode staff enter to void a sale.
     * `confirmed` mirrors the field this app already asks a new password
     * twice for -- a 6-digit code mistyped once is now valid until an admin
     * notices, since nothing else would catch it.
     */
    public function updateVoidPasscode(Request $request)
    {
        $validated = $request->validate([
            'passcode' => ['required', 'digits:6', 'confirmed'],
        ]);

        Setting::setVoidPasscode($validated['passcode']);

        AuditTrail::log('Updated', 'Set the POS void passcode');

        return $this->actionOk($request, 'Void passcode updated.', redirect()->route('settings.edit'));
    }
}
