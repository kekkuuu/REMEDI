{{-- Plain inline-styled HTML on purpose: email clients strip <style> blocks
     and know nothing about the app's stylesheet, so anything not inline is
     simply lost. Kept deliberately small -- a reset code needs the code, the
     expiry, and what to do if it was not you. --}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; max-width:480px; margin:0 auto; padding:24px; color:#0f172a;">

    <p style="margin:0 0 4px; font-size:20px; font-weight:700; letter-spacing:.06em;">
        RE<span style="color:#10b981;">ME</span>DI
    </p>
    <p style="margin:0 0 24px; font-size:12px; color:#64748b; letter-spacing:.12em; text-transform:uppercase;">
        Inventory &amp; Sales Management
    </p>

    <p style="margin:0 0 16px; font-size:15px;">
        Hello {{ $user->name }},
    </p>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.5;">
        Someone asked to reset the password for the REMEDI account
        <strong>{{ $user->email }}</strong>. Enter this code to continue:
    </p>

    <p style="margin:0 0 20px; padding:16px; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; text-align:center; font-size:30px; font-weight:700; letter-spacing:.32em; color:#047857;">
        {{ $code }}
    </p>

    <p style="margin:0 0 20px; font-size:14px; color:#475569; line-height:1.5;">
        It expires in {{ $expiresInMinutes }} minutes and can only be used once.
    </p>

    <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.5;">
        If you didn't ask for this, you can ignore this email &mdash; your
        password has not changed. Nobody can use the code without also knowing
        your email address, but if you keep receiving these, tell an
        administrator.
    </p>

    <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0 12px;">

    <p style="margin:0; font-size:12px; color:#94a3b8;">
        This is an automated message from REMEDI. Please don't reply to it.
    </p>
</div>
