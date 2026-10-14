@extends('layouts.app')

@section('title', 'Database backup')

@section('content')
{{-- BackupController. Reached from the audit trail's "Backup Database"
     button, behind the login-password pop-up (password.confirm) like the
     download itself. Two kinds of file, explained before either is offered:
     a full backup is everything needed to put the shop back AND everything
     sensitive, so it only leaves as an AES-256 zip; a sanitized copy is for
     testing and has the people taken out. --}}
<div class="page-head">
    <a href="{{ route('audit.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>Database Backup</h3>
        <p>Download a copy of REMEDI's data. Read how to handle it first.</p>
    </div>
</div>

<div class="backup-warning" role="note">
    <i class="ti ti-shield-lock" aria-hidden="true"></i>
    <div>
        <strong>A full backup holds personal and security data.</strong>
        It contains every account's name, email and phone number, the password and reset-code hashes,
        the audit trail, and every sale. Treat it like a key to the shop:
        <ul>
            <li>Keep it on an encrypted drive or a private cloud folder, never on a shared PC's desktop.</li>
            <li>Do not email it, send it in a chat, or attach it to a report.</li>
            <li>Send the backup password separately from the file, and delete copies you no longer need.</li>
            <li>For testing, a demo or a defence, use the <strong>sanitized test copy</strong> instead.</li>
        </ul>
    </div>
</div>

<div class="backup-grid">
    {{-- Full backup --}}
    <div class="form-card">
        <div class="section-head">
            <h4><i class="ti ti-file-zip" aria-hidden="true"></i>Full backup (encrypted)</h4>
        </div>

        <p class="backup-copy">
            Everything needed to restore REMEDI, as a <strong>.zip protected with AES-256</strong>.
            Open it with 7-Zip or WinRAR; the zip tool built into Windows cannot open AES-encrypted files.
            <strong>The password is not stored anywhere</strong>: lose it and the backup cannot be opened.
        </p>

        @if($canEncrypt)
            {{-- With a password saved in Safeguard the file is locked with it
                 and nothing needs typing; the fields below then only override
                 it for this one file. Without one they are required. --}}
            @if($savedPassword)
                <p class="backup-saved">
                    <i class="ti ti-lock" aria-hidden="true"></i>
                    <span>Locked with the <strong>backup password set in Safeguard</strong>@if($savedPasswordAt) (last changed {{ $savedPasswordAt->format('M j, Y') }})@endif.
                    <a href="{{ route('safeguard.edit') }}#backup-password">Change it in Safeguard</a></span>
                </p>
            @elseif($savedPasswordUnreadable)
                <p class="backup-unavailable">
                    <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                    <span>The backup password saved in Safeguard cannot be read on this server (it was saved under a different app key).
                    Type a password below, or <a href="{{ route('safeguard.edit') }}#backup-password">set it again in Safeguard</a>.</span>
                </p>
            @else
                <p class="backup-copy" style="margin-top:0;">
                    Tip: <a href="{{ route('safeguard.edit') }}#backup-password">set a backup password in Safeguard</a> so you don't type one every time.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.backup.download') }}" class="backup-form" data-no-skeleton autocomplete="off">
                @csrf
                <input type="hidden" name="type" value="full">

                @if($savedPassword)
                    <details class="backup-override" @if($errors->has('password')) open @endif>
                        <summary>Use a different password for this file</summary>
                @endif
                <div class="form-field">
                    <div class="form-field-head">
                        <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                        <label for="backup_password">{{ $savedPassword ? 'One-time password' : 'Backup password' }}</label>
                    </div>
                    <input type="password" id="backup_password" name="password" @unless($savedPassword) required @endunless minlength="{{ $minPassword }}" maxlength="128"
                           autocomplete="new-password" aria-describedby="backup_password_note">
                    <span class="form-field-note" id="backup_password_note">
                        <i class="ti ti-info-circle" aria-hidden="true"></i>
                        At least {{ $minPassword }} characters. Not your login password.
                    </span>
                    @error('password')<p class="err">{{ $message }}</p>@enderror
                </div>
                <div class="form-field" style="margin-top:14px;">
                    <div class="form-field-head">
                        <span class="form-chip"><i class="ti ti-key" aria-hidden="true"></i></span>
                        <label for="backup_password_confirmation">Type it again</label>
                    </div>
                    <input type="password" id="backup_password_confirmation" name="password_confirmation" @unless($savedPassword) required @endunless minlength="{{ $minPassword }}" maxlength="128"
                           autocomplete="new-password">
                </div>
                @if($savedPassword)
                    </details>
                @endif

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-download" aria-hidden="true"></i> <span>Download encrypted backup</span>
                    </button>
                </div>
            </form>
        @else
            <p class="backup-unavailable">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                This server's zip library cannot encrypt, so a full backup is not offered here.
                Take one from the database host instead, or use the sanitized copy.
            </p>
        @endif
    </div>

    {{-- Sanitized copy --}}
    <div class="form-card">
        <div class="section-head">
            <h4><i class="ti ti-file-database" aria-hidden="true"></i>Sanitized test copy</h4>
        </div>

        <p class="backup-copy">
            A plain <strong>.sql</strong> file for testing or showing the data, with the people taken out.
            Products, stock, sales and forecasts are kept as they are, so every report still adds up.
        </p>
        <ul class="backup-list">
            <li>Account names become “User 1”, “User 2”…, and emails become <code>user1@example.invalid</code>.</li>
            <li>Phone numbers and personal emails are removed. No account can be signed into.</li>
            <li>Void notes, stock-adjustment reasons and stock-report notes are removed.</li>
            <li>No rows for {{ implode(', ', array_map(fn ($t) => str_replace('_', ' ', $t), $emptyInCopy)) }}.</li>
        </ul>
        <p class="backup-copy" style="margin-top:0;">It cannot be used to restore the shop: the accounts in it are not real.</p>

        <form method="POST" action="{{ route('admin.backup.download') }}" class="backup-form" data-no-skeleton>
            @csrf
            <input type="hidden" name="type" value="sanitized">
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">
                    <i class="ti ti-download" aria-hidden="true"></i> <span>Download test copy</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    .backup-warning {
        display: flex; gap: 12px; align-items: flex-start;
        margin: 0 0 18px; padding: 16px 18px;
        border: 1px solid #fcd34d; border-radius: 12px;
        background: #fffbeb; color: #78350f; font-size: 13.5px; line-height: 1.55;
    }
    .backup-warning > i { font-size: 22px; color: #b45309; flex: none; margin-top: 1px; }
    .backup-warning ul { margin: 8px 0 0; padding-left: 18px; }
    .backup-warning li { margin: 2px 0; }
    .backup-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(360px, 100%), 1fr));
        gap: 18px; align-items: start;
    }
    .backup-copy { margin: 4px 0 14px; font-size: 13.5px; color: var(--ink-soft); line-height: 1.55; }
    .backup-list { margin: 0 0 12px; padding-left: 18px; font-size: 13px; color: var(--ink-soft); line-height: 1.55; }
    .backup-list code { font-size: 12px; }
    .backup-unavailable { display: flex; gap: 8px; font-size: 13.5px; color: #92400e; }
    .backup-unavailable a, .backup-saved a, .backup-copy a { color: inherit; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
    .backup-saved {
        display: flex; gap: 8px; align-items: flex-start;
        margin: 0 0 14px; padding: 10px 12px; border-radius: 10px;
        background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; font-size: 13.5px; line-height: 1.5;
    }
    .backup-saved > i { font-size: 18px; flex: none; margin-top: 1px; }
    .backup-override { margin: 0 0 4px; }
    .backup-override summary { cursor: pointer; font-size: 13px; color: var(--ink-soft); margin-bottom: 12px; }
    .section-head h4 i { margin-right: 8px; }
</style>

<script>
/* A download does not leave the page, so the button would sit disabled and
 * the typed password would stay in the box. Show that the file is being
 * built, then put both back: the password fields are emptied once the
 * request has gone, so it is not left behind on a screen at the counter. */
document.querySelectorAll('.backup-form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type=submit]');
        var label = btn && btn.querySelector('span');
        var original = label ? label.textContent : '';
        if (btn) btn.disabled = true;
        if (label) label.textContent = 'Preparing the file…';
        setTimeout(function () {
            form.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
        }, 0);
        setTimeout(function () {
            if (btn) btn.disabled = false;
            if (label) label.textContent = original;
        }, 6000);
    });
});
</script>
@endsection
