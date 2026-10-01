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
            'gcashQrName' => ($p = Setting::gcashQrPayload()) ? (Setting::qrPhMerchantName($p) ?? 'your account') : null,
        ]);
    }

    /**
     * Save (or, blank, remove) the shop's GCash QR. The page decodes the
     * uploaded "My QR" image in the browser and posts the TEXT; the image is
     * never stored. Checked here as a real QR Ph code, CRC included.
     */
    public function updateGcashQr(Request $request)
    {
        $request->validate([
            'gcash_qr' => ['nullable', 'string', 'max:512'],
        ]);

        $payload = trim((string) $request->input('gcash_qr', ''));

        if ($payload === '') {
            Setting::put(Setting::GCASH_QR_KEY, null);
            AuditTrail::log('Updated', 'Removed the POS GCash QR');

            return $this->actionOk($request, 'GCash QR removed. The till shows the plain REMEDI code again.', redirect()->route('safeguard.edit'));
        }

        if (! Setting::isQrPhPayload($payload)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'gcash_qr' => 'That is not a GCash / QR Ph code. Upload the QR from GCash → My QR.',
            ]);
        }

        Setting::put(Setting::GCASH_QR_KEY, $payload);

        // Never the payload itself: the trail is readable by every admin.
        AuditTrail::log('Updated', 'Set the POS GCash QR');

        return $this->actionOk($request, 'GCash QR saved. The till shows it for GCash and Other QR.', redirect()->route('safeguard.edit'));
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

        return $this->actionOk($request, 'Void passcode updated.', redirect()->route('safeguard.edit'));
    }
}
