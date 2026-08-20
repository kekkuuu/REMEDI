<x-guest-layout>
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        This is a secure area of the application. Please confirm your password before continuing.
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autofocus>

        <button type="submit" class="btn btn-primary">Confirm</button>
    </form>
</x-guest-layout>
