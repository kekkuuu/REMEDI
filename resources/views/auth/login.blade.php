<x-guest-layout>
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <div style="margin:8px 0 14px; font-size:.85rem;">
            <a href="{{ route('password.request') }}">Forgot your password?</a>
        </div>

        <button type="submit" class="btn btn-primary">Log in</button>


    </form>
</x-guest-layout>
