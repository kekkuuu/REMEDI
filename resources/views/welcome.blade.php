<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'REMEDI') }} — Web-Based Pharmacy Management System</title>

    {{-- Same tab identity as layouts/guest and layouts/app. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#10b981">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: #10b981;
            --brand-dark: #059669;
            --ink: #1e293b;
            --muted: #64748b;
            --line: #e2e8f0;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f3f4f6;
            overflow-x: hidden;
        }

        /* Every stage takes the full viewport and stacks in normal flow --
           only one is ever display:block at a time, so there is never a
           second page's height sitting underneath and causing a scrollbar
           jump mid-transition. */
        .stage {
            display: none;
            min-height: 100vh;
            width: 100%;
        }

        .stage.is-active { display: flex; }

        /* ============================= SPLASH ============================= */

        #splash {
            display: flex;
            align-items: center;
            justify-content: center;
            position: fixed;
            inset: 0;
            z-index: 100;
            background:
                radial-gradient(circle at 30% 20%, rgba(16, 185, 129, .08), transparent 45%),
                radial-gradient(circle at 75% 80%, rgba(16, 185, 129, .06), transparent 50%),
                #fafcfb;
            opacity: 1;
            transition: opacity .5s ease;
        }

        #splash.is-hiding { opacity: 0; }

        /* Splash is removed from layout (not just faded) once its transition
           ends, so it can never sit invisibly on top of the page eating
           clicks -- see hideSplash() in the script below. */
        #splash.is-gone { display: none; }

        .splash-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 24px;
        }

        .splash-logo {
            width: clamp(96px, 22vw, 148px);
            height: auto;
            opacity: 0;
            transform: scale(.85);
            animation: logoIn .7s cubic-bezier(.2, .7, .3, 1) forwards;
        }

        @keyframes logoIn {
            to { opacity: 1; transform: scale(1); }
        }

        .splash-subtitle {
            margin-top: 18px;
            font-family: 'Outfit', sans-serif;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--muted);
            opacity: 0;
            animation: fadeUp .6s ease .35s forwards;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Three-dot loader -- deliberately quiet: small, low-contrast, no
           label. It only has to say "something is happening", not perform. */
        .splash-dots {
            display: flex;
            gap: 6px;
            margin-top: 28px;
            opacity: 0;
            animation: fadeUp .6s ease .5s forwards;
        }

        .splash-dots span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--brand);
            opacity: .35;
            animation: dotPulse 1.1s ease-in-out infinite;
        }

        .splash-dots span:nth-child(2) { animation-delay: .15s; }
        .splash-dots span:nth-child(3) { animation-delay: .3s; }

        @keyframes dotPulse {
            0%, 80%, 100% { opacity: .25; transform: scale(.85); }
            40% { opacity: 1; transform: scale(1); }
        }

        /* ============================= LANDING ============================= */

        #landing {
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity .5s ease, transform .5s ease;
        }

        #landing.is-visible { opacity: 1; transform: translateY(0); }

        .landing-card {
            text-align: center;
            max-width: 480px;
            width: 100%;
        }

        .brand-mark {
            width: clamp(56px, 12vw, 76px);
            height: auto;
            margin-bottom: 18px;
        }

        .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(2rem, 6vw, 2.75rem);
            font-weight: 700;
            letter-spacing: .16em;
            color: var(--ink);
            line-height: 1;
        }

        .brand-name span { color: var(--brand); }

        .brand-tagline {
            margin-top: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 300;
            letter-spacing: .3em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .landing-intro {
            margin: 28px 0 0;
            font-size: 15px;
            line-height: 1.6;
            color: var(--muted);
        }

        .btn-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 32px;
            padding: 13px 30px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: var(--brand);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .btn-login:hover { background: var(--brand-dark); transform: translateY(-1px); box-shadow: 0 8px 20px -8px rgba(16, 185, 129, .5); }
        .btn-login:active { transform: translateY(0); }

        /* ============================= LOGIN ============================= */

        #login-stage {
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity .45s ease, transform .45s ease;
        }

        #login-stage.is-visible { opacity: 1; transform: translateY(0); }

        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 44px 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 50px -20px rgba(15, 23, 42, .18);
        }

        .login-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            padding: 0;
            margin-bottom: 22px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
            cursor: pointer;
        }

        .login-back:hover { color: var(--ink); }

        .login-brand {
            text-align: center;
            margin-bottom: 28px;
        }

        .login-brand img {
            width: 52px;
            height: auto;
            margin-bottom: 10px;
        }

        .login-brand .brand-name { font-size: 1.6rem; letter-spacing: .14em; }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .field { margin-bottom: 18px; }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            font-size: 15px;
            font-family: inherit;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(16, 185, 129, .12); }

        .btn-submit {
            width: 100%;
            padding: 13px;
            margin-top: 6px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: var(--brand);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .btn-submit:hover { background: var(--brand-dark); }
        .btn-submit[disabled] { opacity: .75; cursor: default; }

        .login-links {
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
        }

        .login-links a { color: var(--brand-dark); text-decoration: none; }
        .login-links a:hover { text-decoration: underline; }

        .form-status {
            background: #dcfce7;
            color: #166534;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .form-errors {
            background: #fee2e2;
            color: #b91c1c;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .form-errors ul { margin: 0; padding-left: 18px; }

        /* ============================= RESPONSIVE ============================= */

        @media (max-width: 640px) {
            .login-card { padding: 32px 24px; }
            .brand-tagline { letter-spacing: .22em; }
        }

        @media (prefers-reduced-motion: reduce) {
            .splash-logo, .splash-subtitle, .splash-dots { animation: none; opacity: 1; transform: none; }
            #splash, #landing, #login-stage { transition: none; }
            .splash-dots span { animation: none; opacity: .6; }
        }
    </style>
</head>
<body>

    {{-- ═══════════════════════════ 1. SPLASH SCREEN ═══════════════════════════
         Shown only on this page's own load (there is no client-side routing
         here, so every fresh GET / is a genuine "initial load"). Duration is
         the single SPLASH_DURATION_MS constant in the script below. --}}
    <div id="splash">
        <div class="splash-inner">
            {{-- LOGO: public/logo.png -- the exact, unedited, transparent
                 REMEDI mark already used for the sidebar/dashboard loader
                 elsewhere in the app. Do not swap this for a redrawn asset. --}}
            <img class="splash-logo" src="{{ asset('logo.png') }}" alt="REMEDI">
            <p class="splash-subtitle">Web-Based Pharmacy Management System</p>
            <div class="splash-dots" aria-hidden="true"><span></span><span></span><span></span></div>
        </div>
    </div>

    {{-- ═══════════════════════════ 2. LANDING PAGE ═══════════════════════════ --}}
    <section id="landing" class="stage">
        <div class="landing-card">
            <img class="brand-mark" src="{{ asset('logo.png') }}" alt="REMEDI">
            <div class="brand-name">RE<span>ME</span>DI</div>
            <div class="brand-tagline">Pharmacy Management System</div>

            <p class="landing-intro">
                Inventory, point-of-sale, and demand forecasting for your pharmacy — in one place.
            </p>

            <button type="button" class="btn-login" id="showLoginBtn">Login</button>
        </div>
    </section>

    {{-- ═══════════════════════════ 3. LOGIN SCREEN ═══════════════════════════
         Posts to the app's REAL, existing login endpoint (routes/auth.php,
         AuthenticatedSessionController::store) -- nothing about the backend
         auth flow changes here, only how the form is presented. --}}
    <section id="login-stage" class="stage">
        <div class="login-card">
            <button type="button" class="login-back" id="backToLandingBtn">
                <span aria-hidden="true">&larr;</span> Back
            </button>

            <div class="login-brand">
                <img src="{{ asset('logo.png') }}" alt="REMEDI">
                <div class="brand-name">RE<span>ME</span>DI</div>
            </div>

            @if (session('status'))
                <div class="form-status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="form-errors">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" id="loginForm">
                @csrf

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn-submit">Log in</button>

                <div class="login-links">
                    <a href="{{ route('password.request') }}">Forgot Password?</a>
                </div>
            </form>
        </div>
    </section>

    <script>
        (function () {
            // Change this one value to change the splash duration everywhere.
            var SPLASH_DURATION_MS = 1500;

            var splash = document.getElementById('splash');
            var landing = document.getElementById('landing');
            var loginStage = document.getElementById('login-stage');
            var showLoginBtn = document.getElementById('showLoginBtn');
            var backBtn = document.getElementById('backToLandingBtn');

            // Server-rendered flag: true when this page is being shown again
            // because a login POST just failed and redirected back to `/`.
            // Replaying a 1.5s splash in front of someone who is trying to
            // read why their password was rejected is the wrong call, so this
            // skips straight to the login screen with the error already
            // visible -- see routes/web.php and AuthenticatedSessionController.
            var skipSplash = @json($errors->any() || old('email') !== null);

            function showStage(stage) {
                [landing, loginStage].forEach(function (s) {
                    s.classList.remove('is-active', 'is-visible');
                });
                stage.classList.add('is-active');
                // Next frame, so the transition (opacity/transform) actually
                // runs instead of starting from its own end state.
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        stage.classList.add('is-visible');
                    });
                });
            }

            function hideSplash() {
                splash.classList.add('is-hiding');

                // Remove the splash from layout once its fade-out finishes --
                // and ALSO on a fallback timer, so a dropped transitionend
                // event (a backgrounded tab, a slow device) can never leave
                // it sitting on screen forever. This is the "must not get
                // stuck" guarantee.
                var done = false;
                function finish() {
                    if (done) return;
                    done = true;
                    splash.classList.add('is-gone');
                    showStage(landing);
                }
                splash.addEventListener('transitionend', finish, { once: true });
                setTimeout(finish, 700); // fade-out transition is .5s; this is a safety margin, not the real trigger
            }

            if (skipSplash) {
                splash.classList.add('is-gone');
                showStage(loginStage);
            } else {
                setTimeout(hideSplash, SPLASH_DURATION_MS);
            }

            showLoginBtn.addEventListener('click', function () {
                showStage(loginStage);
                var emailField = document.getElementById('email');
                if (emailField) emailField.focus({ preventScroll: true });
            });

            backBtn.addEventListener('click', function () {
                showStage(landing);
            });

            // Guard against a double-submit while the redirect is in flight --
            // same pattern layouts/guest.blade.php already uses.
            var loginForm = document.getElementById('loginForm');
            var submitting = false;
            loginForm.addEventListener('submit', function (e) {
                if (submitting) { e.preventDefault(); return; }
                if (typeof loginForm.checkValidity === 'function' && !loginForm.checkValidity()) return;
                submitting = true;
                setTimeout(function () {
                    if (e.defaultPrevented) { submitting = false; return; }
                    var btn = loginForm.querySelector('button[type="submit"]');
                    if (btn) { btn.dataset.label = btn.textContent; btn.textContent = 'Signing you in…'; btn.disabled = true; }
                }, 0);
            });

            window.addEventListener('pageshow', function (ev) {
                if (!ev.persisted) return;
                var btn = loginForm.querySelector('button[type="submit"][disabled]');
                if (btn) { btn.disabled = false; if (btn.dataset.label) btn.textContent = btn.dataset.label; }
                submitting = false;
            });
        })();
    </script>
</body>
</html>
