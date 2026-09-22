@extends('layouts.app')

@section('title', 'Add New User')

@section('content')
{{-- Same "details card" look as My Profile's Account Details card
     (profile/edit.blade.php) -- section-title + a two-column field grid +
     a bottom-aligned actions row. Class names are copied verbatim from that
     page's own <style> block so the two match exactly; each page's styles
     are scoped to itself, so reusing the names is safe. --}}
<style>
    .profile-head {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .profile-head-mark {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: var(--brand-tint);
        border: 1px solid var(--brand-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        color: var(--brand);
        flex-shrink: 0;
    }

    .profile-head h3 {
        margin: 0;
        font-family: 'Outfit', sans-serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ink);
    }

    .profile-head p { margin: 3px 0 0; font-size: 13.5px; color: var(--ink-soft); }

    .profile-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px 20px;
    }

    @media (max-width: 640px) {
        .profile-form-grid { grid-template-columns: 1fr; }
    }

    .field label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 6px;
    }

    .field input,
    .field select { width: 100%; }

    .field .err { margin: 5px 0 0; font-size: 13px; color: #991b1b; }

    .profile-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid var(--line);
    }

    .section-title {
        font-family: 'Outfit', sans-serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--ink);
        margin: 0 0 18px;
    }
</style>

<div class="profile-head">
    <a href="{{ route('users.index') }}" class="btn-back" style="margin-right:4px;"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <span class="profile-head-mark" aria-hidden="true"><i class="ti ti-user-plus"></i></span>
    <div>
        <h3>Add New User</h3>
        <p>Create an account and assign it a role.</p>
    </div>
</div>

<div class="card">
    <p class="section-title">Account Details</p>

    {{-- data-confirm-strict: a stray click on the backdrop must not
         silently discard the account (and the password just typed into
         it) -- see the comment on the confirmModal mousedown handler in
         layouts/app.blade.php. Cancel and Escape both still work. --}}
    <form method="POST" action="{{ route('register') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-user-plus"
          data-confirm-title="Create this account?" data-confirm-body="A new account will be created with the role selected above."
          data-confirm-label="Create account" data-confirm-strict="true">
        @csrf

        <div class="profile-form-grid">
            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Enter full name" required autofocus>
                @error('name')<p class="err">{{ $message }}</p>@enderror
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
            <div class="field">
                <label for="email">Email</label>
                <input type="text" id="email" name="email" value="{{ old('email', '@remedi.com') }}"
                       inputmode="email" placeholder="e.g. jane@remedi.com" autocomplete="off" required>
                @error('email')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="staff" {{ old('role') === 'staff' ? 'selected' : '' }}>Staff</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                </select>
                @error('role')<p class="err">{{ $message }}</p>@enderror
            </div>

            {{-- Optional, same rule as ProfileUpdateRequest's own phone field
                 (nullable, max:40) -- not every account is created with a
                 number in hand. --}}
            <div class="field">
                <label for="phone">Phone Number <span style="font-weight:400; color:#94a3b8;">(optional)</span></label>
                <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
                       placeholder="+63 912 345 6789">
                @error('phone')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Enter password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
                {{-- Delegated in layouts/app.blade.php's document 'input'
                     listener, same pattern as .pw-toggle -- see the
                     comment there. --}}
                <div class="pw-strength" data-pw-strength-for="password" hidden>
                    <div class="pw-strength-bar"><span></span></div>
                    <span class="pw-strength-label"></span>
                </div>
                @error('password')<p class="err">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           placeholder="Confirm password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="password_confirmation"
                            aria-label="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>

        <div class="profile-actions">
            <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="ti ti-user-plus" aria-hidden="true"></i> Create Account
            </button>
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
