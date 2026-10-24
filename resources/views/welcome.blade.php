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
    {{-- Loaded async (preload-then-swap) rather than as a normal blocking
         stylesheet <link> -- a normal one holds first paint until Google's
         CSS response lands, which delays the splash's own background and
         subtitle from appearing at all, for a font that display:swap
         already lets the page render without waiting on anyway. --}}
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;600;700&display=swap"></noscript>

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
            /* The logo-reveal video's own grey (2026-10-24), so the frame's
               faded edges (below) melt into the page. */
            background: radial-gradient(ellipse at 50% 45%, #ebebeb 0%, #dcdcdc 45%, #cacaca 100%);
            opacity: 1;
            transition: opacity .5s ease;
            /* The frame's width, shared by the video and the subtitle's pull-up. */
            --frame: clamp(440px, min(68vw, 120vh), 940px);
        }

        /* Centred and smaller, like the old Logo.mp4 mark (2026-10-24, at the
           user's request -- it first filled the screen). The logo spans
           31-72% of the frame's width, so a frame of up to 940px gives a logo
           of up to ~390px, about the old mark's 420px. The video carries its
           own grey backdrop rather than transparency, so a radial mask fades
           the frame's edges into the page instead of showing a rectangle;
           the logo (within 0.67 of the centre at its widest) stays opaque.
           440px minimum: on a phone the frame is wider than the screen and
           its faded sides are simply off it -- the logo itself still fits. */
        .splash-video {
            display: block;
            width: var(--frame);
            height: auto;
            aspect-ratio: 16 / 9;
            pointer-events: none;
            -webkit-mask-image: radial-gradient(ellipse 40% 50% at 51% 50%, #000 66%, transparent 98%);
                    mask-image: radial-gradient(ellipse 40% 50% at 51% 50%, #000 66%, transparent 98%);
        }

        /* Set once the Get Started button appears -- the whole splash
           becomes a click target at that point (see the click listener on
           #splash in the script), so the cursor says so. */
        #splash.is-ready { cursor: pointer; }

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

        /* The settled logo ends at 78% of the frame's height and the rest is
           faded backdrop, so the subtitle is pulled up into that strip
           (-11% of the frame's width = -20% of its height) rather than
           leaving a gap; the loading dots go -- the playing video already
           says something is happening. */
        #splash .splash-subtitle { margin-top: calc(var(--frame) * -0.11); font-size: clamp(13px, 1.8vw, 17px); color: #475569; position: relative; }
        #splash .splash-dots { display: none !important; }
        /* Mid-reveal the logo's chart bars reach the subtitle's line; by the
           last frame the logo has settled above it. So it comes in with the
           Get Started button, not with playback. */
        #splash .splash-inner:not(.is-ready) .splash-subtitle { visibility: hidden; }

        .splash-logo {
            display: block;
            width: clamp(180px, 45vw, 420px);
            height: auto;
            opacity: 0;
        }

        /* Neither of these animates on page load any more -- the canvas sits
           empty (undrawn) until the video's `playing` event fires and
           startKeying() begins actually putting frames into it, which can
           land well after a CSS animation timed from page load would have
           already finished. JS adds .is-playing to .splash-inner at that
           exact moment instead, so the logo and the subtitle genuinely start
           together, in sync with when the video is first visible -- not
           with when the page happened to load. */
        .splash-inner.is-playing .splash-logo {
            animation: logoIn .7s ease forwards;
        }

        @keyframes logoIn {
            to { opacity: 1; }
        }

        .splash-subtitle {
            margin-top: 22px;
            font-family: 'Outfit', sans-serif;
            font-size: clamp(15px, 2.6vw, 19px);
            font-weight: 500;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--muted);
            opacity: 0;
            transform: scale(1.2);
        }

        .splash-inner.is-playing .splash-subtitle {
            animation: subtitleZoomOut .8s ease-out forwards;
        }

        /* Zooms OUT (shrinks down to rest) rather than in, matching the
           video's own settle-down motion. */
        @keyframes subtitleZoomOut {
            from { opacity: 0; transform: scale(1.2); }
            to { opacity: 1; transform: scale(1); }
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

        /* The dots mean "something is happening" -- once the video has run
           its course there's nothing left to wait on, so they hand off to
           the button rather than pulsing forever beside it. !important is
           required: .splash-dots' own fadeUp animation (`forwards`, so it
           keeps asserting opacity:1 once finished) outranks a plain
           declaration at equal specificity, same reason the
           prefers-reduced-motion override above needs it. */
        .splash-inner.is-ready .splash-dots {
            opacity: 0 !important;
            pointer-events: none;
        }

        /* Wraps the button AND the fallback link (see below) in one slot
           that only the BUTTON sizes -- the button is always in normal
           flow (opacity animates, but it never leaves the flow), so the
           slot's height is stable from first paint. The fallback is
           pulled out of flow entirely with position:absolute so it can
           never contribute its own height to this slot; without that, it
           sat in flow at opacity:0 same as the button does, reserving an
           EXTRA ~70px that vanished the instant .is-ready set it to
           display:none -- shrinking .splash-inner and visibly shoving
           the whole block up the screen right as the button appeared.
           That was the actual "position changes" bug, not the video/canvas
           sizing this file fixes elsewhere. */
        .get-started-slot {
            position: relative;
            margin-top: 28px;
        }

        .get-started-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 13px 32px 13px 40px;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #fff;
            background: var(--brand);
            border: none;
            border-radius: 999px;
            box-shadow: 0 6px 18px rgba(16, 185, 129, .22);
            cursor: pointer;
            opacity: 0;
            transform: translateY(8px) scale(.7);
            pointer-events: none;
            transition: background .15s ease, box-shadow .25s ease, transform .2s ease;
        }

        .get-started-btn:hover { background: var(--brand-dark); }

        /* A genuine <a href> to login, independent of the JS button above --
           see the markup comment on .no-js-fallback for why this exists.
           Its reveal is a plain CSS animation-delay, not gated on any class
           JS would add, so it fires whether or not a single line of that
           script ever ran. Deliberately styled IDENTICAL to
           .get-started-btn (same fill, padding, arrow) rather than looking
           like a distinct "fallback" affordance -- a visitor who lands
           here because the script never ran has no way to tell that's what
           happened, and seeing a differently-styled button would read as
           the page being broken in a new way rather than working exactly
           as intended. position:absolute + inset:0 makes it exactly fill
           .get-started-slot (sized by the button) rather than add its own
           box to the flow -- see the comment on .get-started-slot. */
        .no-js-fallback {
            position: absolute;
            inset: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            text-decoration: none;
            color: #fff;
            background: var(--brand);
            border: none;
            border-radius: 999px;
            box-shadow: 0 6px 18px rgba(16, 185, 129, .22);
            opacity: 0;
            pointer-events: none;
            /* 11s: after the 10-second logo reveal, so even a page whose
               script never runs does not offer it mid-video. */
            animation: noJsFallbackReveal 0s linear 11s forwards;
        }

        /* The splash script marks itself as running the moment it starts
           (2026-10-24): from then on Get Started is its job, shown only when
           the video ends. Without this the CSS timer above put this link up
           6 seconds into the 10-second video on every visit. */
        .splash-inner.has-js .no-js-fallback { display: none; }

        .no-js-fallback:hover { background: var(--brand-dark); }

        @keyframes noJsFallbackReveal {
            to { opacity: 1; pointer-events: auto; }
        }

        /* The dots mean "something is happening", same as the JS path's own
           .is-ready rule above -- but that rule only fires once JS adds the
           class, so without this the dots would keep pulsing forever right
           next to a button that's telling the visitor to move on. Same 6s
           mark as the fallback's own reveal, via a second animation on the
           existing declaration (the first, fadeUp, has long finished by
           then, so there's nothing to fight over). */
        .splash-inner:not(.is-ready) .splash-dots {
            animation: fadeUp .6s ease .5s forwards, dotsHideFallback 0s linear 6s forwards;
        }

        @keyframes dotsHideFallback {
            to { opacity: 0; }
        }

        /* JS got far enough to reveal its own button -- the fallback would
           only ever be a confusing duplicate from here on, delayed timer or
           not. */
        .splash-inner.is-ready .no-js-fallback { display: none; }

        .get-started-arrow {
            display: inline-block;
            transition: transform .25s ease;
        }

        .get-started-btn:hover .get-started-arrow { transform: translateX(4px); }

        /* Overshoots past full size then settles -- a "pop" rather than a
           plain fade, for the one moment on this page that's actually
           asking for a click. */
        @keyframes getStartedPop {
            0% { opacity: 0; transform: translateY(8px) scale(.7); }
            60% { opacity: 1; transform: translateY(0) scale(1.08); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .splash-inner.is-ready .get-started-btn {
            opacity: 1;
            pointer-events: auto;
            animation: getStartedPop .5s cubic-bezier(.34, 1.56, .64, 1) forwards;
        }

        /* !important: the pop animation above fills its final transform
           (forwards) past the point a plain declaration can override, same
           reason the dots' fade-out and the reduced-motion overrides
           elsewhere in this file need it -- without it the hover lift is
           computed but never actually wins. */
        .splash-inner.is-ready .get-started-btn:hover {
            transform: translateY(-3px) scale(1) !important;
            box-shadow: 0 12px 26px rgba(16, 185, 129, .32);
        }

        .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 2.75rem;
            font-weight: 700;
            letter-spacing: .18em;
            color: var(--ink);
            line-height: 1;
        }

        .brand-name span { color: var(--brand); }

        .brand-tagline {
            margin-top: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 300;
            letter-spacing: .35em;
            text-transform: uppercase;
            color: var(--muted);
        }

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
            border-radius: 14px;
            padding: 56px 60px;
            width: 100%;
            max-width: 560px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
        }

        .login-brand {
            text-align: center;
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }

        .field { margin-bottom: 18px; }

        /* Sits between the password field and the submit button, right-aligned
           to match the standalone /login page. */
        .forgot-row { margin: -6px 0 16px; text-align: right; font-size: .85rem; }
        .forgot-row a { color: var(--brand-dark); text-decoration: none; }
        .forgot-row a:hover { text-decoration: underline; }

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
            .login-card { padding: 36px 24px; max-width: 92%; }
            .brand-name { font-size: 2.1rem; }
            .brand-tagline { letter-spacing: .22em; }
        }

        @media (prefers-reduced-motion: reduce) {
            /* !important: .splash-inner.is-playing .splash-logo/.splash-subtitle
               are more specific than a bare .splash-logo/.splash-subtitle
               selector, so without it this would lose once JS adds that
               class -- the exact moment reduced motion matters most. */
            .splash-logo, .splash-subtitle, .splash-dots { animation: none !important; opacity: 1 !important; transform: none !important; }
            #splash, #login-stage { transition: none; }
            .splash-dots span { animation: none; opacity: .6; }
            .get-started-btn, .get-started-arrow { transition: none; }
            .splash-inner.is-ready .get-started-btn {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
            }
            .splash-inner.is-ready .get-started-btn:hover { transform: none !important; }
            .splash-inner.is-ready .splash-dots { opacity: 0 !important; }
        }
    </style>
</head>
<body>

    {{-- ═══════════════════════════ 1. SPLASH SCREEN ═══════════════════════════
         Shown only on this page's own load (there is no client-side routing
         here, so every fresh GET / is a genuine "initial load"). The video
         plays once and stops on its final frame; nothing times out into
         login any more -- a Get Started button appears once playback ends
         (or once FALLBACK_DURATION_MS decides it never will) and login is
         reached only by clicking it. See revealGetStarted() below. --}}
    <div id="splash">
        <div class="splash-inner">
            {{-- The REMEDI logo reveal (2026-10-24, at the user's request:
                 public/assets/remedi-reveal.mp4, 10 s, replacing the keyed
                 Logo.mp4 mark). It carries its own grey backdrop, so it is
                 shown as it is, full screen behind the text and button, and
                 stops on its last frame when it ends -- Get Started then
                 appears, as before. Muted: autoplay refuses sound. --}}
            <video id="splashVideo" class="splash-video" src="{{ \App\Support\StaticAsset::url('assets/remedi-reveal.mp4') }}"
                   muted playsinline preload="auto" fetchpriority="high" disablepictureinpicture aria-hidden="true"></video>
            <p class="splash-subtitle">Web-Based Pharmacy Management System</p>
            <div class="splash-dots" aria-hidden="true"><span></span><span></span><span></span></div>
            {{-- Both the button and its no-JS fallback live in one slot --
                 see .get-started-slot for why (only the button sizes it;
                 the fallback is positioned to exactly fill it rather than
                 adding its own box, or the two would visibly disagree
                 about how much space this row needs). --}}
            <div class="get-started-slot">
                {{-- Hidden until the video finishes (or the fallback timer
                     decides it never will) -- see revealGetStarted() below.
                     Nothing here auto-advances to login any more; this is
                     the only way in. --}}
                <button type="button" id="getStartedBtn" class="get-started-btn">
                    <span>Get Started</span>
                    <span class="get-started-arrow" aria-hidden="true">&rarr;</span>
                </button>
                {{-- Real, plain <a href> -- not a JS-dependent button --
                     that reveals itself after a fixed delay via CSS alone
                     (see .no-js-fallback / @keyframes noJsFallbackReveal
                     above), and goes straight to the login page directly
                     rather than through revealGetStarted()/hideSplash().
                     This exists for exactly one failure mode: the splash
                     script fails to run at all -- a network-level proxy
                     stripping or mangling the inline script, a restrictive
                     in-app browser, anything -- which otherwise leaves
                     every element here sitting at its pre-JS opacity:0
                     forever, i.e. what looks like "the splash page doesn't
                     show" when in fact NOTHING does, forever. Hidden the
                     instant JS *does* get far enough to add is-ready, so
                     this is never visible alongside a working page. --}}
                <a href="{{ route('login') }}" class="no-js-fallback">
                    <span>Get Started</span>
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </div>

    {{-- True no-JS fallback (JavaScript entirely disabled, not just failing
         partway): skips the splash outright rather than leaving a visitor
         staring at a background with nothing on it for 6 seconds waiting on
         a CSS timer that would still work, but is a needlessly long wait
         when there's no chance of the video path ever running anyway. --}}
    <noscript>
        <style>
            #splash { display: none !important; }
            #login-stage { display: flex !important; opacity: 1 !important; transform: none !important; }
        </style>
    </noscript>

    {{-- ═══════════════════════════ 2. LOGIN SCREEN ═══════════════════════════
         Splash goes straight here -- no separate landing/marketing page in
         between. Posts to the app's REAL, existing login endpoint
         (routes/auth.php, AuthenticatedSessionController::store) -- nothing
         about the backend auth flow changes here, only how the form is
         presented. Brand block is TEXT only (no logo image) on this screen,
         by request. --}}
    <section id="login-stage" class="stage">
        <div class="login-card">
            <div class="login-brand">
                <div class="brand-name">RE<span>ME</span>DI</div>
                <div class="brand-tagline">Inventory & Sales Management</div>
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

                {{-- This page carries its OWN login form, so it needs its own
                     copy of this link: logging out redirects to `/`, not to
                     `/login`, so for anyone who has just signed out THIS is the
                     sign-in screen and the one on auth/login.blade.php is never
                     seen. Without it, "Forgot your password?" simply did not
                     exist after a logout. Right-aligned to match that page. --}}
                <div class="forgot-row">
                    <a href="{{ route('password.request') }}">Forgot your password?</a>
                </div>

                <button type="submit" class="btn-submit">Log in</button>
            </form>
        </div>
    </section>

    <script>
        (function () {
            // The script is running: the CSS-only Get Started link (for a page
            // whose script never runs) stands down, and Get Started waits
            // for the video to END (2026-10-24, at the user's request).
            document.querySelector('.splash-inner').classList.add('has-js');

            // Only used if the video never actually STARTS playing -- a very
            // slow network, a stalled download. Cleared the moment the
            // `playing` event fires (see below), so it only ever has to cover
            // "did playback begin" -- if it never does, the Get Started button
            // is revealed anyway rather than leaving the splash with nothing
            // to click. 15s (was 4s): 4s fired while a slow-loading video was
            // still on its way, putting the button up mid-video. A refused
            // autoplay or a load error reveal it at once instead (below).
            var FALLBACK_DURATION_MS = 15000;

            var splash = document.getElementById('splash');
            var loginStage = document.getElementById('login-stage');
            var video = document.getElementById('splashVideo');
            var getStartedBtn = document.getElementById('getStartedBtn');

            // Server-rendered flag: true when this page is being shown again
            // because a login POST just failed and redirected back to `/`.
            // Replaying the splash in front of someone who is trying to read
            // why their password was rejected is the wrong call, so this
            // skips straight to the (already-visible) login screen with the
            // error in place -- see routes/web.php and
            // AuthenticatedSessionController.
            var skipSplash = @json($errors->any() || old('email') !== null);

            // The video is shown as it is (it carries its own backdrop), so
            // there is nothing to key any more; these only mark playback for
            // the subtitle's zoom (see .is-playing) and keep the old names.
            function startKeying() {
                document.querySelector('.splash-inner').classList.add('is-playing');
            }

            function stopKeying() {}

            function showLogin() {
                loginStage.classList.add('is-active');
                // Next frame, so the transition (opacity/transform) actually
                // runs instead of starting from its own end state.
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        loginStage.classList.add('is-visible');
                    });
                });
            }

            // Swaps the loading dots for the Get Started button -- the only
            // way off the splash now. Idempotent: the video's `ended` event,
            // the fallback timer, and a rejected play() promise can all lead
            // here, and only the first should count.
            var getStartedShown = false;
            function revealGetStarted() {
                if (getStartedShown) return;
                getStartedShown = true;
                clearTimeout(fallbackTimer);
                // The fallback timer and a rejected play() promise both
                // reach here WITHOUT the video's `playing` event ever
                // having fired, which is the only place .is-playing
                // normally gets added -- so the logo and subtitle are
                // still sitting at their pre-animation opacity:0. Add it
                // here too (harmless if already present) so the button
                // never appears alone on an otherwise-blank splash.
                document.querySelector('.splash-inner').classList.add('is-playing', 'is-ready');
                splash.classList.add('is-ready'); // cursor affordance -- see #splash.is-ready
            }

            function hideSplash() {
                splash.classList.add('is-hiding');
                stopKeying();

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
                    video.pause();
                    showLogin();
                }
                splash.addEventListener('transitionend', finish, { once: true });
                setTimeout(finish, 700); // fade-out transition is .5s; this is a safety margin, not the real trigger
            }

            if (skipSplash) {
                splash.classList.add('is-gone');
                showLogin();
            } else {
                var hidden = false;
                function hideOnce() {
                    if (hidden) return;
                    hidden = true;
                    hideSplash();
                }

                // The button is the visible affordance, but once it's shown
                // the whole splash is a target -- a click anywhere on it
                // (the button included, via bubbling, so this alone covers
                // both) proceeds. Gated on getStartedShown so a click during
                // the video itself does nothing; the point of waiting for
                // it is lost if a stray tap skips straight past it.
                splash.addEventListener('click', function () {
                    if (!getStartedShown) return;
                    hideOnce();
                });

                // Safety net for "does the video ever actually start" only.
                // Clearing it the moment `playing` actually fires means its
                // duration only has to cover "start playing" -- if it never
                // does, revealGetStarted() shows the button anyway rather
                // than leaving the splash stuck with nothing on it.
                var fallbackTimer = setTimeout(revealGetStarted, FALLBACK_DURATION_MS);

                // The clip plays once, in full, then stops on its final
                // frame -- no `loop`, no auto-advance -- and Get Started
                // appears over it.
                video.addEventListener('ended', function () {
                    stopKeying();
                    revealGetStarted();
                });
                video.addEventListener('playing', function () {
                    clearTimeout(fallbackTimer);
                    startKeying();
                });
                // A video that cannot load will never end: don't wait on it.
                video.addEventListener('error', revealGetStarted);

                var playPromise = video.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    // Autoplay blocked (some mobile browsers, some privacy
                    // settings) -- don't wait on a video that will never
                    // play; reveal the button straight away.
                    playPromise.catch(revealGetStarted);
                }
            }

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
