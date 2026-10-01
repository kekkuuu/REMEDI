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

{{-- QR payment number (2026-10-01): what the POS GCash / Other QR code
     encodes. Here rather than in the code because the repository is public. --}}
<div class="form-card" style="margin-top:20px;">
    <div class="section-head">
        <h4>POS QR Payment Number</h4>
    </div>

    <p style="margin:0 0 16px; font-size:13.5px; color:var(--ink-soft);">
        The mobile number the QR code shows at the till when the customer pays with GCash or Other QR.
        Leave it empty to go back to the plain reference code.
    </p>

    <p style="margin:0 0 20px; font-size:13.5px;">
        @if($qrPaymentNumber)
            <span class="badge badge-success"><i class="ti ti-qrcode" aria-hidden="true"></i> The QR shows {{ $qrPaymentNumber }}</span>
        @else
            <span class="badge badge-warning"><i class="ti ti-qrcode" aria-hidden="true"></i> No number set</span>
            &mdash; the QR shows a reference code instead.
        @endif
    </p>

    <form method="POST" action="{{ route('safeguard.qr-number.update') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-qrcode"
          data-confirm-title="Save the QR payment number?"
          data-confirm-body="The GCash / Other QR code at the till will show this number from the next sale."
          data-confirm-label="Save number">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-device-mobile" aria-hidden="true"></i></span>
                    <label for="qr_payment_number">Mobile number</label>
                </div>
                <input type="tel" inputmode="tel" maxlength="20" id="qr_payment_number" name="qr_payment_number"
                       value="{{ old('qr_payment_number', $qrPaymentNumber) }}" placeholder="09XXXXXXXXX" autocomplete="off">
                @error('qr_payment_number')<p class="err">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-device-floppy" aria-hidden="true"></i> Save Number
            </button>
        </div>
    </form>
</div>
@endsection
