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

    {{-- A real resend, not a link back to the email form: the session already
         knows the account, so retyping the address was pure friction. Its own
         form rather than a second submit button inside the verify form, so
         the code field's `required` can't block it and so it still works with
         no JavaScript. --}}
    <form method="POST" action="{{ route('password.otp.resend') }}" id="resend-form"
          style="margin-top:14px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
        @csrf
        <button type="submit" id="resend-btn"
                style="background:none; border:0; padding:0; font:inherit; font-size:.85rem; color:#047857; cursor:pointer; text-decoration:underline;">
            Resend code
        </button>
        <a href="{{ route('login') }}" style="font-size:.85rem;">Back to sign in</a>
    </form>

    <script>
        /* A short cooldown so a double-click or an impatient tap can't spend
           two SMS credits. The REAL limit is server-side (5 per account per
           10 minutes, shared with the email form) -- this only stops the
           obvious waste, and the page works fine without it. */
        (function () {
            var form = document.getElementById('resend-form');
            var btn = document.getElementById('resend-btn');
            if (!form || !btn) return;

            var COOLDOWN = 30;
            var label = btn.textContent.trim();

            form.addEventListener('submit', function () {
                // sessionStorage, so the cooldown survives the redirect this
                // submit is about to cause -- the page that comes back is a
                // fresh document and a timer here would die with this one.
                try { sessionStorage.setItem('remedi.resendAt', String(Date.now())); } catch (e) {}
            });

            function tick() {
                var at = 0;
                try { at = parseInt(sessionStorage.getItem('remedi.resendAt') || '0', 10); } catch (e) {}
                if (!at) return;

                var left = COOLDOWN - Math.floor((Date.now() - at) / 1000);
                if (left <= 0) {
                    btn.disabled = false;
                    btn.style.opacity = '';
                    btn.style.cursor = 'pointer';
                    btn.textContent = label;
                    return;
                }

                btn.disabled = true;
                btn.style.opacity = '.55';
                btn.style.cursor = 'default';
                btn.textContent = label + ' (' + left + 's)';
                setTimeout(tick, 1000);
            }

            tick();
        })();
    </script>
</x-guest-layout>
