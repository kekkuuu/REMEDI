@extends('layouts.app')

@section('title', 'Add New User')

@section('content')
{{-- This is the admin "Add User" form, not public signup — hence the back
     link to the user list rather than to login. --}}
<div class="page-head">
    <a href="{{ route('users.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>Add New User</h3>
        <p>Create an account and assign it a role.</p>
    </div>
</div>

{{-- Same .form-card vocabulary as Add Product and Edit User. --}}
<div class="form-card">
    <div class="section-head">
        <h4>Account Details</h4>
    </div>

    {{-- data-confirm-strict: a stray click on the backdrop must not silently
         discard the account (and the password just typed into it) -- see
         the comment on the confirmModal mousedown handler in
         layouts/app.blade.php. Cancel and Escape both still work. --}}
    <form method="POST" action="{{ route('register') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-user-plus"
          data-confirm-title="Create this account?" data-confirm-body="A new account will be created with the role selected above."
          data-confirm-label="Create account" data-confirm-strict="true">
        @csrf

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-user" aria-hidden="true"></i></span>
                    <label for="name">Name</label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Enter full name" required autofocus>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-mail" aria-hidden="true"></i></span>
                    <label for="email">Email</label>
                </div>
                {{-- Fixed "@remedi.com" suffix -- every account created here is
                     a company address, and the script below refuses to let it
                     be typed or backspaced away; an admin can only edit the
                     local part in front of it. type="text", not "email":
                     setSelectionRange() throws on type="email" (that type
                     doesn't support the selection API at all), and the fixed
                     suffix can't be enforced without it. old('email') still
                     wins on a validation redisplay -- this is a front-end
                     enhancement only, so a no-JS submission (or the server
                     tests, which post directly) can still land any address;
                     see RegistrationEmailTest's "another domain works". --}}
                <input type="text" id="email" name="email" value="{{ old('email', '@remedi.com') }}"
                       inputmode="email" placeholder="e.g. jane@remedi.com" autocomplete="off" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-shield-lock" aria-hidden="true"></i></span>
                    <label for="role">Role</label>
                </div>
                <select id="role" name="role" required>
                    <option value="staff" {{ old('role') === 'staff' ? 'selected' : '' }}>Staff</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                </select>
            </div>

            {{-- Keeps Role alone on its row, so the two password fields pair up
                 on the next one. --}}
            <div class="form-field" aria-hidden="true"></div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    <label for="password">Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Enter password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
                {{-- Delegated in layouts/app.blade.php's document 'input' listener,
                     same pattern as .pw-toggle -- see the comment there. --}}
                <div class="pw-strength" data-pw-strength-for="password" hidden>
                    <div class="pw-strength-bar"><span></span></div>
                    <span class="pw-strength-label"></span>
                </div>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-lock-check" aria-hidden="true"></i></span>
                    <label for="password_confirmation">Confirm Password</label>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           placeholder="Confirm password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password_confirmation"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-user-plus" aria-hidden="true"></i> Create Account
            </button>
            <a href="{{ route('users.index') }}" class="btn btn-secondary btn-lg">
                <i class="ti ti-x" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </form>
</div>

<script>
    // Locks "@remedi.com" as a fixed suffix on the email field: an admin can
    // only ever edit the local part in front of it. Three layers, because no
    // single browser event covers every way the suffix could be reached:
    //
    //   1. keydown -- refuses Backspace/Delete before the DOM even changes,
    //      so the common case (typing, then backspacing too far) never even
    //      flickers.
    //   2. input -- paste, cut, drag-and-drop and IME composition all bypass
    //      keydown, so this re-asserts the suffix after ANY change rather
    //      than trying to enumerate every input method that could break it.
    //   3. click / keyup / select -- clamps the caret and any selection so
    //      neither can sit inside the suffix, which is what stops a click
    //      (or Home/End/arrow navigation) parking the caret at the very end
    //      and making a single Backspace look like it should be allowed.
    //
    // This is a front-end enhancement only -- the server never required a
    // remedi.com address (see RegistrationEmailTest's "another domain
    // works"), so a no-JS submission, or a test posting directly, can still
    // send any address. Locking the suffix here is about what an admin can
    // TYPE, not a new backend restriction.
    (function () {
        var SUFFIX = '@remedi.com';
        var email = document.getElementById('email');
        if (!email) return;

        // A validation redisplay can restore an address that predates this
        // lock, or one missing the suffix entirely (old('email') with no
        // default) -- either way, the field must never open without it.
        if (!email.value.endsWith(SUFFIX)) {
            email.value = email.value.split('@')[0] + SUFFIX;
        }

        function boundary() {
            return Math.max(0, email.value.length - SUFFIX.length);
        }

        function clampCaret() {
            var max = boundary();
            var start = email.selectionStart, end = email.selectionEnd;
            if (start > max || end > max) {
                email.setSelectionRange(Math.min(start, max), Math.min(end, max));
            }
        }

        email.addEventListener('keydown', function (e) {
            if (e.key !== 'Backspace' && e.key !== 'Delete') return;

            var start = email.selectionStart, end = email.selectionEnd;
            var max = boundary();

            if (start === end) {
                // Collapsed caret: Backspace removes the character before
                // it, Delete the one after it. Only the edit that would
                // actually reach the suffix needs refusing.
                if (e.key === 'Backspace' && start <= max) return;
                if (e.key === 'Delete' && start < max) return;
                e.preventDefault();
            } else if (end > max) {
                // A selection reaching into the suffix -- refuse rather than
                // letting the browser delete part of it (this is also what
                // stops Ctrl+A, Backspace from wiping the whole field).
                e.preventDefault();
            }
        });

        email.addEventListener('input', function () {
            if (email.value.endsWith(SUFFIX)) return;

            // boundary() reads the CURRENT (broken) value's length, which is
            // meaningless here -- an edit that wiped the suffix entirely
            // (e.g. a paste replacing the whole field) can leave a value
            // shorter than SUFFIX, making boundary() collapse to 0 and
            // yanking the caret to the front on every correction. Clamp
            // against the recovered local part's own length instead.
            var local = email.value.split('@')[0];
            var caret = Math.min(email.selectionStart, local.length);
            email.value = local + SUFFIX;
            email.setSelectionRange(caret, caret);
        });

        ['click', 'keyup', 'select'].forEach(function (evt) {
            email.addEventListener(evt, clampCaret);
        });

        // Deferred with setTimeout(0): a mouse click's own default action
        // places the caret at the click point, and that happens AFTER
        // 'focus' fires -- clamping synchronously here just gets overwritten
        // a moment later, every time the field is focused by clicking
        // rather than tabbing into it. This is also what lands a fresh
        // field's caret right before the "@" instead of at the very end.
        email.addEventListener('focus', function () {
            setTimeout(clampCaret, 0);
        });
    })();
</script>
@endsection
