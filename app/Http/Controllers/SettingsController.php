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
            'backupPasswordSet' => Setting::backupPasswordIsSet(),
            'backupPasswordSetAt' => Setting::backupPasswordSetAt(),
            'backupPasswordMin' => BackupController::MIN_PASSWORD_LENGTH,
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

        return $this->actionOk($request, 'Void passcode updated.', redirect()->route('safeguard.edit'));
    }

    /**
     * Set or replace the password every full backup asks for and is locked
     * with -- see Setting::BACKUP_PASSWORD_KEY and BackupController::download().
     * Changing it does not touch backups already downloaded: each zip keeps
     * the password it was made with. Never written to the audit trail.
     */
    public function updateBackupPassword(Request $request)
    {
        $validated = $request->validate([
            'backup_password' => ['bail', 'required', 'string', 'min:'.BackupController::MIN_PASSWORD_LENGTH, 'max:128', 'confirmed'],
        ], [
            'backup_password.required' => 'Enter the backup password.',
            'backup_password.min' => 'Use at least :min characters for the backup password.',
            'backup_password.confirmed' => 'The two backup passwords do not match.',
        ]);

        $replacing = Setting::backupPasswordIsSet();
        Setting::setBackupPassword($validated['backup_password']);

        AuditTrail::log('Updated', $replacing ? 'Changed the backup password' : 'Set the backup password');

        return $this->actionOk($request, 'Backup password saved. Every full backup now asks for it and is locked with it.', redirect()->route('safeguard.edit'));
    }
}
