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
                {{-- Defaults to "@remedi.com" so an admin only has to type the
                     local part -- every account created here is a company
                     address. old('email') still wins on a validation
                     redisplay, so a real address someone typed is never
                     replaced back to the default. The caret is moved to
                     BEFORE the "@" by the script below, or typing would land
                     after ".com" instead of in front of it. --}}
                <input type="email" id="email" name="email" value="{{ old('email', '@remedi.com') }}"
                       placeholder="e.g. jane@remedi.com" autocomplete="off" required>
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
    // The email field defaults to "@remedi.com" (see the input above); left
    // alone, the browser puts the caret at the very end of that default value,
    // so the first keystroke would land after ".com" rather than in front of
    // the "@". Reposition it once, on first focus, and only while the value
    // is still exactly the untouched default -- an address already typed (or
    // restored via old() after a validation failure) keeps the caret wherever
    // the browser put it.
    //
    // Deferred with setTimeout(0): a mouse click's own default action places
    // the caret at the click point, and that happens AFTER the 'focus' event
    // fires -- setting the range synchronously here just got overwritten a
    // moment later, every time the field was focused by clicking rather than
    // tabbing into it. Queuing the reposition as its own task runs it after
    // the click has already done its thing.
    //
    // setSelectionRange() throws InvalidStateError on type="email" -- the
    // spec only allows it on types whose value is plain text (text, search,
    // url, tel, password). Switching to "text" for the duration of the call
    // is the standard workaround; switching back afterward costs nothing,
    // since nothing else reads the type in between.
    (function () {
        var email = document.getElementById('email');
        if (!email) return;

        email.addEventListener('focus', function onFirstFocus() {
            setTimeout(function () {
                if (email.value === '@remedi.com') {
                    email.type = 'text';
                    email.setSelectionRange(0, 0);
                    email.type = 'email';
                }
            }, 0);
            email.removeEventListener('focus', onFirstFocus);
        });
    })();
</script>
@endsection
