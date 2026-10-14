@extends('layouts.app')

@section('title', 'Safeguard')

@section('content')
<div class="page-head">
    <div class="page-head-text">
        <h3>Safeguard</h3>
        <p>Store-wide security controls. This page asks for your login password before it opens.</p>
    </div>
</div>

<div class="form-card">
    <div class="section-head">
        <h4>POS Void Passcode</h4>
    </div>

    <p style="margin:0 0 16px; font-size:13.5px; color:var(--ink-soft);">
        A 6-digit code staff enter to void a sale they rang up (see the "Void Transaction" button on
        a sale's own page). An admin never needs it -- this is only checked for a non-admin account.
        Setting a new code replaces the old one immediately.
    </p>

    <p style="margin:0 0 20px; font-size:13.5px;">
        @if($voidPasscodeSet)
            <span class="badge badge-success"><i class="ti ti-lock" aria-hidden="true"></i> A passcode is set</span>
        @else
            <span class="badge badge-warning"><i class="ti ti-lock-open" aria-hidden="true"></i> No passcode set yet</span>
            &mdash; staff cannot void a sale until one is.
        @endif
    </p>

    <form method="POST" action="{{ route('safeguard.void-passcode.update') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-lock"
          data-confirm-title="{{ $voidPasscodeSet ? 'Replace the void passcode?' : 'Set the void passcode?' }}"
          data-confirm-body="{{ $voidPasscodeSet ? 'Staff currently using the old code will need the new one.' : 'Staff will be able to void a sale they rang up once this is set.' }}"
          data-confirm-label="{{ $voidPasscodeSet ? 'Replace passcode' : 'Set passcode' }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                    <label for="passcode">New passcode</label>
                </div>
                <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       id="passcode" name="passcode" placeholder="6 digits" autocomplete="off" required>
                @error('passcode')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                    <label for="passcode_confirmation">Confirm new passcode</label>
                </div>
                <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       id="passcode_confirmation" name="passcode_confirmation" placeholder="6 digits" autocomplete="off" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-device-floppy" aria-hidden="true"></i> {{ $voidPasscodeSet ? 'Replace Passcode' : 'Set Passcode' }}
            </button>
        </div>
    </form>
</div>

{{-- GCash QR for the till (2026-10-01, at the user's request). The uploaded
     "My QR" image is decoded HERE, in the browser, and only the decoded text
     is posted and stored -- see SettingsController::updateGcashQr(). In the
     database rather than the code because the repository is public. --}}
<div class="form-card" style="margin-top:20px;">
    <div class="section-head">
        <h4>GCash QR for the Till</h4>
    </div>

    <p style="margin:0 0 16px; font-size:13.5px; color:var(--ink-soft);">
        Customers paying with GCash or Other QR scan this at the POS. In the GCash app open
        <strong>QR &rarr; My QR</strong>, take a screenshot or tap Download, and upload it here.
        Only the code inside the picture is saved, not the picture.
    </p>

    <p style="margin:0 0 20px; font-size:13.5px;">
        @if($gcashQrName)
            <span class="badge badge-success"><i class="ti ti-qrcode" aria-hidden="true"></i> A GCash QR is saved &middot; {{ $gcashQrName }}</span>
        @else
            <span class="badge badge-warning"><i class="ti ti-qrcode" aria-hidden="true"></i> No GCash QR saved</span>
            &mdash; the till shows a plain REMEDI code, which payment apps cannot pay.
        @endif
    </p>

    <form method="POST" action="{{ route('safeguard.gcash-qr.update') }}" id="gcashQrForm"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-qrcode"
          data-confirm-title="Save this GCash QR?"
          data-confirm-body="The GCash / Other QR at the till will show this code from the next sale."
          data-confirm-label="Save QR">
        @csrf
        @method('PUT')
        <input type="hidden" name="gcash_qr" id="gcashQrPayload" value="">

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-photo" aria-hidden="true"></i></span>
                    <label for="gcashQrFile">GCash "My QR" picture</label>
                </div>
                <input type="file" id="gcashQrFile" accept="image/*">
                <p id="gcashQrRead" style="margin:6px 0 0; font-size:13px; min-height:1.2em;"></p>
                @error('gcash_qr')<p class="err">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg" id="gcashQrSave" disabled>
                <i class="ti ti-device-floppy" aria-hidden="true"></i> Save QR
            </button>
        </div>
    </form>

    @if($gcashQrName)
        <form method="POST" action="{{ route('safeguard.gcash-qr.update') }}" style="margin-top:10px;"
              class="js-confirm" data-confirm-icon="ti-trash"
              data-confirm-title="Remove the GCash QR?"
              data-confirm-body="The till goes back to the plain REMEDI code, which payment apps cannot pay."
              data-confirm-label="Remove QR">
            @csrf
            @method('PUT')
            <input type="hidden" name="gcash_qr" value="">
            <button type="submit" class="btn btn-clear"><i class="ti ti-trash" aria-hidden="true"></i> Remove QR</button>
        </form>
    @endif
</div>

{{-- Backup password (2026-10-15, at the user's request). Every full backup
     (Audit trail → Backup Database) is an AES-256 zip locked with this, so it
     is set once here instead of typed for every download. Stored ENCRYPTED
     with the app key, not hashed -- see Setting::BACKUP_PASSWORD_KEY. --}}
<div class="form-card" style="margin-top:20px;" id="backup-password">
    <div class="section-head">
        <h4>Backup Password</h4>
    </div>

    <p style="margin:0 0 16px; font-size:13.5px; color:var(--ink-soft);">
        Every full database backup is a .zip locked with this password (open it with 7-Zip or WinRAR).
        Set it once here and the backup page uses it automatically. Changing it does not affect backups
        already downloaded &mdash; each one keeps the password it was made with, so keep a note of old
        passwords for as long as you keep old backups. It is never shown again after saving.
    </p>

    <p style="margin:0 0 20px; font-size:13.5px;">
        @if($backupPasswordSet && $backupPasswordReadable)
            <span class="badge badge-success"><i class="ti ti-lock" aria-hidden="true"></i> A backup password is set</span>
            @if($backupPasswordSetAt) &mdash; last changed {{ $backupPasswordSetAt->format('M j, Y g:i A') }}. @endif
        @elseif($backupPasswordSet)
            <span class="badge badge-warning"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Saved password cannot be read here</span>
            &mdash; it was saved on a server with a different app key. Set it again below.
        @else
            <span class="badge badge-warning"><i class="ti ti-lock-open" aria-hidden="true"></i> No backup password set</span>
            &mdash; a full backup asks for a password each time until one is.
        @endif
    </p>

    <form method="POST" action="{{ route('safeguard.backup-password.update') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-file-zip"
          data-confirm-title="{{ $backupPasswordSet ? 'Change the backup password?' : 'Set the backup password?' }}"
          data-confirm-body="{{ $backupPasswordSet ? 'New backups will be locked with the new password. Backups already downloaded still open only with the old one.' : 'Every full backup from now on will be locked with this password.' }}"
          data-confirm-label="{{ $backupPasswordSet ? 'Change password' : 'Set password' }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                    <label for="backup_password">{{ $backupPasswordSet ? 'New backup password' : 'Backup password' }}</label>
                </div>
                <input type="password" id="backup_password" name="backup_password" minlength="{{ $backupPasswordMin }}" maxlength="128"
                       autocomplete="new-password" required>
                <span class="form-field-note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    At least {{ $backupPasswordMin }} characters. Not your login password.
                </span>
                @error('backup_password')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                    <label for="backup_password_confirmation">Type it again</label>
                </div>
                <input type="password" id="backup_password_confirmation" name="backup_password_confirmation" minlength="{{ $backupPasswordMin }}" maxlength="128"
                       autocomplete="new-password" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-device-floppy" aria-hidden="true"></i> {{ $backupPasswordSet ? 'Change Password' : 'Set Password' }}
            </button>
        </div>
    </form>

    @if($backupPasswordSet)
        <form method="POST" action="{{ route('safeguard.backup-password.update') }}" style="margin-top:10px;"
              class="js-confirm" data-confirm-icon="ti-trash"
              data-confirm-title="Remove the backup password?"
              data-confirm-body="A full backup will ask for a password each time it is downloaded."
              data-confirm-label="Remove password">
            @csrf
            @method('PUT')
            <input type="hidden" name="remove" value="1">
            <button type="submit" class="btn btn-clear"><i class="ti ti-trash" aria-hidden="true"></i> Remove Password</button>
        </form>
    @endif
</div>

{{-- The same reader the POS camera scanner uses (ZXing C++ in WebAssembly). --}}
<script src="https://cdn.jsdelivr.net/npm/barcode-detector@3.2.2/dist/iife/ponyfill.js"></script>
<script>
(function () {
    const file = document.getElementById('gcashQrFile');
    if (!file) return;
    const out = document.getElementById('gcashQrRead');
    const payloadField = document.getElementById('gcashQrPayload');
    const save = document.getElementById('gcashQrSave');

    function say(text, ok) {
        out.textContent = text;
        out.style.color = ok ? '#15803d' : '#b91c1c';
    }

    file.addEventListener('change', async function () {
        payloadField.value = '';
        save.disabled = true;
        const f = file.files && file.files[0];
        if (!f) { out.textContent = ''; return; }

        say('Reading the QR…', true);
        try {
            const Detector = ('BarcodeDetector' in window) ? window.BarcodeDetector
                : (window.BarcodeDetectionAPI && window.BarcodeDetectionAPI.BarcodeDetector);
            if (!Detector) throw new Error('no reader');
            const bitmap = await createImageBitmap(f);
            const codes = await new Detector({ formats: ['qr_code'] }).detect(bitmap);
            const text = codes.length ? codes[0].rawValue : '';

            // A QR Ph code starts with its format indicator. The server
            // checks the rest, CRC included.
            if (!text.startsWith('000201')) {
                say(text ? 'That QR is not a GCash / QR Ph payment code.' : 'No QR code found in that picture. Try a clearer screenshot.', false);
                return;
            }
            // The account name is tag 59; walk the tag-length-value fields
            // rather than searching, since "59" can appear inside others.
            let name = '';
            for (let i = 0; i + 4 <= text.length;) {
                const id = text.substr(i, 2), len = parseInt(text.substr(i + 2, 2), 10);
                if (isNaN(len)) break;
                if (id === '59') { name = text.substr(i + 4, len); break; }
                i += 4 + len;
            }
            payloadField.value = text;
            save.disabled = false;
            say('✓ GCash QR read' + (name ? ' · ' + name : '') + '. Click Save QR.', true);
        } catch (e) {
            say('Could not read that picture. Check the internet connection, then try again.', false);
        }
    });
})();
</script>
@endsection
