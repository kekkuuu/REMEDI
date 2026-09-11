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
            background:
                radial-gradient(circle at 30% 20%, rgba(16, 185, 129, .08), transparent 45%),
                radial-gradient(circle at 75% 80%, rgba(16, 185, 129, .06), transparent 50%),
                #fafcfb;
            opacity: 1;
            transition: opacity .5s ease;
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
            animation: noJsFallbackReveal 0s linear 6s forwards;
        }

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
            {{-- LOGO: public/Logo.mp4 -- the animated REMEDI mark. Standard
                 H.264 has no alpha channel, so the raw <video> would render
                 its own solid background rather than sitting transparently
                 over the splash. The <video> here is decode-only and never
                 shown (opacity:0, 1x1, off the layout); a canvas the same
                 size as .splash-logo draws each frame and keys the clip's
                 own solid background colour out to transparent in real
                 time -- see chromaKeyFrame() below. Filename case matters on
                 Linux (Vercel/Railway), unlike Windows/XAMPP -- keep it
                 exactly `Logo.mp4` if the file is ever replaced. --}}
            <video id="splashVideo" src="{{ asset('Logo.mp4') }}" muted playsinline preload="auto" fetchpriority="high"
                   style="position:absolute; width:1px; height:1px; opacity:0; pointer-events:none;"></video>
            {{-- width/height match Logo.mp4's real dimensions (824x768).
                 Without them a <canvas> defaults to 300x150 (2:1) until the
                 first real frame is drawn and chromaKeyFrame() sets
                 canvas.width/height to the video's actual size -- at which
                 point the intrinsic ratio jumps from 2:1 to ~1.07:1, nearly
                 doubling the rendered height under `height:auto` and
                 visibly shoving the whole vertically-centred splash block
                 (subtitle, dots, button) down the screen the moment
                 playback starts. Declaring the real size up front means
                 the space is correctly reserved from the very first paint,
                 so there's nothing left to reflow when JS's own
                 `if (canvas.width !== video.videoWidth)` check runs and
                 finds they already match. --}}
            <canvas id="splashCanvas" class="splash-logo" width="824" height="768" role="img" aria-label="REMEDI"></canvas>
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

                <button type="submit" class="btn-submit">Log in</button>
            </form>
        </div>
    </section>

    <script>
        (function () {
            // Only used if the video never actually STARTS playing -- blocked
            // autoplay, a decode failure, a very slow network. Cleared the
            // moment the `playing` event fires (see below), so it only ever
            // has to cover "did playback begin" -- if it never does, the Get
            // Started button is revealed anyway rather than leaving the
            // splash with nothing to click.
            var FALLBACK_DURATION_MS = 4000;

            var splash = document.getElementById('splash');
            var loginStage = document.getElementById('login-stage');
            var video = document.getElementById('splashVideo');
            var canvas = document.getElementById('splashCanvas');
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            var getStartedBtn = document.getElementById('getStartedBtn');

            // Server-rendered flag: true when this page is being shown again
            // because a login POST just failed and redirected back to `/`.
            // Replaying the splash in front of someone who is trying to read
            // why their password was rejected is the wrong call, so this
            // skips straight to the (already-visible) login screen with the
            // error in place -- see routes/web.php and
            // AuthenticatedSessionController.
            var skipSplash = @json($errors->any() || old('email') !== null);

            // ---- Chroma key ----
            // Logo.mp4 has no alpha channel (H.264 in a <video> element never
            // does), so it plays with its own background baked in. That
            // background isn't one flat colour -- there's a soft vignette
            // across the frame (measured: pale green ranging roughly
            // rgb(196,224,210) at the corners to rgb(229,241,233) toward the
            // centre) -- so keying on distance from a single sampled colour
            // left a visible rectangle wherever the vignette drifted outside
            // that one point's threshold.
            //
            // What actually separates every background sample from the
            // logo's own light pixels (the capsule's near-neutral white) is
            // the RELATIONSHIP between channels, not their absolute value:
            // the background is consistently a little light (average channel
            // 195-245) AND a little green-shifted (green minus the red/blue
            // average sits at +4 to +32). The capsule's white measures near
            // rgb(252,253,253) -- neutral, green delta ~0-1 -- so it fails
            // the green-shift test and stays opaque; the logo's saturated
            // greens fail the lightness test the same way. Two independent
            // "how far outside the background's known range" penalties are
            // summed into one score and fed through the same soft KEY_LOW/
            // KEY_HIGH edge as before, so anti-aliased edges still blend
            // rather than going jagged.
            // The clip fades in FROM true black to its pale steady-state
            // background, and a fade-to-black is (approximately) every
            // channel scaled down by the same factor -- so the RATIO between
            // green and the red/blue average stays roughly constant across
            // the whole fade, even though absolute brightness swings from 0
            // to ~210. Keying on that ratio (rather than on brightness bands)
            // is what catches every brightness the fade passes through,
            // including the mid-fade grey that two separate brightness bands
            // missed -- it scored as "too bright to be the dark profile, too
            // dark to be the pale one" and stayed a visible box.
            //
            // Below AVG_FLOOR the ratio itself gets noisy (near-zero channels
            // divide unreliably), but a pixel that dark carries no visible
            // colour anyway, so it's simply treated as background outright --
            // this is what the true rgb(0,0,0) opening frame hits.
            // RATIO_MAX is wider than the steady-state background's own
            // ratio (~0.04-0.10) needs, because the ratio isn't perfectly
            // stable across the fade in practice -- likely gamma correction
            // in the encode rather than a literal linear scale-to-black.
            // Measured directly from real playback at the darkest part of
            // the fade (rgb(49,66,60), the first frame sampled after true
            // black): ratio 0.197. 0.28 covers that with margin while
            // staying far below every logo-green sample's ratio (>=0.45).
            var AVG_FLOOR = 20;
            var RATIO_MIN = 0.02, RATIO_MAX = 0.28;
            var RATIO_SCALE = 100; // converts the ratio gap into the same units KEY_LOW/HIGH use
            var KEY_LOW = 0.3;
            var KEY_HIGH = 1.2;
            var keying = false;

            function backgroundScore(r, g, b) {
                var avg = (r + g + b) / 3;
                if (avg <= AVG_FLOOR) return 0;

                var greenShift = g - (r + b) / 2;
                var ratio = greenShift / avg;

                if (ratio < RATIO_MIN) return (RATIO_MIN - ratio) * RATIO_SCALE;
                if (ratio > RATIO_MAX) return (ratio - RATIO_MAX) * RATIO_SCALE;
                return 0;
            }

            // The clip also carries a soft WHITE glow immediately around the
            // mark, between the pale background and the artwork itself --
            // colourimetrically indistinguishable from the capsule's own
            // white (both sit around rgb(250,252,252)), so no per-pixel
            // colour rule can tell them apart. What DOES separate them is
            // CONNECTIVITY: the glow is continuously reachable from the
            // frame's own edges by hopping through background-or-glow-
            // coloured pixels; the capsule's white is walled off from the
            // edges by the dark ribbon around it and is never reached that
            // way, even though its colour alone looks identical. So this
            // floods inward from the four borders through anything either
            // background-scored OR plainly near-white, and only what that
            // flood actually reaches gets made transparent -- a standard
            // "magic wand from the edges" background removal.
            var NEAR_WHITE_MIN = 235;

            // Reused across frames rather than reallocated each one; resized
            // only if the video's own dimensions ever change (they don't,
            // but canvas.width/height get touched on the first frame).
            var floodBuffers = null;

            function chromaKeyFrame() {
                if (video.videoWidth && video.videoHeight) {
                    if (canvas.width !== video.videoWidth) canvas.width = video.videoWidth;
                    if (canvas.height !== video.videoHeight) canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                    var w = canvas.width, h = canvas.height;
                    var frame = ctx.getImageData(0, 0, w, h);
                    var d = frame.data;

                    if (!floodBuffers || floodBuffers.w !== w || floodBuffers.h !== h) {
                        floodBuffers = {
                            w: w, h: h,
                            floodable: new Uint8Array(w * h),
                            visited: new Uint8Array(w * h),
                            queue: new Int32Array(w * h),
                            labeled: new Uint8Array(w * h),
                            nearBoundary: new Uint8Array(w * h),
                        };
                    }
                    var floodable = floodBuffers.floodable;
                    var visited = floodBuffers.visited;
                    var queue = floodBuffers.queue;
                    var labeled = floodBuffers.labeled;
                    var nearBoundary = floodBuffers.nearBoundary;
                    visited.fill(0);
                    labeled.fill(0);
                    nearBoundary.fill(0);

                    var pixelCount = w * h;
                    for (var p = 0; p < pixelCount; p++) {
                        var idx = p * 4;
                        var r = d[idx], g = d[idx + 1], b = d[idx + 2];
                        var isNearWhite = (r + g + b) / 3 > NEAR_WHITE_MIN;
                        floodable[p] = (backgroundScore(r, g, b) <= KEY_HIGH || isNearWhite) ? 1 : 0;
                    }

                    var qHead = 0, qTail = 0;
                    function tryEnqueue(x, y) {
                        if (x < 0 || x >= w || y < 0 || y >= h) return;
                        var p = y * w + x;
                        if (visited[p] || !floodable[p]) return;
                        visited[p] = 1;
                        queue[qTail++] = p;
                    }
                    for (var x = 0; x < w; x++) { tryEnqueue(x, 0); tryEnqueue(x, h - 1); }
                    for (var y = 0; y < h; y++) { tryEnqueue(0, y); tryEnqueue(w - 1, y); }
                    while (qHead < qTail) {
                        var p2 = queue[qHead++];
                        var px = p2 % w, py = (p2 / w) | 0;
                        tryEnqueue(px + 1, py); tryEnqueue(px - 1, py);
                        tryEnqueue(px, py + 1); tryEnqueue(px, py - 1);
                    }

                    // The soft per-pixel formula below exists for genuine
                    // anti-aliasing: the handful of pixels right on the seam
                    // between the mark and the flat background, blended by
                    // the video encode. It has no business running anywhere
                    // else, but until now it ran over the WHOLE canvas --
                    // and a cool blue-grey highlight partway down the
                    // capsule's glass cap has almost no green push
                    // (greenShift/avg near 0), which is colourimetrically
                    // the same "too neutral to be background" reading
                    // RATIO_MIN is tuned to catch, so it was scored and
                    // partially keyed out as a diagonal soft patch with no
                    // connection to any real edge -- the "hole" in the cap.
                    // Restrict it to a BOUNDARY_RADIUS-px band dilated out
                    // from the actual flood, via a capped multi-source BFS
                    // reusing `queue` (the earlier flood's contents are done
                    // with by this point). Anything outside that band is
                    // topologically nowhere near the background and is left
                    // fully opaque regardless of what its colour ratio says.
                    var BOUNDARY_RADIUS = 3;
                    var dqHead = 0, dqTail = 0;
                    for (var pv = 0; pv < pixelCount; pv++) {
                        if (visited[pv]) { nearBoundary[pv] = 1; queue[dqTail++] = pv; }
                    }
                    var frontierStart = 0, frontierEnd = dqTail;
                    for (var depth = 0; depth < BOUNDARY_RADIUS; depth++) {
                        var nextEnd = frontierEnd;
                        for (var qi = frontierStart; qi < frontierEnd; qi++) {
                            var cp = queue[qi];
                            var cx = cp % w, cy = (cp / w) | 0;
                            if (cx + 1 < w) { var np1 = cp + 1; if (!nearBoundary[np1]) { nearBoundary[np1] = 1; queue[nextEnd++] = np1; } }
                            if (cx - 1 >= 0) { var np2 = cp - 1; if (!nearBoundary[np2]) { nearBoundary[np2] = 1; queue[nextEnd++] = np2; } }
                            if (cy + 1 < h) { var np3 = cp + w; if (!nearBoundary[np3]) { nearBoundary[np3] = 1; queue[nextEnd++] = np3; } }
                            if (cy - 1 >= 0) { var np4 = cp - w; if (!nearBoundary[np4]) { nearBoundary[np4] = 1; queue[nextEnd++] = np4; } }
                        }
                        frontierStart = frontierEnd;
                        frontierEnd = nextEnd;
                    }

                    // Anything the flood reached is background (or the glow
                    // around it) and goes fully transparent. Anything within
                    // the boundary band but not reached keeps the soft
                    // per-pixel edge from backgroundScore(), so genuine edge
                    // anti-aliasing against the flat background still blends
                    // rather than going jagged. Everything else -- the bulk
                    // of the artwork -- is forced fully opaque, whatever its
                    // own colour ratio happens to read as.
                    for (var p3 = 0; p3 < pixelCount; p3++) {
                        if (visited[p3]) {
                            d[p3 * 4 + 3] = 0;
                            continue;
                        }
                        var idx3 = p3 * 4;
                        if (!nearBoundary[p3]) {
                            d[idx3 + 3] = 255;
                            continue;
                        }
                        var score = backgroundScore(d[idx3], d[idx3 + 1], d[idx3 + 2]);
                        if (score < KEY_HIGH) {
                            d[idx3 + 3] = Math.round(Math.max(0, (score - KEY_LOW) / (KEY_HIGH - KEY_LOW)) * 255);
                        } else {
                            d[idx3 + 3] = 255;
                        }
                    }

                    // The flood only removes background/glow that's reachable
                    // from the canvas edges, so a handful of near-white
                    // anti-aliasing pixels along the capsule's own highlight
                    // -- walled off from the edges same as the capsule is,
                    // but only 3-15px, not part of any real stroke -- survive
                    // as visible flecks. Real marks in this logo are all
                    // hundreds of pixels; nothing legitimate is this small.
                    // Label connected opaque components among what's left
                    // (reusing `queue` as a scratch buffer, one component's
                    // indices per contiguous slice) and erase any island
                    // under MIN_ISLAND_SIZE outright.
                    var MIN_ISLAND_SIZE = 60;
                    var runStart = 0;
                    for (var p4 = 0; p4 < pixelCount; p4++) {
                        if (visited[p4] || labeled[p4]) continue;
                        var idx4 = p4 * 4;
                        if (d[idx4 + 3] <= 0) { labeled[p4] = 1; continue; }
                        var qh = runStart, qt = runStart;
                        queue[qt++] = p4;
                        labeled[p4] = 1;
                        while (qh < qt) {
                            var cp = queue[qh++];
                            var cx = cp % w, cy = (cp / w) | 0;
                            if (cx + 1 < w) { var np1 = cp + 1; if (!visited[np1] && !labeled[np1] && d[np1 * 4 + 3] > 0) { labeled[np1] = 1; queue[qt++] = np1; } }
                            if (cx - 1 >= 0) { var np2 = cp - 1; if (!visited[np2] && !labeled[np2] && d[np2 * 4 + 3] > 0) { labeled[np2] = 1; queue[qt++] = np2; } }
                            if (cy + 1 < h) { var np3 = cp + w; if (!visited[np3] && !labeled[np3] && d[np3 * 4 + 3] > 0) { labeled[np3] = 1; queue[qt++] = np3; } }
                            if (cy - 1 >= 0) { var np4 = cp - w; if (!visited[np4] && !labeled[np4] && d[np4 * 4 + 3] > 0) { labeled[np4] = 1; queue[qt++] = np4; } }
                        }
                        if (qt - runStart < MIN_ISLAND_SIZE) {
                            for (var k = runStart; k < qt; k++) { d[queue[k] * 4 + 3] = 0; }
                        }
                        runStart = qt;
                    }

                    ctx.putImageData(frame, 0, 0);
                }
                if (keying) requestAnimationFrame(chromaKeyFrame);
            }

            function startKeying() {
                if (keying) return;
                keying = true;
                // Fires the logo's fade-in and the subtitle's zoom-in
                // together, right as the first real frame is about to be
                // drawn -- see the .is-playing rules above.
                document.querySelector('.splash-inner').classList.add('is-playing');
                requestAnimationFrame(chromaKeyFrame);
            }

            function stopKeying() {
                keying = false;
            }

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
                // frame -- no `loop`, no auto-advance. stopKeying() here
                // (rather than leaving the rAF loop running) matters now
                // that the splash can sit idle indefinitely waiting for a
                // click: chromaKeyFrame() redraws every frame regardless of
                // whether the video is still advancing, which cost nothing
                // over a brief hold and would be needless CPU otherwise. The
                // canvas keeps whatever it last drew.
                video.addEventListener('ended', function () {
                    stopKeying();
                    revealGetStarted();
                });
                video.addEventListener('playing', function () {
                    clearTimeout(fallbackTimer);
                    startKeying();
                });

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
