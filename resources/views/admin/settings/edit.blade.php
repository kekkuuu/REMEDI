@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="page-head">
    <div class="page-head-text">
        <h3>Settings</h3>
        <p>Store-wide configuration.</p>
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

    <form method="POST" action="{{ route('settings.void-passcode.update') }}"
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
@endsection
