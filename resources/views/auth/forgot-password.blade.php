<x-guest-layout>
    {{-- The layout itself already renders session('status') (the .status
         block) and $errors->any() (the .errors block) above the slot -- see
         layouts/guest.blade.php -- so this view adds neither a second time. --}}
    {{-- Deliberately describes BOTH paths without asking which one applies:
         the role is on the account, so the server already knows, and making
         someone pick would also tell a stranger which addresses are admin
         accounts. See PasswordResetRequestController::store(). --}}
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        Enter your email. Staff accounts are reset by an admin &mdash; you'll be notified and given a
        temporary password to sign in with. Admin accounts get a 6-digit code by text to the mobile
        number on the account.
    </div>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <button type="submit" class="btn btn-primary">Continue</button>
    </form>

    <div style="margin-top:14px; font-size:.85rem;">
        <a href="{{ route('login') }}">Back to sign in</a>
    </div>
</x-guest-layout>
