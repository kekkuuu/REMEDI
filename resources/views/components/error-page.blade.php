{{--
    Shared frame for every HTTP error view (403/404/419/500/503).

    Laravel resolves resources/views/errors/{code}.blade.php automatically for
    a matching HTTP exception -- no route or registration needed -- and falls
    back to it in production (APP_DEBUG=false) where it would otherwise show
    the bare framework default this whole component exists to replace. Each
    of those five views is just this component with its own copy: same
    branded card as the login screen (layouts/guest.blade.php), so a broken
    link still looks like part of the app rather than a dead end.

    $code    : the HTTP status, shown as the big number ("404")
    $title   : one short line under it
    $message : a sentence explaining what happened, in plain language
    $primary : ['label' => ..., 'href' => ...] for the one action button.
               Omit entirely for a page with nothing to do next (503).
--}}
@props(['code', 'title', 'message', 'primary' => null])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} — {{ config('app.name', 'REMEDI') }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#10b981">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
            background: #f3f4f6;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .auth-card {
            background: #fff;
            border-radius: 14px;
            padding: 56px 60px;
            width: 100%;
            max-width: 560px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            text-align: center;
        }
        .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            color: #1e293b;
            display: block;
            line-height: 1;
        }
        .brand-name span { color: #10b981; }
        .brand-divider {
            height: 0.5px;
            background: #e2e8f0;
            margin: 30px 0;
        }
        .error-code {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 3.5rem;
            line-height: 1;
            color: #10b981;
            margin: 0 0 10px;
        }
        .error-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 10px;
        }
        .error-message {
            font-size: 1rem;
            color: #64748b;
            line-height: 1.6;
            margin: 0 auto;
            max-width: 40ch;
        }
        .btn {
            display: inline-block;
            margin-top: 28px;
            padding: 12px 28px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            background: #10b981;
            color: #fff;
        }
        .btn:hover { background: #059669; }
        @media (max-width: 600px) {
            .auth-card { padding: 36px 24px; }
            .brand-name { font-size: 2.1rem; }
            .error-code { font-size: 2.75rem; }
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="brand-wrap">
            <span class="brand-name">RE<span>ME</span>DI</span>
        </div>
        <div class="brand-divider"></div>

        <p class="error-code">{{ $code }}</p>
        <h1 class="error-title">{{ $title }}</h1>
        <p class="error-message">{{ $message }}</p>

        @if($primary)
            <a href="{{ $primary['href'] }}" class="btn">{{ $primary['label'] }}</a>
        @endif
    </div>
</body>
</html>
