<x-guest-layout>
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        Forgot your password? No problem. Just let us know your email address and we will email you a password reset link.
    </div>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <button type="submit" class="btn btn-primary">Email Password Reset Link</button>
    </form>
</x-guest-layout>
