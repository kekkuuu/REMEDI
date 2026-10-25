{{-- The HTML half of the Safeguard code email; the plain-text half is
     safeguard-code-text.blade.php. Same rules as password-reset-code.blade.php:
     a complete document, inline styles, no images or links, one-word wordmark. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your REMEDI Safeguard code</title>
</head>
<body style="margin:0; padding:0; background:#f8fafc;">
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; max-width:480px; margin:0 auto; padding:24px; color:#0f172a; background:#ffffff;">

    <p style="margin:0 0 4px; font-size:20px; font-weight:700; letter-spacing:.06em; color:#047857;">REMEDI</p>
    <p style="margin:0 0 24px; font-size:12px; color:#64748b; letter-spacing:.12em; text-transform:uppercase;">
        Inventory &amp; Sales Management
    </p>

    <p style="margin:0 0 16px; font-size:15px;">
        Hello {{ $user->name }},
    </p>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.5;">
        You asked to replace the {{ $what }} on the Safeguard page without the current one.
        Enter this code in the "Current" box instead:
    </p>

    <p style="margin:0 0 20px; padding:16px; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; text-align:center; font-size:30px; font-weight:700; letter-spacing:.32em; color:#047857;">
        {{ $code }}
    </p>

    <p style="margin:0 0 20px; font-size:14px; color:#475569; line-height:1.5;">
        The code works for {{ $expiresInMinutes }} minutes and only once.
    </p>

    <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.5;">
        If you did not ask for this, you can ignore this email; nothing has changed.
        If it keeps happening, tell your REMEDI administrator.
    </p>

    <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0 12px;">

    <p style="margin:0; font-size:12px; color:#94a3b8;">
        Sent by REMEDI because a Safeguard code was requested for this account.
    </p>
</div>
</body>
</html>
