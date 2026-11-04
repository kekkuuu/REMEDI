{{-- The HTML half of a new account's sign-in details. Same shape as
     password-reset-code.blade.php, and for the same delivery reasons: a complete
     document, inline styles only, no images, and one plain-word wordmark. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your REMEDI account is ready</title>
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
        An administrator created a REMEDI account for you. Use these details to sign in:
    </p>

    <div style="margin:0 0 20px; padding:16px; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; font-size:15px; line-height:1.7;">
        <div style="font-size:12px; color:#64748b;">Sign-in email</div>
        <div style="font-weight:600; margin-bottom:10px; word-break:break-all;">{{ $user->email }}</div>
        <div style="font-size:12px; color:#64748b;">Temporary password</div>
        <div style="font-family:Consolas,Menlo,monospace; font-weight:700; font-size:17px; color:#047857; word-break:break-all;">{{ $temporaryPassword }}</div>
    </div>

    <p style="margin:0 0 20px; font-size:14px; color:#475569; line-height:1.5;">
        You will be asked to choose your own password the first time you sign in.
        Sign in here: {{ $loginUrl }}
    </p>

    <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.5;">
        If you were not expecting this, tell your REMEDI administrator.
    </p>

    <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0 12px;">

    <p style="margin:0; font-size:12px; color:#94a3b8;">
        Sent by REMEDI because an account was created for you.
    </p>
</div>
</body>
</html>
