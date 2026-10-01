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
            'qrPaymentNumber' => Setting::qrPaymentNumber(),
        ]);
    }

    /**
     * The number the POS GCash / Other QR code encodes. Blank clears it, and
     * the till goes back to its reference-string QR.
     */
    public function updateQrNumber(Request $request)
    {
        $request->validate([
            'qr_payment_number' => ['nullable', 'string', 'max:20'],
        ]);

        $raw = trim((string) $request->input('qr_payment_number', ''));

        if ($raw === '') {
            Setting::put(Setting::QR_PAYMENT_NUMBER_KEY, null);
            AuditTrail::log('Updated', 'Cleared the POS QR payment number');

            return $this->actionOk($request, 'QR payment number removed.', redirect()->route('safeguard.edit'));
        }

        $number = Setting::normalisePhMobile($raw);

        if ($number === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'qr_payment_number' => 'Enter a Philippine mobile number, for example 09454553998.',
            ]);
        }

        Setting::put(Setting::QR_PAYMENT_NUMBER_KEY, $number);

        // The last four only: the trail is a log, not a directory.
        AuditTrail::log('Updated', 'Set the POS QR payment number (ending '.substr($number, -4).')');

        return $this->actionOk($request, 'QR payment number saved.', redirect()->route('safeguard.edit'));
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
