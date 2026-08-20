<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'REMEDI') }}</title>

    {{-- Same tab identity as layouts/app: the login screen is the first page
         anyone sees, so it must not be the one page still showing a blank
         globe. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- manifest.json, not site.webmanifest: Apache under XAMPP has no MIME
         type registered for .webmanifest and served it with none at all, which
         is enough for Chrome to refuse the manifest and drop installability.
         .json it already knows. --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
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
        }
        .auth-card {
            background: #fff;
            border-radius: 14px;
            padding: 56px 60px;
            width: 100%;
            max-width: 560px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
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
        .brand-tagline {
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 300;
            letter-spacing: 0.35em;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 10px;
            display: block;
        }
        .brand-wrap {
            text-align: center;
            margin-bottom: 36px;
        }
        .brand-divider {
            height: 0.5px;
            background: #e2e8f0;
            margin: 0 0 30px;
        }
        label { display:block; margin-bottom:6px; font-size:1rem; font-weight:600; color:#374151; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 12px 14px; font-size: 1rem; border: 1px solid #d1d5db; border-radius: 8px; margin-bottom: 16px;
            outline: none;
        }
        input:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }
        .btn { display: inline-block; padding: 12px 20px; border-radius: 8px; border: none; cursor: pointer; font-size: 1rem; text-decoration: none; }
        .btn-primary { background: #10b981; color: #fff; width: 100%; font-weight: 600; }
        .btn-primary:hover { background: #059669; }
        .errors { background:#fee2e2; color:#b91c1c; padding:12px; border-radius:8px; margin-bottom:16px; font-size:.9rem; }
        .status { background:#dcfce7; color:#166534; padding:12px; border-radius:8px; margin-bottom:16px; font-size:.9rem; }
        .auth-links { text-align:center; margin-top:16px; font-size:.9rem; }
        .auth-links a { color:#047857; text-decoration:none; }
        @media (max-width: 600px) {
            .auth-card { padding: 36px 24px; max-width: 92%; }
            .brand-name { font-size: 2.1rem; }
        }

        /* ── Sign-in skeleton ──
           Authenticating then loading the dashboard takes a moment; without
           this the button just sits there and the click reads as ignored. */
        @keyframes remedi-shimmer {
            0%   { background-position: -420px 0; }
            100% { background-position: 420px 0; }
        }

        /* ── Post-login skeleton ──
           Signing in redirects to the dashboard, and until that document
           arrives the browser still shows this page. So the skeleton is
           shaped like the DASHBOARD, not like the login card: you see the
           app's sidebar/topbar/panels filling in, which is where you're
           actually going. */
        .auth-skeleton {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 999;
            background: #f1f5f9;
        }

        body.is-authenticating { overflow: hidden; }
        body.is-authenticating .auth-skeleton { display: flex; }
        body.is-authenticating .auth-card { visibility: hidden; }

        /* Sidebar is the app's dark teal slab (--nav-bg), not the slate it
           used to be -- the skeleton has to look like the page it precedes. */
        .auth-skeleton .sk-sidebar {
            width: 280px;
            flex-shrink: 0;
            background: #0c3b33;
            padding: 26px 16px;
        }

        .auth-skeleton .sk-main { flex: 1; display: flex; flex-direction: column; }

        .auth-skeleton .sk-topbar {
            height: 78px;
            background: #fff;
            border-bottom: 0.5px solid #e2e8f0;
            display: flex;
            align-items: center;
            padding: 0 32px;
            gap: 14px;
        }

        .auth-skeleton .sk-content { padding: 28px 32px; }

        .auth-skeleton .sk-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(178px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .auth-skeleton .sk-block {
            background: #e2e8f0;
            background-image: linear-gradient(90deg, #e2e8f0 0, #eef2f7 180px, #e2e8f0 360px);
            background-size: 840px 100%;
            border-radius: 8px;
            animation: remedi-shimmer 1.3s linear infinite;
        }

        .auth-skeleton .sk-sidebar .sk-block {
            background: #14554a;
            background-image: linear-gradient(90deg, #14554a 0, #1b6a5c 180px, #14554a 360px);
            height: 40px;
            margin-bottom: 8px;
            border-radius: 7px;
        }

        /* Same proportions as the real dashboard: 118px KPI tiles, a greeting
           bar with a date pill, two charts side by side. */
        .auth-skeleton .sk-kpi   { height: 118px; border-radius: 14px; }
        .auth-skeleton .sk-bar   { height: 26px; width: 220px; }

        .auth-skeleton .sk-greeting {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
        }

        .auth-skeleton .sk-h    { height: 24px; width: 260px; margin-bottom: 8px; }
        .auth-skeleton .sk-sub  { height: 13px; width: 320px; }
        .auth-skeleton .sk-pill { height: 38px; width: 230px; border-radius: 10px; }

        .auth-skeleton .sk-tabs { display: flex; gap: 10px; margin-bottom: 22px; }
        .auth-skeleton .sk-tab  { height: 41px; width: 104px; border-radius: 999px; }

        .auth-skeleton .sk-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .auth-skeleton .sk-chart { height: 280px; border-radius: 14px; }

        @media (max-width: 1000px) {
            .auth-skeleton .sk-charts { grid-template-columns: 1fr; }
        }

        .auth-signing-in {
            position: fixed;
            left: 50%;
            bottom: 34px;
            transform: translateX(-50%);
            background: #1e293b;
            color: #e2e8f0;
            font-size: 13px;
            font-weight: 500;
            padding: 9px 18px;
            border-radius: 999px;
            box-shadow: 0 8px 22px -8px rgba(15, 23, 42, .6);
        }

        @media (max-width: 767px) {
            .auth-skeleton .sk-sidebar { display: none; }
            .auth-skeleton .sk-content { padding: 16px 14px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .auth-skeleton .sk-block { animation: none; }
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="brand-wrap">
            <span class="brand-name">RE<span>ME</span>DI</span>
            <span class="brand-tagline">Inventory & Sales Management</span>
        </div>
        <div class="brand-divider"></div>

        @if(session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="errors">
                <ul style="margin:0; padding-left:18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif


        {{ $slot }}
    </div>

    <div class="auth-skeleton" aria-hidden="true">
        <div class="sk-sidebar">
            {{-- Brand lockup, then the nav items the dashboard shows. --}}
            <div class="sk-block" style="height:46px; margin-bottom:24px;"></div>
            <div class="sk-block"></div>
            <div class="sk-block"></div>
            <div class="sk-block"></div>
            <div class="sk-block"></div>
            <div class="sk-block" style="width:60%; height:12px; margin:18px 0 10px;"></div>
            <div class="sk-block"></div>
            <div class="sk-block"></div>
            <div class="sk-block"></div>
        </div>
        <div class="sk-main">
            <div class="sk-topbar">
                <div class="sk-block sk-bar"></div>
                <div class="sk-block" style="height:40px; width:360px; border-radius:999px; margin:0 auto;"></div>
                <div class="sk-block" style="height:38px; width:38px; border-radius:50%;"></div>
            </div>
            <div class="sk-content">
                <div class="sk-greeting">
                    <div>
                        <div class="sk-block sk-h"></div>
                        <div class="sk-block sk-sub"></div>
                    </div>
                    <div class="sk-block sk-pill"></div>
                </div>

                <div class="sk-kpis">
                    <div class="sk-block sk-kpi"></div>
                    <div class="sk-block sk-kpi"></div>
                    <div class="sk-block sk-kpi"></div>
                    <div class="sk-block sk-kpi"></div>
                    <div class="sk-block sk-kpi"></div>
                    <div class="sk-block sk-kpi"></div>
                </div>

                <div class="sk-tabs">
                    <div class="sk-block sk-tab"></div>
                    <div class="sk-block sk-tab"></div>
                </div>

                <div class="sk-charts">
                    <div class="sk-block sk-chart"></div>
                    <div class="sk-block sk-chart"></div>
                </div>
            </div>
        </div>
        <p class="auth-signing-in">Signing you in&hellip;</p>
    </div>

<script>
    // Show the skeleton once the credentials are on their way. Guarded on
    // the form's own validity so an empty submit (which the browser blocks)
    // doesn't blank the fields the user still has to fill in.
    (function () {
        var card = document.querySelector('.auth-card');
        if (!card) return;

        card.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.hasAttribute('data-no-skeleton')) return;
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

            setTimeout(function () {
                if (!e.defaultPrevented) document.body.classList.add('is-authenticating');
            }, 0);
        });

        // Coming back via the back button must not leave the skeleton up.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) document.body.classList.remove('is-authenticating');
        });
    })();
</script>
</body>
</html>
