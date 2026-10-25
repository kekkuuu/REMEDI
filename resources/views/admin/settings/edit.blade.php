@extends('layouts.app')

@section('title', 'Safeguard')

@section('content')
<style>
    .safeguard-forgot { margin: 14px 0 0; }
    .safeguard-forgot-link {
        display: inline-flex; align-items: center; gap: 6px; padding: 0; border: 0; background: none;
        color: var(--brand-dark, #047857); font: inherit; font-size: 13.5px; cursor: pointer;
    }
    .safeguard-forgot-link:hover { text-decoration: underline; }
</style>
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

        {{-- Replacing a code asks for the current one first (2026-10-25). --}}
        @if($voidPasscodeSet)
            <div class="form-grid" style="margin-bottom:16px;">
                <div class="form-field">
                    <div class="form-field-head">
                        <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                        <label for="current_passcode">Current passcode</label>
                    </div>
                    <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           id="current_passcode" name="current_passcode" placeholder="6 digits" autocomplete="off" required>
                    @error('current_passcode')<p class="err">{{ $message }}</p>@enderror
                    <span class="form-field-note">
                        <i class="ti ti-info-circle" aria-hidden="true"></i>
                        Forgot it? Use "Email me a code" below and type that code here instead.
                    </span>
                </div>
            </div>
        @endif

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

    {{-- "Forgot the current one?" -- outside the form above (forms cannot nest).
         Emails a one-time code to the admin's personal address that the
         Current box accepts once (SettingsController::sendForgotCode). --}}
    @if($voidPasscodeSet)
        <form method="POST" action="{{ route('safeguard.forgot', 'passcode') }}" class="js-confirm safeguard-forgot"
              data-confirm-tone="neutral" data-confirm-icon="ti-mail"
              data-confirm-title="Email a code for the POS void passcode?"
              data-confirm-body="A 6-digit code goes to your personal email. Type it in the Current box within {{ $forgotTtl }} minutes to set a new POS void passcode."
              data-confirm-label="Email me a code" data-on-success="none">
            @csrf
            <button type="submit" class="safeguard-forgot-link">
                <i class="ti ti-mail" aria-hidden="true"></i> Forgot the current POS void passcode? Email me a code
            </button>
        </form>
    @endif
</div>

{{-- Backup password (2026-10-15, at the user's request). Every full backup
     (Audit trail → Backup Database) asks for THIS password, refuses any other,
     and locks the zip with it. Stored HASHED like the void passcode -- the
     server only ever checks it -- see Setting::BACKUP_PASSWORD_KEY. --}}
<div class="form-card" style="margin-top:20px;" id="backup-password">
    <div class="section-head">
        <h4>Backup Password</h4>
    </div>

    <p style="margin:0 0 16px; font-size:13.5px; color:var(--ink-soft);">
        Every full database backup asks for this password each time it is downloaded, and the .zip is
        locked with it (open it with 7-Zip or WinRAR). A full backup cannot be downloaded until one is set.
        Changing it does not affect backups already downloaded &mdash; each one keeps the password it was
        made with, so keep a note of old passwords for as long as you keep old backups.
    </p>

    <p style="margin:0 0 20px; font-size:13.5px;">
        @if($backupPasswordSet)
            <span class="badge badge-success"><i class="ti ti-lock" aria-hidden="true"></i> A backup password is set</span>
            @if($backupPasswordSetAt) &mdash; last changed {{ $backupPasswordSetAt->format('M j, Y g:i A') }}. @endif
        @else
            <span class="badge badge-warning"><i class="ti ti-lock-open" aria-hidden="true"></i> No backup password set</span>
            &mdash; full backups are unavailable until one is.
        @endif
    </p>

    <form method="POST" action="{{ route('safeguard.backup-password.update') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-file-zip"
          data-confirm-title="{{ $backupPasswordSet ? 'Change the backup password?' : 'Set the backup password?' }}"
          data-confirm-body="{{ $backupPasswordSet ? 'New backups will ask for, and be locked with, the new password. Backups already downloaded still open only with the old one.' : 'Every full backup will ask for this password and be locked with it.' }}"
          data-confirm-label="{{ $backupPasswordSet ? 'Change password' : 'Set password' }}">
        @csrf
        @method('PUT')

        @if($backupPasswordSet)
            <div class="form-grid" style="margin-bottom:16px;">
                <div class="form-field">
                    <div class="form-field-head">
                        <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                        <label for="current_backup_password">Current backup password</label>
                    </div>
                    <input type="password" id="current_backup_password" name="current_backup_password" maxlength="128"
                           autocomplete="current-password" required>
                    @error('current_backup_password')<p class="err">{{ $message }}</p>@enderror
                    <span class="form-field-note">
                        <i class="ti ti-info-circle" aria-hidden="true"></i>
                        Forgot it? Use "Email me a code" below and type that code here instead.
                    </span>
                </div>
            </div>
        @endif

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

    {{-- "Forgot the current one?" -- outside the form above (forms cannot nest).
         Emails a one-time code to the admin's personal address that the
         Current box accepts once (SettingsController::sendForgotCode). --}}
    @if($backupPasswordSet)
        <form method="POST" action="{{ route('safeguard.forgot', 'backup') }}" class="js-confirm safeguard-forgot"
              data-confirm-tone="neutral" data-confirm-icon="ti-mail"
              data-confirm-title="Email a code for the backup password?"
              data-confirm-body="A 6-digit code goes to your personal email. Type it in the Current box within {{ $forgotTtl }} minutes to set a new backup password."
              data-confirm-label="Email me a code" data-on-success="none">
            @csrf
            <button type="submit" class="safeguard-forgot-link">
                <i class="ti ti-mail" aria-hidden="true"></i> Forgot the current backup password? Email me a code
            </button>
        </form>
    @endif
</div>
@endsection
