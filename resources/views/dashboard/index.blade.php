{{-- resources/views/dashboard/index.blade.php --}}
{{--
    The dashboard SHELL. Renders in ~200ms because it runs none of the
    dashboard's queries -- DashboardController only does that work on the AJAX
    branch, which this page then fetches and injects.

    The point is where the wait is spent. Previously the browser sat on the
    login page for the whole 5-12s dashboard render, so the app appeared to
    hang before it appeared at all. Now the sidebar, topbar and bell are on
    screen immediately and only the panel that is actually slow is pending.

    With JavaScript off this page never fetches anything, so <noscript> sends
    the browser to ?full=1, which renders the body synchronously the old way.
--}}
@extends('layouts.app')

@section('title', $isAdmin ? 'Dashboard' : 'Staff Dashboard')

@section('content')
<noscript>
    <meta http-equiv="refresh" content="0;url={{ route('dashboard', ['full' => 1]) }}">
    <p>Loading the dashboard&hellip;
        <a href="{{ route('dashboard', ['full' => 1]) }}">Continue</a>
    </p>
</noscript>

<style>
    /* ── Dashboard AJAX loader ──
       A floating card over a dimmed page. The scrim and the panel shadow are
       lifted from .remedi-modal in layouts/app.blade.php on purpose: the app
       already has a visual language for "something is in front of the page",
       and a loader that invents its own would read as a different kind of
       object for no reason.

       All of it is scoped under .dash-loader so nothing can leak into the
       dashboard body that replaces it. */
    #dashboardRoot { position: relative; }

    /* .page-skeleton is display:none by default so the layout's own copy only
       shows while navigating. Here it is the backdrop, so opt it in -- and let
       it fill the panel it stands in for, rather than collapsing to nothing
       behind the card. */
    #dashboardRoot > .page-skeleton { display: block; }

    /* Hidden by default: the scrim and its card are for two occasions only --
       the arrival straight after signing in, and a failure that has to be
       reported. Everything else gets the skeleton on its own. */
    .dash-loader { display: none; }

    #dashboardRoot.is-first-load .dash-loader,
    #dashboardRoot.is-failed .dash-loader { display: flex; }

    .dash-loader {
        position: fixed;
        inset: 0;
        z-index: 190;              /* under .remedi-modal (200): a real dialog
                                      still wins if one is somehow opened */
        /* display is set by the two gate rules above, not here -- putting
           `display: flex` in this block would come later in the sheet and
           silently defeat them. */
        align-items: center;
        justify-content: center;
        padding: 20px;
        overflow-y: auto;          /* short windows: never clip the card */
        background: rgba(15, 23, 42, .45);
        -webkit-backdrop-filter: blur(2px);
        backdrop-filter: blur(2px);
        animation: dash-scrim-in .16s ease-out;
    }

    @keyframes dash-scrim-in { from { opacity: 0; } to { opacity: 1; } }

    .dash-loader__card {
        position: relative;
        overflow: hidden;          /* clips the motifs and the wave */
        width: 100%;
        max-width: 460px;
        border-radius: 18px;
        background:
            radial-gradient(120% 90% at 50% 0%, #fbfffd 0%, #f2fbf7 55%, #eaf7f1 100%);
        box-shadow: 0 24px 60px -20px rgba(15, 23, 42, .45);
        animation: dash-card-in .2s ease-out;
    }

    @keyframes dash-card-in {
        from { opacity: 0; transform: translateY(10px) scale(.97); }
        to   { opacity: 1; transform: none; }
    }

    /* Motifs and wave are one colour each, set here and inherited through
       `currentColor` in the SVGs. */
    .dash-loader__motifs {
        position: absolute; inset: 0;
        width: 100%; height: 100%;
        color: rgba(16, 185, 129, .13);
        pointer-events: none;
    }

    .dash-loader__wave {
        position: absolute; left: 0; right: 0; bottom: 0;
        width: 100%; height: 38%;
        color: rgba(16, 185, 129, .09);
        pointer-events: none;
    }

    .dash-loader__stage {
        position: relative;
        text-align: center;
        padding: 44px 30px 40px;
    }

    .dash-loader__ring {
        position: relative;
        width: 150px;
        height: 150px;
        margin: 0 auto 24px;
        display: grid;
        place-items: center;
    }

    .dash-loader__ring svg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);   /* start the arc at 12 o'clock */
    }

    .dash-loader__track {
        fill: none;
        stroke: rgba(16, 185, 129, .16);
        stroke-width: 7;
    }

    /* The arc is a fixed slice of the circumference (2*pi*54 = 339.3) that
       spins. Indeterminate on purpose -- see the note in _loading. */
    .dash-loader__arc {
        fill: none;
        stroke: var(--brand);
        stroke-width: 7;
        stroke-linecap: round;
        stroke-dasharray: 95 340;
        transform-origin: 60px 60px;
        animation: dash-spin 1.15s linear infinite;
    }

    @keyframes dash-spin { to { transform: rotate(360deg); } }

    .dash-loader__logo {
        position: relative;
        width: 76px;
        height: 76px;
        object-fit: contain;   /* the lockup is not square; do not stretch it */
    }

    .dash-loader__title {
        font-family: 'Outfit', sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--ink);
        margin: 0 0 8px;
    }

    .dash-loader__sub {
        font-size: 14.5px;
        color: var(--ink-soft);
        margin: 0;
    }

    .dash-loader__dots {
        display: flex;
        gap: 9px;
        justify-content: center;
        margin-top: 22px;
    }

    .dash-loader__dots span {
        width: 9px; height: 9px;
        border-radius: 50%;
        background: var(--brand);
        opacity: .28;
        animation: dash-dot 1.25s ease-in-out infinite;
    }

    .dash-loader__dots span:nth-child(2) { animation-delay: .18s; }
    .dash-loader__dots span:nth-child(3) { animation-delay: .36s; }

    @keyframes dash-dot {
        0%, 70%, 100% { opacity: .28; transform: translateY(0); }
        35%           { opacity: 1;   transform: translateY(-4px); }
    }

    /* ── Failure ── */
    .dash-loader__failed {
        position: relative;
        text-align: center;
        padding: 44px 30px 40px;
    }

    .dash-loader__failed-icon {
        width: 62px; height: 62px;
        margin: 0 auto 18px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        background: #fee2e2;
        color: #b91c1c;
        font-size: 28px;
    }

    .dash-loader__actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    /* One class flips the loader between waiting and failed. */
    #dashboardRoot.is-failed .dash-loader__stage { display: none; }

    /* .topbar-loading and its dash-pulse keyframes moved to
       layouts/app.blade.php: every page raises this pill while a sidebar
       navigation is in flight, so it cannot live in one page's style block.
       This page still builds its own pill in JS below -- same class, same
       look, different occasion (an AJAX body rather than a page load). */

    @media (max-width: 640px) {
        .dash-loader__stage,
        .dash-loader__failed { padding: 34px 20px 30px; }
        .dash-loader__ring { width: 122px; height: 122px; margin-bottom: 18px; }
        .dash-loader__logo { width: 62px; height: 62px; }
        .dash-loader__title { font-size: 20px; }
    }

    /* Motion is decorative here; the text carries the meaning either way. */
    @media (prefers-reduced-motion: reduce) {
        .dash-loader,
        .dash-loader__card,
        .dash-loader__arc,
        .dash-loader__dots span { animation: none; }
        .dash-loader__arc { stroke-dasharray: 250 340; }
    }
</style>

{{-- is-first-load is what makes the branded card appear. Without it the
     page still fetches its body the same way and still shows the skeleton --
     it just does it quietly, which is all a revisit needs. --}}
<div id="dashboardRoot" data-dashboard-url="{{ route('dashboard') }}"
     class="{{ $justSignedIn ? 'is-first-load' : '' }}">
    @include('dashboard._loading')
</div>

<script>
(function () {
    var root = document.getElementById('dashboardRoot');
    if (!root) return;

    var url     = root.dataset.dashboardUrl;
    var titleEl = document.getElementById('dashLoaderTitle');
    var subEl   = document.getElementById('dashLoaderSub');
    var failed  = document.getElementById('dashLoaderFailed');
    var errorEl = document.getElementById('dashLoaderError');
    var timers  = [];

    // Mirrors the topbar pill in the mockup. Built here rather than in the
    // layout so the layout stays generic -- only this page has an AJAX body.
    var pill = document.createElement('span');
    pill.className = 'topbar-loading';
    pill.textContent = 'Loading your dashboard…';
    var topbarLeft = document.querySelector('.topbar-left');
    if (topbarLeft) topbarLeft.appendChild(pill);

    // Same reasoning as the sign-in skeleton: a single frozen line spends a
    // ten-second wait looking stalled, so say what is actually slow. The
    // numbers come from measuring this route -- ~5s warm, 10-12s cold.
    var STEPS = [
        [6000,  'Still crunching the numbers…', 'Totalling sales and stock across the catalogue.'],
        [15000, 'Almost there…',                'This load is rebuilding cached figures, which is slower than usual.']
    ];

    function clearTimers() { timers.forEach(clearTimeout); timers = []; }

    function startSteps() {
        clearTimers();

        // Nothing to escalate when the card is not on screen: on a revisit the
        // skeleton is the whole story, and rewriting text nobody can see just
        // leaves timers running.
        if (!root.classList.contains('is-first-load')) return;

        STEPS.forEach(function (step) {
            timers.push(setTimeout(function () {
                if (titleEl) titleEl.textContent = step[1];
                if (subEl)   subEl.textContent   = step[2];
            }, step[0]));
        });
    }

    function fail(message) {
        clearTimers();
        pill.remove();

        // Re-resolve rather than trusting the captured references: a retry
        // replaces the whole loader, so the originals are detached by then and
        // writing to them paints nothing.
        var panel = document.getElementById('dashLoaderFailed');
        var slot  = document.getElementById('dashLoaderError');

        // No panel means the body was already injected and is on screen. A
        // failure at that point is not worth destroying a working dashboard
        // to report -- the user has the page they asked for.
        if (!panel) return;

        root.classList.add('is-failed');
        if (slot) slot.textContent = message;
        panel.hidden = false;
    }

    /**
     * Run the <script> tags inside an injected fragment.
     *
     * innerHTML never executes scripts, so the dashboard's Chart.js block
     * would otherwise be inert markup and every chart would come up blank.
     * Re-creating each node makes it run -- but only in ORDER and only one at
     * a time: the inline block calls `new Chart(...)` at parse time, so if the
     * CDN <script src> were still in flight when it ran, Chart would be
     * undefined and the whole block would throw. Hence the await-on-load.
     */
    function runScripts(container) {
        var scripts = Array.prototype.slice.call(container.querySelectorAll('script'));

        return scripts.reduce(function (chain, old) {
            return chain.then(function () {
                return new Promise(function (resolve) {
                    var s = document.createElement('script');

                    Array.prototype.forEach.call(old.attributes, function (a) {
                        s.setAttribute(a.name, a.value);
                    });

                    if (old.src) {
                        // Resolve either way: one dead CDN should cost its own
                        // charts, not the rest of the page.
                        s.onload = s.onerror = function () { resolve(); };
                        old.parentNode.replaceChild(s, old);
                    } else {
                        s.textContent = old.textContent;
                        old.parentNode.replaceChild(s, old);
                        resolve();
                    }
                });
            });
        }, Promise.resolve());
    }

    function load() {
        root.classList.remove('is-failed');

        var panel = document.getElementById('dashLoaderFailed');
        if (panel) panel.hidden = true;

        // fetch() has no default timeout, and this route legitimately takes
        // 5-12s -- so "slow" and "dead" look identical from here. Without a
        // ceiling a wedged request leaves the ring turning for as long as the
        // tab is open and the failure panel, which exists precisely so the
        // wait cannot become a dead end, never appears. 45s is well past the
        // worst measured cold load and well short of a user's patience.
        var TIMEOUT_MS = 45000;
        var controller = ('AbortController' in window) ? new AbortController() : null;
        var timedOut = false;

        var killer = setTimeout(function () {
            timedOut = true;
            if (controller) {
                controller.abort();
            } else {
                fail('The dashboard took too long to answer.');
            }
        }, TIMEOUT_MS);

        var settled = function () { clearTimeout(killer); };

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
        .then(function (res) {
            settled();
            // EnsureUserIsActive answers AJAX with 401 rather than a redirect,
            // so a session deactivated mid-wait lands here. Send them to the
            // login page it would have redirected to.
            if (res.status === 401 || res.status === 419) {
                window.location.href = '{{ route('login') }}';
                return null;
            }
            if (!res.ok) throw new Error('The server answered ' + res.status + '.');

            // fetch follows redirects, so an expired session can arrive here as
            // a 200 carrying the login PAGE. Parsing that as JSON fails with
            // "Unexpected token '<'", which tells the user nothing. Check what
            // actually came back and say something true instead.
            var type = res.headers.get('content-type') || '';

            if (type.indexOf('application/json') === -1) {
                throw new Error('The server sent a page instead of dashboard data — your session may have ended.');
            }

            return res.json();
        })
        .then(function (data) {
            if (!data) return;
            if (!data.html) throw new Error('The response was empty.');

            clearTimers();
            root.innerHTML = data.html;

            return runScripts(root).then(function () {
                pill.remove();
                // Let anything listening (the bell, charts that size to their
                // container) know the page grew.
                window.dispatchEvent(new Event('resize'));
                // The body is on the page and the loader is finished. The
                // startup alert toasts wait for this before spending their
                // 5s window, which would otherwise elapse behind the loading
                // card; see "Startup alert toasts" in layouts/app.
                window.dispatchEvent(new CustomEvent('remedi:dashboard-ready'));
            });
        })
        .catch(function (err) {
            settled();

            if (timedOut || (err && err.name === 'AbortError')) {
                fail('The dashboard took longer than 45 seconds to answer, so the request was stopped.');

                return;
            }

            fail(err && err.message ? err.message : 'The request didn’t come back.');
        });
    }

    // Kept from the server-rendered markup rather than re-rendered into the
    // script, so retrying costs nothing and the page carries one copy.
    var loaderHtml = root.innerHTML;

    // The loader markup is replaced wholesale on retry, so every handle into
    // it -- including the retry button itself -- has to be re-found.
    function bindLoader() {
        titleEl = document.getElementById('dashLoaderTitle');
        subEl   = document.getElementById('dashLoaderSub');
        failed  = document.getElementById('dashLoaderFailed');
        errorEl = document.getElementById('dashLoaderError');

        var again = document.getElementById('dashRetry');
        if (!again) return;

        again.addEventListener('click', function () {
            root.innerHTML = loaderHtml;
            bindLoader();
            if (topbarLeft) topbarLeft.appendChild(pill);
            startSteps();
            load();
        });
    }

    bindLoader();
    startSteps();
    load();
})();
</script>
@endsection
