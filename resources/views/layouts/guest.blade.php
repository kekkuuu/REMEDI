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

        /* ── Signing in ──
           No skeleton here any more. It used to paint a full-screen mock of
           the dashboard over this page, but /dashboard now answers with a
           shell in ~0.3s and does its own branded wait (dashboard/_loading),
           so the skeleton was a grey impression of a page that the real
           loading screen was about to replace half a second later -- two
           different waiting states back to back, the throwaway one first.

           What survives is the part that was actually load-bearing: the click
           has to register, and it must not be possible to post the credentials
           twice while the redirect is in flight. */
        .auth-card button[disabled] { opacity: .75; cursor: default; }

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

<script>
    // Mark the sign-in as under way, and refuse a second one.
    //
    // The whole visible wait now lives on the other side of the redirect, in
    // the dashboard's own loading screen -- this only has to cover the POST
    // and the redirect after it, which is well under a second. So: no overlay,
    // no skeleton, just a button that shows it heard the click.
    (function () {
        var card = document.querySelector('.auth-card');
        if (!card) return;

        var pending = false;

        function release() {
            pending = false;
            Array.prototype.forEach.call(
                card.querySelectorAll('button[type=submit][disabled]'),
                function (b) {
                    b.disabled = false;
                    if (b.dataset.label) b.textContent = b.dataset.label;
                }
            );
        }

        card.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.hasAttribute('data-no-skeleton')) return;
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

            // Signing in is slow enough to invite a second click, and each one
            // posts the credentials again.
            if (pending) {
                e.preventDefault();
                return;
            }
            pending = true;

            setTimeout(function () {
                if (e.defaultPrevented) {
                    release();
                    return;
                }

                // Disabled only AFTER the browser has serialised and sent the
                // form -- doing it inside the handler can drop the submitter
                // from the POST body.
                Array.prototype.forEach.call(
                    form.querySelectorAll('button[type=submit]'),
                    function (b) {
                        b.dataset.label = b.textContent;
                        b.textContent = 'Signing you in…';
                        b.disabled = true;
                    }
                );
            }, 0);
        });

        // A bfcache restore must not bring back a dead disabled button.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) release();
        });
    })();
</script>

{{-- The opening intro (2026-10-23, at the user's request: the REMEDI logo
     reveal, public/assets/remedi-reveal.mp4, "before the login page"). Plays
     over the login page the FIRST time it is opened in a browser session
     (sessionStorage), so a wrong password or a sign-out does not replay ten
     seconds of video; never over an error or a status message. Muted, because
     a browser refuses to autoplay sound. It fades to the login form when the
     video ends or on Skip, and gets out of the way at once -- so it can never
     be what stops someone signing in -- if the video errors, is refused
     autoplay, has not started within 4 s, or the device asks for reduced
     motion. --}}
@if(request()->routeIs('login') && ! $errors->any() && ! session('status') && is_file(public_path('assets/remedi-reveal.mp4')))
<style>
    .intro {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: flex;
        align-items: flex-end;
        justify-content: center;
        background: #d4d4d4;        /* the video's own grey, before frame one */
        transition: opacity .5s ease;
    }
    .intro.is-leaving { opacity: 0; pointer-events: none; }
    .intro video {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;          /* the logo sits in the middle third */
    }
    .intro__skip {
        position: relative;
        margin: 0 16px 28px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 0;
        border-radius: 999px;
        padding: 8px 18px;
        background: rgba(15, 110, 86, .92);
        color: #fff;
        font: 600 14px 'Outfit', sans-serif;
        cursor: pointer;
        box-shadow: 0 8px 24px -12px rgba(15, 23, 42, .45);
    }
    .intro__skip:hover { background: #0b5a46; }
    .intro__skip:focus-visible { outline: 2px solid #0f6e56; outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .intro { display: none; } }
</style>
<div class="intro" id="intro">
    <video id="introVideo" src="{{ \App\Support\StaticAsset::url('assets/remedi-reveal.mp4') }}"
           muted playsinline autoplay preload="auto" disablepictureinpicture aria-hidden="true"></video>
    <button type="button" class="intro__skip" id="introSkip">Skip intro &rsaquo;</button>
</div>
<script>
    (function () {
        var el = document.getElementById('intro');
        var KEY = 'remedi.introPlayed';
        var played = false;
        try { played = sessionStorage.getItem(KEY) === '1'; sessionStorage.setItem(KEY, '1'); } catch (e) {}

        // Decided before the first paint (this runs straight after the
        // markup), so a repeat visit never flashes the intro.
        if (played || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            el.remove();
            return;
        }

        var video = document.getElementById('introVideo');
        var done = false;
        var html = document.documentElement, overflow = html.style.overflow;
        html.style.overflow = 'hidden';

        function finish() {
            if (done) return;
            done = true;
            clearTimeout(startGuard);
            html.style.overflow = overflow;
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 550);
            var email = document.getElementById('email');
            if (email) email.focus({ preventScroll: true });
        }

        var startGuard = setTimeout(function () { if (video.currentTime === 0) finish(); }, 4000);
        video.addEventListener('playing', function () { clearTimeout(startGuard); });
        video.addEventListener('ended', finish);
        video.addEventListener('error', finish);
        var playing = video.play();
        if (playing && playing.catch) playing.catch(finish);
        document.getElementById('introSkip').addEventListener('click', finish);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') finish(); });
    })();
</script>
@endif
</body>
</html>
