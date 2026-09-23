<x-guest-layout>
    {{-- The layout renders session('status') and $errors itself (see
         layouts/guest.blade.php), so neither is repeated here. --}}
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        Code verified. Choose a new password for your account.
    </div>

    <form method="POST" action="{{ route('password.otp.update') }}">
        @csrf

        <label for="password">New password</label>
        <input id="password" type="password" name="password" required autofocus
               autocomplete="new-password" minlength="8">

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" type="password" name="password_confirmation"
               required autocomplete="new-password" minlength="8">

        {{-- No must_change_password lock follows this one: the person picked
             this password themselves, unlike the admin-issued default that
             UserController::resetPassword() hands out. --}}
        <button type="submit" class="btn btn-primary">Set new password</button>
    </form>

    <div style="margin-top:14px; font-size:.85rem;">
        <a href="{{ route('login') }}">Back to sign in</a>
    </div>
</x-guest-layout>
