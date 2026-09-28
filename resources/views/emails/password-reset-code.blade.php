{{-- The HTML half of the reset-code email. A plain-text half sits beside it
     (password-reset-code-text.blade.php), and the pair matters for delivery:
     an HTML-only message with a one-time code in it is a classic spam
     signal, and this one was landing in Gmail's spam folder (2026-09-28).

     A complete document (doctype, charset, title) rather than a bare <div>,
     inline styles only (email clients strip <style> blocks), no images, no
     links, and no "do not reply" -- all things spam filters score. The
     account's own address is NOT printed: it sits on a domain unrelated to
     the Gmail sender, and a body naming another domain reads as phishing.
     The wordmark is ONE word in one colour: "RE<span>ME</span>DI" splits a
     word across tags, which is how spam hides words from filters, so filters
     score it (still landing in spam after the text part was added). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your REMEDI password reset code</title>
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
        You asked to reset your REMEDI password. Enter this code on the reset
        screen to continue:
    </p>

    <p style="margin:0 0 20px; padding:16px; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; text-align:center; font-size:30px; font-weight:700; letter-spacing:.32em; color:#047857;">
        {{ $code }}
    </p>

    <p style="margin:0 0 20px; font-size:14px; color:#475569; line-height:1.5;">
        The code works for {{ $expiresInMinutes }} minutes and only once.
    </p>

    <p style="margin:0 0 8px; font-size:13px; color:#64748b; line-height:1.5;">
        If you did not ask for this, you can ignore this email; your password
        has not changed. If it keeps happening, tell your REMEDI administrator.
    </p>

    <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0 12px;">

    <p style="margin:0; font-size:12px; color:#94a3b8;">
        Sent by REMEDI because a password reset was requested for this account.
    </p>
</div>
</body>
</html>
