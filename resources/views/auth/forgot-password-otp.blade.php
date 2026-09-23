<x-guest-layout>
    {{-- The layout renders session('status') and $errors itself (see
         layouts/guest.blade.php), so neither is repeated here. --}}
    <div style="margin-bottom:12px; font-size:.9rem; color:#6b7280;">
        Enter the 6-digit code sent to <strong>{{ $maskedPhone }}</strong>.
        It expires in {{ \App\Models\User::OTP_TTL_MINUTES }} minutes.
    </div>

    @unless($smsDelivers)
        {{-- No gateway is wired in yet (config/sms.php), so the code went to
             the log rather than a handset. Saying so is the difference
             between a flow that looks broken and one that is simply not
             connected yet -- and it disappears on its own the moment
             SMS_DRIVER is set, because SmsService::delivers() decides it. --}}
        <div style="margin-bottom:12px; padding:10px; border:1px dashed #f59e0b; border-radius:8px; background:#fffbeb; font-size:.82rem; color:#92400e;">
            No SMS gateway is connected yet, so no text was actually sent.
            The code is in <code>storage/logs/laravel.log</code>.
        </div>
    @endunless

    <form method="POST" action="{{ route('password.otp.verify') }}">
        @csrf

        <label for="code">6-digit code</label>
        <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*"
               maxlength="6" autocomplete="one-time-code" required autofocus
               style="letter-spacing:.4em; text-align:center; font-size:1.2rem;">

        <button type="submit" class="btn btn-primary">Verify code</button>
    </form>

    <div style="margin-top:14px; font-size:.85rem; display:flex; justify-content:space-between; gap:10px;">
        <a href="{{ route('password.request') }}">Send a new code</a>
        <a href="{{ route('login') }}">Back to sign in</a>
    </div>
</x-guest-layout>
