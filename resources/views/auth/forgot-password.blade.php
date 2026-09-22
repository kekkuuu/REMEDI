<x-guest-layout>
    {{-- The layout itself already renders session('status') (the .status
         block) and $errors->any() (the .errors block) above the slot -- see
         layouts/guest.blade.php -- so this view adds neither a second time. --}}
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        Forgot your password? Enter your email and an admin will be notified to reset it for you.
        You'll be given the default password to sign in with, and asked to set a new one right away.
    </div>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <button type="submit" class="btn btn-primary">Notify an admin</button>
    </form>

    <div style="margin-top:14px; font-size:.85rem;">
        <a href="{{ route('login') }}">Back to sign in</a>
    </div>
</x-guest-layout>
