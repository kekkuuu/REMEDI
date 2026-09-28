// REMEDI shared page script -- moved out of layouts/app.blade.php (2026-09-28).
// This file IS the source now. Server values arrive in window.REMEDI_BOOT,
// set inline by the layout just before this file loads.
    // Sidebar show/hide toggle, persisted across page loads.
    (function () {
        var toggleBtn = document.getElementById('sidebarToggleBtn');
        if (!toggleBtn) return;

        var isMobile = function () { return window.matchMedia('(max-width: 767px)').matches; };

        function setHidden(next) {
            document.documentElement.setAttribute('data-sidebar-hidden', next ? 'true' : 'false');
            // Don't let a phone's transient drawer state overwrite the saved
            // desktop preference.
            if (!isMobile()) {
                localStorage.setItem('remedi_sidebar_hidden', next ? 'true' : 'false');
            }
        }

        toggleBtn.addEventListener('click', function () {
            setHidden(document.documentElement.getAttribute('data-sidebar-hidden') !== 'true');
        });

        var scrim = document.getElementById('sidebarScrim');
        if (scrim) scrim.addEventListener('click', function () { setHidden(true); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isMobile()) setHidden(true);
        });

        // Tapping a nav link navigates; leaving the drawer open over the
        // outgoing page (and the skeleton) just hides what you asked for.
        var sidebarEl = document.querySelector('.sidebar');
        if (sidebarEl) {
            sidebarEl.addEventListener('click', function (e) {
                if (isMobile() && e.target.closest('a')) setHidden(true);
            });
        }
    })();


    // ── Shared list-loading skeleton ──
    // Every list page (POS, inventory, products, sales, forecast) refreshes
    // #results-wrapper over AJAX. Without this the stale rows just sit there
    // until the response lands, so typing in a search box looked inert.
    window.REMEDI = window.REMEDI || {};

    /**
     * Whichever element actually scrolls the page.
     *
     * The app has scrolled from the document in some layouts and from
     * .content-body in others, and every "keep my place" fix needs the real
     * one -- setting scrollTop on a element that does not scroll is a silent
     * no-op, which is exactly how a tab click ends up back at the top.
     */
    REMEDI.scroller = function () {
        var candidates = [document.querySelector('.content-body'),
                          document.querySelector('.main-content')];

        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            if (el && el.scrollHeight - el.clientHeight > 1) return el;
        }

        return document.scrollingElement || document.documentElement;
    };

    /**
     * Capture the scroll offset now, put it back after the DOM has been
     * rewritten.
     *
     * Every list page swaps its rows over fetch, and the offset has to be read
     * BEFORE the swap and written to the SAME element afterwards — resolve the
     * scroller after the rows are gone and the page may no longer overflow, so
     * REMEDI.scroller() answers with a different element and the write lands on
     * something that does not scroll.
     *
     * If the new list is genuinely shorter than the old offset the browser
     * clamps, and that is correct: there is no row 40 to return to.
     */
    REMEDI.holdScroll = function () {
        var el = REMEDI.scroller();
        var y = el.scrollTop;

        return function () { el.scrollTop = y; };
    };

    /**
     * Lock the page behind a modal without losing the reader's place.
     *
     * Every dialog in the app sets body overflow to hidden so the page behind
     * it cannot scroll. That collapses the document's scrollable overflow, the
     * browser clamps the offset to zero, and restoring overflow on close leaves
     * you at the top -- measured: open the confirm dialog 300px down a list,
     * cancel it, and you are back at row 1. The dialog had not even done
     * anything yet.
     *
     * Returns the unlock, so callers cannot restore the overflow and forget the
     * offset.
     */
    REMEDI.lockScroll = function () {
        var el = REMEDI.scroller();
        var y = el.scrollTop;

        document.body.style.overflow = 'hidden';

        return function () {
            document.body.style.overflow = '';
            el.scrollTop = y;
        };
    };

    /**
     * Keep the reader's place across a full page reload.
     *
     * Every `js-confirm` action without an explicit data-on-success falls back
     * to window.location.reload() — marking a batch returned, adding a batch,
     * deleting a category. On a 645-row inventory list that meant clicking
     * "Return" on row 40 and coming back at row 1, with the row you just acted
     * on somewhere off screen. That is the single most common "why did it jump
     * to the top" in the app.
     *
     * Stamped per URL, consumed once, and expiring: a reload lands within
     * milliseconds, so anything older is a later visit that should open where
     * the browser would normally open it.
     */
    var SCROLL_KEY = 'remedi_scroll';

    REMEDI.reloadKeepingPlace = function () {
        try {
            sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
                url: location.href,
                y: REMEDI.scroller().scrollTop,
                at: Date.now(),
            }));
        } catch (e) { /* private mode: reload without the courtesy */ }

        window.location.reload();
    };

    (function restoreScrollAfterReload() {
        var raw = null;
        try { raw = sessionStorage.getItem(SCROLL_KEY); } catch (e) { return; }
        if (!raw) return;
        try { sessionStorage.removeItem(SCROLL_KEY); } catch (e) { /* consumed either way */ }

        var saved;
        try { saved = JSON.parse(raw); } catch (e) { return; }
        if (!saved || saved.url !== location.href || Date.now() - saved.at > 10000) return;

        /* Retry until it sticks, rather than firing once and hoping.
           Measured: a single attempt two frames in lands while the document is
           still at viewport height, so the offset clamps to 0 and the whole
           courtesy silently does nothing. The page reaches full height a few
           frames later (table layout, web fonts, the charts' first draw), and
           REMEDI.scroller() can answer with a different element until it does.

           Stops as soon as the offset holds, after ~20 frames, or the moment
           the reader scrolls for themselves — fighting someone for control of
           the scrollbar is worse than losing their place. */
        var tries = 0;
        var cancelled = false;

        function stopRestoring() { cancelled = true; }

        ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function (evt) {
            window.addEventListener(evt, stopRestoring, { once: true, passive: true });
        });

        (function attempt() {
            if (cancelled) return;

            var el = REMEDI.scroller();
            el.scrollTop = saved.y;

            if (Math.abs(el.scrollTop - saved.y) <= 2 || ++tries > 20) {
                ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach(function (evt) {
                    window.removeEventListener(evt, stopRestoring);
                });
                return;
            }

            requestAnimationFrame(attempt);
        })();
    })();

    REMEDI.showListSkeleton = function (wrapper, opts) {
        if (!wrapper) return;
        opts = opts || {};
        var rows = opts.rows || 6;
        var grid = !!opts.grid;

        // Match the height of what's being replaced so the page doesn't jump.
        var current = wrapper.getBoundingClientRect().height;

        var html = '<div class="list-skeleton' + (grid ? ' is-grid' : '') + '">';
        for (var i = 0; i < rows; i++) html += '<div class="sk-block sk-line"></div>';
        html += '</div>';

        wrapper.style.minHeight = current > 0 ? current + 'px' : '';
        wrapper.innerHTML = html;
    };

    REMEDI.clearListSkeleton = function (wrapper) {
        if (wrapper) wrapper.style.minHeight = '';
    };

    // ── Live search ──
    // The suggestion DROPDOWN was removed by request: results now appear only
    // where they belong — the page's own table, or the POS product grid. This
    // keeps the real-time behaviour (debounced, aborts in-flight requests)
    // and just fires `suggest:live` so each page refreshes its own list.
    //
    // data-suggest-url is still honoured, but purely to decide that a field
    // is a live one; nothing is rendered under it.
    REMEDI.attachSuggest = function (input, options) {
        if (!input || input.dataset.suggestBound === '1') return;
        input.dataset.suggestBound = '1';
        options = options || {};

        var minChars = options.minChars || 2;
        var timer = null;

        function fire() {
            var term = input.value.trim();
            if (term.length && term.length < minChars) return;
            input.dispatchEvent(new CustomEvent('suggest:live', { detail: { term: term }, bubbles: true }));
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(fire, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { clearTimeout(timer); fire(); }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-suggest-url]').forEach(function (el) {
            REMEDI.attachSuggest(el);
        });
    });

    // Drop the animation suppressor once the first paint is done.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.documentElement.classList.remove('nav-no-anim');
        });
    });

    // Navigation feedback: mark the clicked sidebar item and swap in a
    // skeleton, so a click on a slow page reads as "loading" instead of
    // "nothing happened".
    (function () {
        var sidebar = document.querySelector('.sidebar');
        var contentBody = document.querySelector('.content-body');
        if (!sidebar || !contentBody) return;

        /* How long a navigation must already be taking before a placeholder is
           worth showing. Below this the next page has effectively arrived, and
           painting one would only flicker. */
        var SKELETON_DELAY_MS = 180;
        var paint = null;

        var topbarTitle = document.querySelector('.topbar-left h2');
        var originalTitle = topbarTitle ? topbarTitle.textContent : null;
        var deactivated = [];
        // Whether a navigation is already under way. A second click supersedes
        // the first rather than stacking on top of it -- see showNavigating().
        var navigating = false;

        // The visible label of a nav item, minus its icon and (for category
        // sub-links) the trailing product count.
        function navLabel(link) {
            var clone = link.cloneNode(true);
            Array.prototype.forEach.call(clone.querySelectorAll('.nav-sub-count'), function (el) {
                el.parentNode.removeChild(el);
            });
            return clone.textContent.replace(/\s+/g, ' ').trim();
        }

        function showNavigating(link) {
            // Click one item, then another before the first page arrives, and
            // the browser lands you on the SECOND one -- a later navigation
            // supersedes the earlier. The highlight has to say the same thing.
            //
            // It used to just add to whatever was already marked, so both items
            // sat there lit up and you could not tell which page was actually
            // coming. Clear the previous mark first: only the most recent click
            // reads as loading.
            Array.prototype.forEach.call(sidebar.querySelectorAll('.is-loading'), function (el) {
                el.classList.remove('is-loading');
            });

            // Retitle the header to where we're GOING, but only for sidebar
            // items -- an in-content button's label ("Edit", "Generate") is
            // not the name of the destination page.
            if (sidebar.contains(link)) {
                var label = navLabel(link);
                if (topbarTitle && label) {
                    topbarTitle.textContent = label;
                }

                // Only capture the active set on the FIRST click of a
                // navigation. The first call already stripped those classes, so
                // re-reading here would store an empty list and clearNavigating()
                // would have nothing to put back -- which is what stranded the
                // sidebar with no highlight at all after a double click, and on
                // every bfcache restore that followed one.
                if (!navigating) {
                    deactivated = Array.prototype.slice.call(sidebar.querySelectorAll('.active'));
                    deactivated.forEach(function (el) { el.classList.remove('active'); });
                }
            }

            navigating = true;
            link.classList.add('is-loading');

            /* Everything the click PAINTS is delayed by SKELETON_DELAY_MS.

               Most pages here answer in a fraction of a second on a local
               server. Swapping the content out for a placeholder and back again
               inside that window is not feedback -- the page visibly collapses
               to the skeleton, the scroll offset is clamped, and it all snaps
               back as the next document paints. That is the "jumping up and
               popping" on every tab click.

               Waiting means a fast page never paints a skeleton at all: you
               click and the next page is simply there. A slow one still gets the
               full treatment, which is the case this was built for -- /dashboard
               takes 5-12s. clearNavigating() cancels the timer if the page
               arrives first, or if the navigation turns out not to be one. */
            paint = setTimeout(function () {
                paint = null;

                /* Dress the skeleton as the page being opened -- standing in
                   with the wrong shape makes the real page visibly jump
                   when it lands, which is what nine placeholder table rows
                   in front of POS's tile grid looked like before "pos" was
                   added, and what a plain 6-column table looked like in
                   front of Notifications' card feed or Categories' 3-column
                   list before this pass.

                   Four top-level shapes (dash/pos/feed/list), and "list"
                   is itself parametrized -- see the data-* attributes below
                   and the matching CSS + the comment inside
                   _page-skeleton.blade.php's sk-shape-list block -- because
                   Inventory, Products, Sales, Users, the audit trail and
                   Categories disagree with each other about a KPI row, tab
                   chips, toolbar shape and column count far more than they
                   agree with one plain "header + search + table". Checked
                   in order; the FIRST match wins, so put a more specific
                   route before a substring it would otherwise also match. */
                var navSkeleton = contentBody.querySelector('.page-skeleton');
                if (navSkeleton) {
                    var href = link.getAttribute('href') || '';
                    var routeConfigs = [
                        { test: /\/(dashboard|reports|forecast|sales-forecast)(\/|\?|#|$)/, shape: 'dash' },
                        { test: /\/pos(\/|\?|#|$)/, shape: 'pos' },
                        { test: /\/notifications(\/|\?|#|$)/, shape: 'feed' },
                        // 4 real KPI cards, no tabs, a plain search+Filter+Add
                        // toolbar, ~6-column table.
                        { test: /\/users(\/|\?|#|$)/, shape: 'list', header: 1, kpis: 4, toolbar: 'simple', tabs: 0, cols: 6 },
                        // No header on the real page; 2 KPI cards ("Total
                        // sales today" / "Transactions today"); a date-range
                        // toolbar richer than a bare search box.
                        { test: /\/sales(\/|\?|#|$)/, shape: 'list', header: 0, kpis: 2, toolbar: 'rich', tabs: 0, cols: 6 },
                        // 4 KPI cards (Total/Logins/Logouts/Views); the
                        // richest toolbar in the app (action + role + two
                        // dates + presets).
                        { test: /\/audit(\/|\?|#|$)/, shape: 'list', header: 1, kpis: 4, toolbar: 'rich', tabs: 0, cols: 6 },
                        // No header, no KPIs, no tabs; an 11-column table --
                        // the widest in the app.
                        { test: /\/products(\/|\?|#|$)/, shape: 'list', header: 0, kpis: 0, toolbar: 'simple', tabs: 0, cols: 11 },
                        // The "toolbar" is really an Add Category trigger
                        // card, and the table is a sparse 3 columns.
                        { test: /\/categories(\/|\?|#|$)/, shape: 'list', header: 1, kpis: 0, toolbar: 'card', tabs: 0, cols: 3 },
                        // No header, no KPIs; 7 real status filter chips
                        // (All/Low Stock/Expiring/Expired/Need to Return/
                        // Fail to Return/Returned); an 11-column table.
                        { test: /\/inventory(\/|\?|#|$)/, shape: 'list', header: 0, kpis: 0, toolbar: 'simple', tabs: 7, cols: 11 },
                    ];

                    var config = null;
                    for (var ci = 0; ci < routeConfigs.length; ci++) {
                        if (routeConfigs[ci].test.test(href)) { config = routeConfigs[ci]; break; }
                    }
                    // A route none of these match (custom pages, anything
                    // added later) falls back to the plainest "list" case
                    // rather than the busiest one.
                    config = config || { shape: 'list', header: 1, kpis: 0, toolbar: 'simple', tabs: 0, cols: 6 };

                    navSkeleton.dataset.shape = config.shape;
                    navSkeleton.dataset.header = config.header != null ? config.header : 1;
                    navSkeleton.dataset.kpis = config.kpis != null ? config.kpis : 0;
                    navSkeleton.dataset.toolbar = config.toolbar || 'simple';
                    navSkeleton.dataset.tabs = config.tabs != null ? config.tabs : 0;
                    navSkeleton.dataset.cols = config.cols != null ? config.cols : 6;
                }

                /* The header pill. The dashboard has always shown one while it
                   fetches its body; this puts the same marker on every other
                   page while the next document is in flight.

                   Its own id, so clearNavigating() removes THIS pill and never
                   the one dashboard/index builds for its AJAX body -- two
                   different waits, and the dashboard owns the end of its own. */
                var oldPill = document.getElementById('navLoadingPill');
                if (oldPill) oldPill.remove();

                var topbarLeft = document.querySelector('.topbar-left');
                if (topbarLeft) {
                    // navLabel() is the sidebar item's own text. An in-content
                    // button says "Edit" or "Generate", which is not the name of
                    // a page, so those get the bare form.
                    var dest = sidebar.contains(link) ? navLabel(link) : '';

                    var pill = document.createElement('span');
                    pill.className = 'topbar-loading';
                    pill.id = 'navLoadingPill';
                    pill.textContent = dest ? 'Loading ' + dest + '\u2026' : 'Loading\u2026';
                    topbarLeft.appendChild(pill);
                }

                /* Hold the page's height while the skeleton is up.
                   .is-navigating replaces the content with a short placeholder,
                   so the document collapses from (measured) 2398px to under a
                   viewport, the browser clamps the scroll offset to the new
                   maximum, and the reader is thrown up the page -- 900px to
                   324px on the dashboard. That is invisible when the navigation
                   lands, because the next document starts at the top anyway. It
                   is very visible when it does NOT land: a click that ends a
                   text selection, a link to the page you are already on, a
                   cancelled download. The page just jumped for nothing.

                   Same trick as REMEDI.showListSkeleton: pin the height, and let
                   clearNavigating() release it. */
                contentBody.style.minHeight = contentBody.getBoundingClientRect().height + 'px';
                contentBody.classList.add('is-navigating');

                // Force a synchronous style+layout flush. Once a navigation is
                // under way the browser is free to skip repainting the outgoing
                // document, which would leave the skeleton applied but never
                // shown. Reading a layout property makes it commit now.
                void contentBody.offsetHeight;
            }, SKELETON_DELAY_MS);
        }

        function clearNavigating() {
            navigating = false;

            // A navigation that resolved inside the delay never painted and
            // must not paint now.
            if (paint) { clearTimeout(paint); paint = null; }

            var pill = document.getElementById('navLoadingPill');
            if (pill) pill.remove();

            contentBody.classList.remove('is-navigating');
            contentBody.style.minHeight = '';
            sidebar.querySelectorAll('.is-loading').forEach(function (el) {
                el.classList.remove('is-loading');
            });

            // Put back everything showNavigating() changed — this runs when a
            // navigation is abandoned or the page returns from bfcache, where
            // the DOM is replayed exactly as we left it.
            if (topbarTitle && originalTitle !== null) {
                topbarTitle.textContent = originalTitle;
            }
            deactivated.forEach(function (el) { el.classList.add('active'); });
            deactivated = [];
        }

        document.addEventListener('click', function (e) {
            var link = e.target.closest('a');
            if (!link) return;

            // Let the browser handle anything that isn't a plain same-tab
            // navigation: modifier/middle clicks open new tabs, and a
            // handler elsewhere may still preventDefault this event.
            if (e.defaultPrevented) return;

            /* A click that merely ends a text selection is not a request to go
               anywhere. Selecting a product name inside a row -- and every row
               in this app is a link now -- fired this handler on mouseup, and
               the skeleton it raises hides the real content: any scroll
               container inside it loses its offset (measured on /notifications,
               the list jumped 200 -> 0), and the page collapses to the skeleton
               height. If the browser does navigate anyway the only thing lost
               is the skeleton, which is cosmetic. */
            var selection = window.getSelection();
            if (selection && !selection.isCollapsed) return;
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (link.target && link.target !== '_self') return;
            if (link.hasAttribute('download')) return;

            // Downloads/exports don't replace the page, so they must not blank
            // it. Everything else that navigates gets the skeleton.
            if (link.closest('[data-no-skeleton]')) return;

            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;

            var url;
            try {
                url = new URL(link.href, window.location.href);
            } catch (err) {
                return;
            }

            if (url.origin !== window.location.origin) return;
            // Clicking the page you're already on navigates but repaints
            // instantly; a skeleton would just flicker.
            if (url.href === window.location.href) return;

            showNavigating(link);
        });

        /* The skeleton + header pill are raised by the document click handler
           above, which only fires for <a>. The Inventory nav toggle is a
           <button> that navigates, so it has to ask for the same treatment by
           hand -- without this it jumped straight to the next page with no
           loading state at all while its submenu was still animating open,
           which read as a glitch. */
        window.REMEDI = window.REMEDI || {};
        window.REMEDI.showNavigating = showNavigating;

        /* mm/dd/yyyy inside every EMPTY date field.
           ---------------------------------------------------------------
           A native <input type="date"> cannot be given a placeholder: the
           attribute is ignored, and the grey text it shows when empty is drawn
           by the BROWSER from its own locale. Nothing in the document changes
           that -- verified by rendering four date inputs with no lang, en-US,
           en-GB and en-CA: Chrome printed mm/dd/yyyy in all four, because it
           follows its own UI language. A register whose browser runs a
           dd/mm/yyyy locale would show dd/mm/yyyy, and the markup could not
           say otherwise.

           So an EMPTY date field is carried as a text input holding a real
           placeholder, and becomes a date input the moment it is focused. The
           swap only ever happens while the field is empty, so no value is at
           risk, and the element keeps its name, its classes, its inline styles
           and its min/max the whole time -- what the form posts is still the
           Y-m-d a date input submits, because by the time anything is typed it
           IS a date input again.

           A field that already holds a value keeps type="date" and is never
           touched: a value is not a placeholder, and the browser draws it in
           the user's own format. */
        (function () {
            var FORMAT = 'mm/dd/yyyy';

            function toPlaceholder(input) {
                if (input.value) return;                  // a value is not a placeholder
                input.dataset.datePlaceholder = '1';
                input.type = 'text';
                input.placeholder = FORMAT;
                input.classList.add('date-placeholder');
            }

            function toDate(input) {
                input.type = 'date';
                input.removeAttribute('placeholder');
                input.classList.remove('date-placeholder');
            }

            function scan(root) {
                (root || document).querySelectorAll('input[type="date"]').forEach(toPlaceholder);
            }

            // focusin, not focus: focus does not bubble, and these fields are
            // inside forms that get re-rendered.
            document.addEventListener('focusin', function (e) {
                var el = e.target;
                if (!el.dataset || el.dataset.datePlaceholder !== '1') return;
                if (el.type === 'date') return;

                toDate(el);

                // Open the picker on the click that focused it, so the swap is
                // invisible to the user rather than costing them a second click.
                // Not every browser has showPicker, and it throws without a user
                // gesture (a Tab into the field), which is not an error here.
                if (typeof el.showPicker === 'function') {
                    try { el.showPicker(); } catch (err) { /* no gesture: fine */ }
                }
            });

            // Left empty again -- put the placeholder back.
            document.addEventListener('focusout', function (e) {
                var el = e.target;
                if (el.dataset && el.dataset.datePlaceholder === '1' && !el.value) toPlaceholder(el);
            });

            scan();

            // The list pages swap their rows (and the filter bars around them)
            // over fetch, and the confirm dialog re-renders forms, so new date
            // fields appear after load. One observer is cheaper than asking
            // every page to remember to call this.
            if (window.MutationObserver) {
                new MutationObserver(function (records) {
                    for (var i = 0; i < records.length; i++) {
                        for (var j = 0; j < records[i].addedNodes.length; j++) {
                            var node = records[i].addedNodes[j];
                            if (node.nodeType !== 1) continue;
                            if (node.matches && node.matches('input[type="date"]')) toPlaceholder(node);
                            else if (node.querySelectorAll) scan(node);
                        }
                    }
                }).observe(document.body, { childList: true, subtree: true });
            }
        })();

        /* Password reveal. Delegated from the document, so it works on every
           form page without each one shipping its own handler -- and keeps
           working if a form is ever re-rendered over AJAX. */
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.pw-toggle');
            if (!toggle) return;

            var field = document.getElementById(toggle.dataset.pwToggle);
            if (!field) return;

            var shown = field.type === 'text';
            field.type = shown ? 'password' : 'text';
            toggle.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');

            var icon = toggle.querySelector('i');
            if (icon) icon.className = 'ti ' + (shown ? 'ti-eye' : 'ti-eye-off');
        });

        /* Strength meter under a NEW-password field. Delegated the same way
           the reveal toggle above is -- works on every form page with no
           per-page script, including one re-rendered over AJAX -- and
           looks for a sibling element carrying data-pw-strength-for="<the
           input's id>" rather than assuming a fixed markup shape, so each
           form places the meter wherever it makes sense.
           No library: length plus a rough count of character classes
           (lower/upper/digit/symbol) is enough for a weak/normal/strong
           hint without shipping something like zxcvbn for one small cue. */
        function passwordStrength(pw) {
            if (!pw) return null;
            var score = 0;
            if (pw.length >= 8) score++;
            if (pw.length >= 12) score++;
            if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
            if (/\d/.test(pw)) score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            if (pw.length < 8 || score <= 1) return 'weak';
            if (score >= 4) return 'strong';
            return 'normal';
        }

        document.addEventListener('input', function (e) {
            var input = e.target;
            if (input.tagName !== 'INPUT' || input.type !== 'password' || !input.id) return;
            var meter = document.querySelector('[data-pw-strength-for="' + input.id + '"]');
            if (!meter) return;

            var strength = passwordStrength(input.value);
            meter.hidden = !strength;
            meter.classList.remove('is-weak', 'is-normal', 'is-strong');
            if (!strength) return;
            meter.classList.add('is-' + strength);
            var label = meter.querySelector('.pw-strength-label');
            if (label) label.textContent = strength === 'weak' ? 'Weak' : strength === 'normal' ? 'Normal' : 'Strong';
        });

        // Full-page form submits (filters, save, delete) also replace the
        // document, so they get the same treatment. AJAX forms call
        // preventDefault, which we check for on the next tick.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.hasAttribute('data-no-skeleton')) return;
            if (form.target && form.target !== '_self') return;

            setTimeout(function () {
                if (!e.defaultPrevented) showNavigating(form);
            }, 0);
        });

        // Restoring from the back/forward cache replays the old DOM, which
        // still carries the skeleton state from when we left the page.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) clearNavigating();
        });

        // If the navigation never completes (cancelled download, blocked
        // request), don't strand the user on a skeleton forever.
        window.addEventListener('beforeunload', function () {
            setTimeout(clearNavigating, 8000);
        });
    })();

    /* ── Server-rendered flashes open the same dialog ────────────────────
       "Product saved", "Cannot delete a category that has products", a list of
       validation errors: all of them used to be a banner at the top of the
       content, which on a long form meant the answer to what you just did sat
       above the fold while you were still looking at the field you fixed.

       The banner is still rendered server-side and still readable without
       JavaScript -- this promotes it to the dialog and hides the banner, so
       there is exactly one place outcomes appear. Runs after the layout's own
       script has defined showMessage. */
    document.addEventListener('DOMContentLoaded', function () {
        var banners = document.querySelectorAll('.content-body [data-flash]');
        if (!banners.length) return;

        // One dialog, not four stacked on top of each other. An error anywhere
        // in the set decides the tone: a page can carry a success flash AND a
        // validation failure at once (saved one thing, rejected another), and
        // heading that "Done" over a list of what went wrong reads as the
        // opposite of what happened.
        var banner_list = Array.prototype.slice.call(banners);
        var errorBanner = banner_list.filter(function (b) { return b.dataset.flash === 'error'; })[0];
        var kind = errorBanner ? 'error' : 'success';
        var titleSource = errorBanner || banner_list[0];
        var lines = [];

        banners.forEach(function (banner) {
            banner.querySelectorAll('li').forEach(function (li) {
                lines.push(li.textContent.trim());
            });
            var span = banner.querySelector(':scope > span');
            if (span) lines.push(span.textContent.trim());
            banner.hidden = true;
            banner.style.display = 'none';   // .alert sets display, so [hidden] alone loses
        });

        if (!lines.length) return;

        REMEDI.showMessage({
            title: titleSource.dataset.flashTitle || (kind === 'success' ? 'Done' : 'Could not complete that'),
            // A single line reads better as the body; several belong in a list
            // under a heading that says what they are.
            body: lines.length === 1 ? lines[0] : '',
            list: lines.length > 1 ? lines : [],
            icon: kind === 'success' ? 'ti-circle-check' : 'ti-alert-circle',
            tone: kind === 'success' ? 'neutral' : 'danger',
        });
    });

    /* ── Rows that behave like links ─────────────────────────────────────
       `tr.clickable-row[data-href]`, delegated once here rather than an inline
       onclick per row. The guards are the ones every link in this app gets:

       - a click that ends a text selection is not a navigation (selecting a SKU
         to copy it used to open the forecast detail page instead);
       - modified and middle clicks belong to the browser, so the row can be
         opened in a new tab like any other link;
       - a click that started on a real control inside the row (the product
         link, a button, a form) is that control's, not the row's.

       The row still carries a real <a> in its first cell, so this is an
       enhancement rather than the only way through. */
    document.addEventListener('click', function (e) {
        var row = e.target.closest('tr.clickable-row[data-href]');
        if (!row) return;
        if (e.defaultPrevented || e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (e.target.closest('a, button, input, select, label, form')) return;

        var selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;

        window.location = row.dataset.href;
    });

    /* ── Message popup ───────────────────────────────────────────────────
       REMEDI.showMessage({ title, body, icon, tone }) -> { setDetail, close }

       The replacement for window.alert(). A browser alert reads as a browser
       error rather than as the app talking, blocks the tab until it is
       dismissed, and can only ever repeat what the page already knew. This is
       the same card the confirm dialog uses, and the returned handle lets the
       caller fill in a detail line once a server round-trip answers — which is
       how the POS turns "out of stock" into "0 on hand right now", checked
       against the database at the moment of the click. */
    REMEDI.showMessage = function (opts) {
        opts = opts || {};

        var modal = document.getElementById('messageModal');
        if (!modal) {                       // no layout chrome (guest pages)
            return { setDetail: function () {}, close: function () {} };
        }

        var titleEl = document.getElementById('messageModalTitle');
        var bodyEl = document.getElementById('messageModalBody');
        var stateEl = document.getElementById('messageModalState');
        var listEl = document.getElementById('messageModalList');
        var iconWrap = document.getElementById('messageModalIcon');
        var okBtn = document.getElementById('messageModalOk');
        var lastFocus = document.activeElement;
        var unlock = null;

        titleEl.textContent = opts.title || 'Notice';
        bodyEl.textContent = opts.body || '';

        iconWrap.innerHTML = '';
        var i = document.createElement('i');
        i.className = 'ti ' + (opts.icon || 'ti-alert-triangle');
        i.setAttribute('aria-hidden', 'true');
        iconWrap.appendChild(i);
        // Neutral (blue) unless the caller says otherwise: running out of stock
        // is information, not a destructive act, and a red disc on every
        // mis-tap at the register reads as an error the cashier caused.
        iconWrap.classList.toggle('is-neutral', opts.tone !== 'danger');

        // A list of lines (validation errors, mostly). textContent per item:
        // these carry user input and server messages.
        listEl.innerHTML = '';
        var lines = opts.list || [];
        lines.forEach(function (line) {
            var li = document.createElement('li');
            li.textContent = line;
            listEl.appendChild(li);
        });
        listEl.hidden = lines.length === 0;

        if (opts.detail) {
            stateEl.textContent = opts.detail;
            stateEl.hidden = false;
        } else {
            stateEl.textContent = '';
            stateEl.hidden = true;
        }

        function close() {
            modal.classList.remove('is-open');
            if (unlock) { unlock(); unlock = null; }
            okBtn.removeEventListener('click', close);
            modal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKey);
            if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
        }

        function onBackdrop(e) { if (e.target === modal) close(); }
        function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') close(); }

        okBtn.addEventListener('click', close);
        modal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKey);

        modal.classList.add('is-open');
        unlock = REMEDI.lockScroll();
        // preventScroll: focusing a control inside a fixed overlay still lets
        // the browser scroll the document to "reveal" it, which lands the page
        // at the top -- the dialog opens and the list behind it jumps to row 1.
        okBtn.focus({ preventScroll: true });

        return {
            // Called when a lookup lands after the modal is already up. Guarded
            // on is-open: the cashier may have dismissed it in the meantime,
            // and a late response must not reopen or rewrite a closed dialog.
            setDetail: function (text) {
                if (!modal.classList.contains('is-open')) return;
                stateEl.textContent = text;
                stateEl.hidden = !text;
            },
            close: close,
        };
    };

    /* ── Notification bell ──────────────────────────────────────────────────
       The panel is rendered server-side, so it is already correct before this
       runs; everything here is about keeping it current and making it open.

       "Real time" here is polling, not push. This app is plain PHP under
       Apache with no queue worker, no broadcast driver and no websocket server
       (see "Frontend" in REMEDI.md), so SSE or websockets would mean standing
       up infrastructure the rest of the stack does not have. What the alerts
       actually track -- stock crossing a reorder level, a batch entering its
       expiry window -- moves on the scale of a sale or a delivery, and
       AlertService::forget() drops the cache the moment a checkout or a batch
       edit happens, so the next poll is accurate rather than merely recent. */
    /* ── Read / unread store ─────────────────────────────────────────────
       Which notifications this user has already looked at, kept per user in
       localStorage. Deliberately client-side: "seen it" is a per-person,
       per-device fact, not shared state — marking one read at the register must
       not clear it on the manager's screen. Ids come from AlertService and are
       keyed to the batch/product, so they survive the list reordering between
       polls.

       Capped at 200: alert ids churn as stock moves, and an uncapped list would
       grow forever in a browser that never clears storage.

       It lives out here, above the bell, because two surfaces paint from it —
       the dropdown and the full /notifications page, which renders the same
       rows. One owner of the key, one place that announces a change; each
       surface only listens and repaints. Defined before the bell's own guard so
       the page still has it on any layout where the bell is absent. */
    window.remediAlertReads = (function () {
        const KEY = 'remedi_alerts_read:' + window.REMEDI_BOOT.userId;
        const MAX = 200;

        function get() {
            try { return new Set(JSON.parse(localStorage.getItem(KEY) || '[]')); }
            catch (e) { return new Set(); }
        }

        function add(ids) {
            const set = get();
            const before = set.size;

            [].concat(ids).forEach(function (id) { if (id) set.add(id); });
            if (set.size === before) return;         // nothing new: no repaint

            try { localStorage.setItem(KEY, JSON.stringify([...set].slice(-MAX))); }
            catch (e) { /* private mode or quota: read state is a nicety, not load-bearing */ }

            // Repaint every surface showing these rows. An event rather than a
            // direct call so neither side has to know the other exists, or be
            // loaded at all.
            document.dispatchEvent(new CustomEvent('remedi:alerts-read'));
        }

        return { get: get, add: add };
    })();

    (function () {
        const wrap = document.getElementById('bellWrap');
        if (!wrap) return;

        const btn = document.getElementById('bellBtn');
        const panel = document.getElementById('bellPanel');
        const list = document.getElementById('bellList');
        const foot = document.getElementById('bellFoot');
        const countEl = document.getElementById('bellCount');
        const stamp = document.getElementById('bellStamp');

        // HALF AlertService::TTL_SECONDS (2026-09-27, was equal to it). Every
        // stock-moving action clears that cache outright (AlertService::forget),
        // so the TTL only bounds time-driven changes (a batch expiring at
        // midnight); what a poll interval bounds is how long a change made on
        // ANOTHER machine takes to reach this bell. 15s halves that, and every
        // other poll is served from the cache the first one warmed, so the
        // expensive rebuild does not run any more often than before.
        const POLL_MS = 15000;
        const markReadBtn = document.getElementById('bellMarkRead');

        // The store above, shared with /notifications.
        const reads = window.remediAlertReads;

        /* Paints every row and drives the badge. The badge counts UNREAD, not
           total: a badge that keeps showing 12 after you have read all twelve
           is what makes people stop looking at it. */
        function applyReadState(reorder) {
            const read = reads.get();
            // Every row, including ones the current tab is hiding: the badge
            // is a count of everything unread, not of the visible tab.
            const rows = list.querySelectorAll('.topbar-bell-row');
            let unread = 0;

            rows.forEach(function (row) {
                const id = row.dataset.alertId;
                const isRead = id && read.has(id);
                row.classList.toggle('is-read', !!isRead);
                if (!isRead) unread++;
            });

            countEl.textContent = unread > 9 ? '9+' : String(unread);
            countEl.hidden = unread < 1;
            btn.title = unread + ' unread notification' + (unread === 1 ? '' : 's');
            btn.setAttribute('aria-label', 'Alerts (' + unread + ' unread)');

            if (markReadBtn) markReadBtn.hidden = unread < 1;

            /* The classes above are what orderRows() sorts on, so the sink runs
               after the paint, never before it.

               Callers pass reorder=false while the pointer is on a row. Moving
               a row between mousedown and mouseup means the click lands on the
               list instead of the link it started on, and the notification
               simply never opens — so the sink waits for the next open, poll or
               page load, by which time the pointer is elsewhere. */
            if (reorder !== false) relayout();
        }

        /* ── Tabs ──
           Filtering is client-side on data-group: the whole payload is already
           in hand, so a round trip per tab would only add latency to data the
           panel is holding. */
        // Read from the markup rather than hardcoded: staff have no "All" tab,
        // so assuming one would leave activeTab naming a button that is not
        // there and the Alerts tab looking selected without being selected.
        const initialTab = document.querySelector('.bell-tab.is-active');
        let activeTab = initialTab ? initialTab.dataset.tab : 'all';

        /* ── Row order: unread first, then newest first ──
           ONE flat feed. Rows are deliberately NOT gathered into per-group
           blocks: All is a chronological feed, and the tabs above are how you
           narrow it to a single group. The group headings that used to sit
           between those blocks are gone with them — with an interleaved list
           there is no contiguous run left for a heading to label.

           Unread outranks recency because the panel exists to show what has not
           been dealt with; a handled row sitting at the top pushes new ones
           below the fold. A read row is NOT removed: it keeps its severity
           stripe and stays reachable, because "I have seen this" is not "the
           stock is fine". The newest notification is unread by definition, so
           it still lands first.

           Within each read bucket the sort is on data-sort-at, the onset time
           AlertService stamps on every row (see returnWindowOpenedAt): when the
           batch expired, when it entered its return window, when stock last
           moved, when the audit event happened. Missing or unparseable sorts
           last rather than jumping the queue. */
        function sortKey(row) {
            const t = Date.parse(row.dataset.sortAt || '');
            return isNaN(t) ? -Infinity : t;
        }

        function orderRows() {
            [...list.querySelectorAll('.topbar-bell-row')]
                .sort(function (a, b) {
                    return (a.classList.contains('is-read') - b.classList.contains('is-read'))
                        || (sortKey(b) - sortKey(a));
                })
                .forEach(function (row) { list.appendChild(row); });
        }

        function relayout() {
            orderRows();
        }

        function applyTab() {
            list.querySelectorAll('.topbar-bell-row').forEach(function (row) {
                const group = row.dataset.group || 'alerts';
                row.hidden = !(activeTab === 'all' || group === activeTab);
            });

            relayout();

            // An empty tab needs to say so rather than looking broken.
            let empty = list.querySelector('.bell-tab-empty');
            const anyVisible = !!list.querySelector('.topbar-bell-row:not([hidden])');

            if (!anyVisible && !list.querySelector('.topbar-bell-empty')) {
                if (!empty) {
                    empty = document.createElement('p');
                    empty.className = 'topbar-bell-empty bell-tab-empty';
                    list.appendChild(empty);
                }
                empty.textContent = 'Nothing here right now.';
                empty.hidden = false;
            } else if (empty) {
                empty.hidden = true;
            }
        }

        document.querySelectorAll('.bell-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                activeTab = tab.dataset.tab;
                document.querySelectorAll('.bell-tab').forEach(function (t) {
                    const on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                applyTab();
            });
        });

        /* ── Live timestamps ──
           Every row carries a moving time; what moves depends on what the row
           IS. An audit row happened, so it gets "x ago". An inventory alert did
           not happen at a moment -- it is a standing condition -- so "2 minutes
           ago" would be a lie, and it gets the length of time it has been open
           beside the onset AlertService already labelled: "Since Aug 14 · 19
           days". Both are computed here rather than served, because a figure
           that has to keep moving cannot come from a 30s cache. */
        function relative(iso) {
            const then = new Date(iso).getTime();
            if (!then) return '';
            const secs = Math.round((Date.now() - then) / 1000);
            if (secs < 45) return 'just now';
            const mins = Math.round(secs / 60);
            if (mins < 60) return mins + ' minute' + (mins === 1 ? '' : 's') + ' ago';
            const hrs = Math.round(mins / 60);
            if (hrs < 24) return hrs + ' hour' + (hrs === 1 ? '' : 's') + ' ago';
            const days = Math.round(hrs / 24);
            if (days < 30) return days + ' day' + (days === 1 ? '' : 's') + ' ago';
            return new Date(then).toLocaleDateString();
        }

        /* How long a standing condition has been open. FLOOR, not round: a
           shelf that emptied 110 seconds ago has been empty for one minute, not
           two. Past 30 days the onset date carries it on its own and the
           duration is dropped rather than reading "· 412 days", and an onset in
           the future (a clock skew, never a real alert) says nothing at all. */
        function duration(iso) {
            if (!iso) return '';
            const then = new Date(iso).getTime();
            if (!then) return '';
            const secs = Math.floor((Date.now() - then) / 1000);
            if (secs < 0) return '';
            if (secs < 60) return 'just now';
            const mins = Math.floor(secs / 60);
            if (mins < 60) return mins + ' minute' + (mins === 1 ? '' : 's');
            const hrs = Math.floor(mins / 60);
            if (hrs < 24) return hrs + ' hour' + (hrs === 1 ? '' : 's');
            const days = Math.floor(hrs / 24);
            if (days <= 30) return days + ' day' + (days === 1 ? '' : 's');
            return '';
        }

        /* The absolute label comes from the server -- so the bell, the
           notifications page and the toasts cannot drift into three date
           formats -- and the moving half is computed here, because it has to
           keep moving on a page nobody has reloaded. */
        function stampTimes() {
            list.querySelectorAll('.bell-time').forEach(function (el) {
                var when = el.dataset.when || '';
                var at = el.dataset.at;
                if (at) {
                    el.textContent = relative(at) + (when ? ' · ' + when : '');
                    return;
                }
                var open = duration(el.dataset.since);
                el.textContent = when + (open ? ' · ' + open : '');
            });
        }

        // Re-stamp on a short tick. The smallest unit either function prints is
        // a minute, with a "just now" band that ends at one, so a 60s interval
        // could leave a row reading "just now" for very nearly two minutes --
        // which is the one thing a live timestamp must not do. 15s bounds the
        // error to 15s and costs a few dozen textContent writes.
        const TIME_TICK_MS = 15000;
        setInterval(stampTimes, TIME_TICK_MS);

        const closeBtn = document.getElementById('bellClose');
        if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });

        // Clicking a notification marks it read. mousedown, not click: the row
        // is a link, so a plain click navigates and a handler that runs after
        // the browser has begun unloading is not guaranteed to finish.
        function markRowRead(e) {
            const row = e.target.closest('.topbar-bell-row');
            if (!row || !row.dataset.alertId) return;
            reads.add(row.dataset.alertId);   // repaints via remedi:alerts-read
        }

        list.addEventListener('mousedown', markRowRead);
        // Enter on a focused row fires click, not mousedown, so keyboard users
        // would never mark anything read. Adding an id twice is a no-op.
        list.addEventListener('click', markRowRead);

        if (markReadBtn) {
            markReadBtn.addEventListener('click', function () {
                reads.add([...list.querySelectorAll('.topbar-bell-row[data-alert-id]')]
                    .map(function (row) { return row.dataset.alertId; }));
            });
        }

        /* Repaint whenever read state changes anywhere — this panel's own rows,
           or the same rows on /notifications.

           Repaint only, never reorder: whoever handled the pointer is still
           holding it, and a row that moves between mousedown and mouseup takes
           its link out from under the click. The sink lands on the next open,
           poll or page load instead. */
        document.addEventListener('remedi:alerts-read', function () { applyReadState(false); });

        let timer = null;
        let inFlight = null;
        let lastSignature = null;
        let lastFetched = Date.now();

        // ── open / close ──
        function setOpen(open) {
            panel.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            // applyReadState before the fetch, not just after it: a row marked
            // read on the last open sinks now, synchronously, rather than
            // waiting for a poll whose payload may be identical and therefore
            // never re-renders (see render()'s signature guard).
            if (open) { touchStamp(); applyReadState(); refresh(); }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!panel.classList.contains('is-open'));
        });

        // Click-outside and Escape, the same guards the suggest panel uses.
        document.addEventListener('click', function (e) {
            if (panel.classList.contains('is-open') && !wrap.contains(e.target)) setOpen(false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('is-open')) {
                setOpen(false);
                btn.focus({ preventScroll: true });
            }
        });

        // ── rendering ──
        function touchStamp() {
            const secs = Math.round((Date.now() - lastFetched) / 1000);
            stamp.textContent = secs < 10 ? 'just now'
                : secs < 60 ? secs + 's ago'
                : Math.round(secs / 60) + 'm ago';
        }

        function render(data) {
            // Audit rows ride along for admins; staff get an empty array.
            // Sorted here as well as in orderRows() so the DOM is built in
            // final order — appending in one order and immediately re-appending
            // in another is a visible reflow on a panel that is already open.
            const items = (data.items || []).concat(data.activity || [])
                .sort(function (a, b) {
                    const ta = Date.parse(a.sort_at || a.at || '');
                    const tb = Date.parse(b.sort_at || b.at || '');
                    return (isNaN(tb) ? -Infinity : tb) - (isNaN(ta) ? -Infinity : ta);
                });
            const alerts = data.alerts || [];

            // Signature guard: re-rendering identical rows on every poll would
            // tear down a row the pointer is already on. Keyed on the item
            // bodies, since those carry the day counts that actually move.
            const sig = items.map(i => (i.id || '') + '|' + i.kind + '|' + i.title + '|' + i.body).join('~')
                + '#' + alerts.map(a => a.kind + ':' + a.count).join('|');
            if (sig === lastSignature) return;
            lastSignature = sig;

            list.innerHTML = '';

            if (!items.length) {
                list.innerHTML = '<p class="topbar-bell-empty">Nothing needs attention right now.</p>';
            } else {
                items.forEach(function (it) {
                    const row = document.createElement('a');
                    row.className = 'topbar-bell-row ' + (it.cls || '');
                    row.href = it.href;
                    row.dataset.group = it.group || 'alerts';
                    row.dataset.sortAt = it.sort_at || it.at || '';
                    if (it.at) row.dataset.at = it.at;

                    const disc = document.createElement('span');
                    disc.className = 'bell-icon';
                    disc.setAttribute('aria-hidden', 'true');
                    const icon = document.createElement('i');
                    icon.className = 'ti ' + (it.icon || 'ti-bell');
                    disc.appendChild(icon);

                    // textContent, not innerHTML: these strings carry product
                    // names straight from the catalogue, and this panel renders
                    // on every authenticated page.
                    const title = document.createElement('strong');
                    title.textContent = it.title || '';
                    const body = document.createElement('small');
                    body.textContent = it.body || '';

                    const text = document.createElement('span');
                    text.className = 'bell-text';
                    text.appendChild(title);
                    text.appendChild(body);

                    // Same shape the Blade render produces: data-when always,
                    // data-at only for rows that are a real event. stampTimes()
                    // below fills the text for both.
                    if (it.when) {
                        const time = document.createElement('em');
                        time.className = 'bell-time';
                        time.dataset.when = it.when;
                        if (it.at) time.dataset.at = it.at;
                        // No `at` means a standing condition: hand stampTimes()
                        // the onset instead, so it can keep a live duration on
                        // it. Blade emits the identical pair -- if these two
                        // drift, the times stop moving on the first poll.
                        else if (it.sort_at) time.dataset.since = it.sort_at;
                        time.textContent = it.when;
                        text.appendChild(time);
                    }

                    // The action pill, matching the Blade render exactly. A
                    // span for the same reason it is one there: this sits
                    // inside the row's anchor, which may not contain a button
                    // or a second link. If these two drift, the pill vanishes
                    // on the first poll -- the same failure data-when had.
                    if (it.action) {
                        const action = document.createElement('span');
                        action.className = 'bell-action';
                        action.textContent = it.action + ' ';
                        const arrow = document.createElement('i');
                        arrow.className = 'ti ti-arrow-right';
                        arrow.setAttribute('aria-hidden', 'true');
                        action.appendChild(arrow);
                        text.appendChild(action);
                    }

                    const dot = document.createElement('span');
                    dot.className = 'unread-dot';
                    dot.setAttribute('aria-hidden', 'true');

                    if (it.id) row.dataset.alertId = it.id;

                    row.appendChild(disc);
                    row.appendChild(text);
                    row.appendChild(dot);
                    list.appendChild(row);
                });
            }

            if (foot) {
                foot.innerHTML = '';
                alerts.forEach(function (a) {
                    const link = document.createElement('a');
                    link.href = a.href;
                    link.textContent = 'View all ' + Number(a.count).toLocaleString() + ' ' + a.short;
                    foot.appendChild(link);
                });
            }

            stampTimes();
            applyTab();
            applyReadState();
        }

        // ── polling ──
        function refresh() {
            if (inFlight) inFlight.abort();
            const ctl = new AbortController();
            inFlight = ctl;

            fetch(window.REMEDI_BOOT.alertsUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: ctl.signal,
            })
                .then(function (r) {
                    // Session gone: stop polling rather than looping on the
                    // login redirect until the tab is closed.
                    if (r.status === 401 || r.status === 419 || r.redirected) { stop(); return null; }
                    return r.ok ? r.json() : null;
                })
                .then(function (data) {
                    if (!data) return;
                    lastFetched = Date.now();
                    render(data);
                    touchStamp();
                    // One poll, several readers. The toast stack watches the
                    // same payload for kinds whose count has gone up rather
                    // than fetching /alerts a second time -- a second loop
                    // would double the request rate and could still disagree
                    // with the badge by one interval.
                    window.dispatchEvent(new CustomEvent('remedi:alerts', { detail: data }));
                })
                .catch(function () { /* offline or aborted: keep the last known state */ })
                .finally(function () { if (inFlight === ctl) inFlight = null; });
        }

        function start() {
            if (timer) return;
            timer = setInterval(refresh, POLL_MS);
        }

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        // A hidden tab cannot show a badge, so polling it is pure waste --
        // a register left open all day would otherwise fire ~1,000 requests
        // nobody sees. Catching up on return is what makes it feel live.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); return; }
            refresh();
            start();
        });

        // Coming back to the window catches up immediately rather than waiting
        // out the rest of the interval -- the gap between "something happened"
        // and "the badge says so" is what makes a polled bell feel stale.
        window.addEventListener('focus', function () {
            if (!document.hidden) refresh();
        });

        // Anything that moves stock invalidates the alert set. AlertService
        // is cleared server-side by those actions (see AlertService::forget),
        // so a poll right after one returns fresh numbers instead of showing
        // the pre-action count until the next tick.
        //
        // And every OTHER tab of this browser is told too, over a
        // BroadcastChannel: an admin with the till in one tab and the dashboard
        // in another sees the sale land in both bells at once, not the second
        // one up to POLL_MS later. The listener calls refresh() directly, never
        // this wrapper, so a message can never echo back and forth. Browsers
        // without BroadcastChannel just keep the poll.
        let alertChannel = null;
        try {
            alertChannel = new BroadcastChannel('remedi-alerts');
            alertChannel.onmessage = function () {
                if (!document.hidden) refresh();
            };
        } catch (e) { alertChannel = null; }

        window.remediRefreshAlerts = function () {
            refresh();
            if (alertChannel) {
                try { alertChannel.postMessage('refresh'); } catch (e) { /* closed */ }
            }
        };

        // Paint whatever the server rendered before the first poll lands, so
        // the badge is an unread count from the very first frame.
        stampTimes();
        applyTab();
        applyReadState();

        if (!document.hidden) start();
    })();

    /* ── Confirm + AJAX for destructive / state-changing actions ──────────
       One handler for every `form.js-confirm` in the app: delete a product,
       batch, category or user; activate/deactivate a user; mark a batch
       returned. The form stays a real POST form and this only intercepts
       submit, so with JavaScript off each still works unconfirmed rather than
       becoming a dead button — the same trade-off #logoutModal makes.

       Per-form data attributes:
         data-confirm-title / -body / -label   dialog copy
         data-confirm-icon                     Tabler class, default ti-alert-triangle
         data-confirm-tone="neutral"           blue disc instead of destructive red
         data-on-success="remove-row|reload|toggle|none"
         data-row                              selector of the row to remove
       ------------------------------------------------------------------- */
    (function () {
        const modal = document.getElementById('confirmModal');
        if (!modal) return;

        const panel = modal.querySelector('.remedi-modal__panel');
        const iconWrap = document.getElementById('confirmModalIcon');
        const titleEl = document.getElementById('confirmModalTitle');
        const bodyEl = document.getElementById('confirmModalBody');
        const reasonsWrap = document.getElementById('confirmModalReasons');
        const passcodeWrap = document.getElementById('confirmModalPasscode');
        const passcodeInput = document.getElementById('confirmModalPasscodeInput');
        const confirmBtn = document.getElementById('confirmModalConfirm');
        const cancelBtn = document.getElementById('confirmModalCancel');
        let unlockScroll = null;

        let form = null;
        let lastFocus = null;
        let submitting = false;
        let defaultLabel = 'Confirm';
        // Rebuilt fresh on every open() -- see data-confirm-reasons below.
        // Empty whenever a form has no reason picker, which is every form
        // except the ones that opt in.
        let reasonButtons = [];

        // See data-confirm-passcode below (staff voiding a sale). False for
        // every other js-confirm form, which is why the gate this drives is
        // a no-op for them.
        let passcodeRequired = false;

        function passcodeValid() {
            return !passcodeRequired || /^\d{6}$/.test(passcodeInput.value);
        }

        // Every button the picker currently offers -- reason buttons if
        // there are any, else the plain Confirm button -- stays disabled
        // until the code is complete. Re-run on every keystroke.
        function syncPasscodeGate() {
            const ready = passcodeValid();
            (reasonButtons.length ? reasonButtons : [confirmBtn]).forEach(function (b) {
                b.disabled = !ready;
            });
        }

        passcodeInput.addEventListener('input', function () {
            // Digits only -- a 6-digit code, not free text.
            passcodeInput.value = passcodeInput.value.replace(/\D/g, '').slice(0, 6);
            syncPasscodeGate();
        });

        function open(target) {
            const d = target.dataset;
            form = target;
            lastFocus = document.activeElement;

            titleEl.textContent = d.confirmTitle || 'Are you sure?';
            bodyEl.textContent = d.confirmBody || 'This action cannot be undone.';
            defaultLabel = d.confirmLabel || 'Confirm';
            confirmBtn.textContent = defaultLabel;

            iconWrap.innerHTML = '';
            const i = document.createElement('i');
            i.className = 'ti ' + (d.confirmIcon || 'ti-alert-triangle');
            i.setAttribute('aria-hidden', 'true');
            iconWrap.appendChild(i);
            iconWrap.classList.toggle('is-neutral', d.confirmTone === 'neutral');

            // A reversible action should not be offered behind a red button.
            confirmBtn.classList.toggle('btn-danger', d.confirmTone !== 'neutral');
            confirmBtn.classList.toggle('btn-primary', d.confirmTone === 'neutral');

            // data-confirm-reasons: a JSON {value: label} map (e.g.
            // User::ARCHIVE_REASONS). When present, the single generic
            // Confirm button is replaced by one button per reason -- picking
            // one both answers the dialog and supplies the form field the
            // server requires (see the [name="reason"] lookup below), so
            // there's no separate "choose, then confirm" step.
            reasonsWrap.innerHTML = '';
            reasonButtons = [];
            let reasons = null;
            if (d.confirmReasons) {
                try { reasons = JSON.parse(d.confirmReasons); } catch (e) { reasons = null; }
            }

            if (reasons) {
                confirmBtn.hidden = true;
                reasonsWrap.hidden = false;

                Object.keys(reasons).forEach(function (value) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'remedi-modal__reason-btn';
                    btn.textContent = reasons[value];
                    btn.addEventListener('click', function () {
                        const field = form.querySelector('[name="reason"]');
                        if (field) field.value = value;
                        // Only when THIS dialog opened with the passcode
                        // gate -- a form can have its own unrelated field
                        // named "passcode" (Settings' own "New passcode"),
                        // and copying an always-empty gate value into that
                        // would silently blank it out on every submit.
                        if (passcodeRequired) {
                            const pc = form.querySelector('[name="passcode"]');
                            if (pc) pc.value = passcodeInput.value;
                        }
                        submitConfirmed(btn);
                    });
                    reasonsWrap.appendChild(btn);
                    reasonButtons.push(btn);
                });
            } else {
                confirmBtn.hidden = false;
                reasonsWrap.hidden = true;
            }

            // data-confirm-passcode="1": a manager passcode gates whichever
            // buttons the block above just built. Reset on every open --
            // this is a fresh sale each time, not a code to remember across
            // clicks.
            passcodeRequired = d.confirmPasscode === '1';
            passcodeWrap.hidden = !passcodeRequired;
            passcodeInput.value = '';
            syncPasscodeGate();

            modal.classList.add('is-open');
            unlockScroll = REMEDI.lockScroll();
            (passcodeRequired ? passcodeInput : (reasonButtons[0] || confirmBtn)).focus({ preventScroll: true });
        }

        function close() {
            modal.classList.remove('is-open');
            if (unlockScroll) { unlockScroll(); unlockScroll = null; }
            if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
            form = null;
        }

        function reset() {
            submitting = false;
            confirmBtn.disabled = false;
            cancelBtn.disabled = false;
            confirmBtn.textContent = defaultLabel;
            reasonButtons.forEach(function (b) { b.disabled = false; });
            // Cleared, not left standing, so a failed attempt (wrong code,
            // already voided) starts the next one from a blank field rather
            // than a stale code sitting in the box.
            if (passcodeRequired) {
                passcodeInput.value = '';
                syncPasscodeGate();
            }
        }

        /* What to SAY when a confirmed action is refused.
           ------------------------------------------------------------------
           Two different 422 shapes arrive here and the user needs both:

             - a controller's own refusal (Controller::actionFailed) answers
               {success:false, error:"..."}
             - a VALIDATION failure answers Laravel's own
               {message:"...", errors:{field:["..."]}} -- with no `error` key

           Reading only `error` turned every validation message in the app into
           "That action could not be completed.", which is the one sentence that
           does not say what to fix. Reported from Add User: an address with a
           capital letter fails the `lowercase` rule, and the dialog said
           nothing about it. Same bug the POS checkout had, and the same fix.

           Field messages are joined with a space rather than a newline: the
           dialog body is textContent, so a newline would render as a space
           anyway -- doing it here keeps the sentences properly separated. */
        function failureMessage(data) {
            if (!data) return 'That action could not be completed.';
            if (data.error) return data.error;

            if (data.errors) {
                var lines = [];

                Object.keys(data.errors).forEach(function (field) {
                    var messages = data.errors[field];
                    (Array.isArray(messages) ? messages : [messages]).forEach(function (m) {
                        if (m) lines.push(String(m));
                    });
                });

                if (lines.length) return lines.join(' ');
            }

            return data.message || 'That action could not be completed.';
        }

        function notify(type, message) {
            // Every outcome the app reports goes through the one dialog. It
            // used to be an inline banner at the top of the content that
            // scrolled itself into view, which meant a delete performed at row
            // 40 threw the reader to row 1 to read one line.
            REMEDI.showMessage({
                title: type === 'success' ? 'Done' : 'Could not complete that',
                body: message,
                icon: type === 'success' ? 'ti-circle-check' : 'ti-alert-circle',
                tone: type === 'success' ? 'neutral' : 'danger',
            });
        }

        document.addEventListener('submit', function (e) {
            const target = e.target.closest('form.js-confirm');
            if (!target || submitting) return;
            e.preventDefault();
            open(target);
        });

        cancelBtn.addEventListener('click', close);

        modal.addEventListener('mousedown', function (e) {
            if (panel.contains(e.target)) return;
            // A form can opt out of backdrop-dismiss with
            // data-confirm-strict -- Add New User does, since a stray
            // outside click silently discarding an account (and the
            // password just typed into it) is a worse failure mode here
            // than on a reversible toggle or delete confirm. Escape and
            // Cancel still work; only the click-outside shortcut is gone.
            if (form && form.dataset.confirmStrict) return;
            close();
        });

        document.addEventListener('keydown', function (e) {
            if (!modal.classList.contains('is-open')) return;
            if (e.key === 'Escape') { close(); return; }
            if (e.key === 'Tab') {
                e.preventDefault();

                // Cancel plus whichever the reason picker replaced Confirm
                // with -- one item when there's no picker, one per reason
                // when there is -- plus the passcode field first, when one
                // is showing. Cycles either way with Shift.
                const items = (passcodeRequired ? [passcodeInput] : [])
                    .concat([cancelBtn], reasonButtons.length ? reasonButtons : [confirmBtn]);
                const idx = items.indexOf(document.activeElement);
                const next = e.shiftKey
                    ? items[(idx <= 0 ? items.length : idx) - 1]
                    : items[(idx + 1) % items.length];
                next.focus({ preventScroll: true });
            }
        });

        /* Shared by the plain Confirm button and every reason button --
           picking a reason both answers the dialog and supplies the field,
           so from here on it's the same request either way. `busyBtn` is
           whichever button was actually clicked, so "Working…" (or, for a
           reason button, a disabled state -- its label IS the reason, so
           overwriting it would lose that) appears where the click landed. */
        function submitConfirmed(busyBtn) {
            if (!form || submitting) return;
            const target = form;
            const mode = target.dataset.onSuccess || 'reload';

            submitting = true;
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            reasonButtons.forEach(function (b) { b.disabled = true; });
            if (busyBtn === confirmBtn) confirmBtn.textContent = 'Working…';

            // FormData carries the form's own CSRF token and its method-spoofing
            // _method field, so this is byte-for-byte the request the plain form
            // would have posted. (Do not write the Blade directive name here —
            // Blade compiles it even inside a JS comment.)
            fetch(target.action, {
                method: 'POST',
                body: new FormData(target),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            })
                .then(function (res) {
                    return res.json().then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (result) {
                    close();
                    reset();

                    if (!result.ok || !result.data.success) {
                        notify('danger', failureMessage(result.data));
                        return;
                    }

                    notify('success', result.data.message || 'Done.');

                    // Deleting a batch or marking one returned changes what the
                    // bell should be showing; ask it to catch up now rather
                    // than up to POLL_MS later.
                    if (typeof window.remediRefreshAlerts === 'function') window.remediRefreshAlerts();

                    if (mode === 'remove-row') {
                        const row = target.closest(target.dataset.row || 'tr');
                        if (row) row.remove();
                        const tbody = target.closest('tbody');
                        // Nothing left to look at: let the server re-paginate.
                        if (tbody && !tbody.querySelector('tr')) REMEDI.reloadKeepingPlace();
                    } else if (mode === 'toggle') {
                        applyToggle(target, result.data.state);
                    } else if (mode !== 'none') {
                        // Keeps the reader where they were: this is the branch
                        // "Mark Returned", "Add Batch" and every other default
                        // action lands in.
                        REMEDI.reloadKeepingPlace();
                    }
                })
                .catch(function () {
                    // Offline, blocked, or a non-JSON error page: fall back to a
                    // real form post rather than stranding a disabled dialog.
                    submitting = true;
                    target.submit();
                });
        }

        confirmBtn.addEventListener('click', function () {
            // Same guard as the reason-button handler above.
            if (passcodeRequired) {
                const pc = form.querySelector('[name="passcode"]');
                if (pc) pc.value = passcodeInput.value;
            }
            submitConfirmed(confirmBtn);
        });

        /* Activate/deactivate flips one badge and one button label, so the row
           is patched in place — reloading the whole list to change one word is
           what made this feel heavy. */
        function applyToggle(target, state) {
            if (!state) { REMEDI.reloadKeepingPlace(); return; }

            const row = target.closest('tr');
            const btn = target.querySelector('button');

            if (btn) {
                // An icon action (User Management) keeps its icon and swaps
                // the label inside it; textContent on the button would wipe
                // the icon out along with the old word.
                const label = btn.querySelector('.act-label');
                const icon = btn.querySelector('i.ti');
                if (label) {
                    label.textContent = state.action;
                    btn.setAttribute('aria-label', state.action);
                    if (icon) {
                        icon.classList.toggle('ti-user-off', state.is_active);
                        icon.classList.toggle('ti-user-check', !state.is_active);
                    }
                } else {
                    btn.textContent = state.action;
                }
                btn.classList.toggle('btn-warning', state.is_active);
                btn.classList.toggle('btn-success', !state.is_active);
            }

            if (row) {
                // [data-user-status], not a colour class: an admin row's ROLE
                // badge is .badge-success too and comes first, so a colour
                // match relabelled the role instead of the status.
                const badge = row.querySelector('[data-user-status]');
                if (badge) {
                    badge.textContent = state.badge;
                    badge.classList.toggle('badge-success', state.is_active);
                    badge.classList.toggle('badge-danger', !state.is_active);
                }
            }

            // The dialog copy is built from the row's current state, so it has
            // to move with it or the next click asks the previous question.
            const nowActive = state.is_active;
            target.dataset.confirmTitle = (nowActive ? 'Deactivate' : 'Activate') + ' this account?';
            target.dataset.confirmLabel = nowActive ? 'Deactivate' : 'Activate';
            target.dataset.confirmBody = nowActive
                ? target.dataset.confirmBodyOff || 'They will be signed out and unable to sign in again.'
                : target.dataset.confirmBodyOn || 'They will be able to sign in again.';
        }
    })();

    /* ── Log out: centred confirm, then an AJAX POST ─────────────────────── */
    (function () {
        const form = document.getElementById('logoutForm');
        const modal = document.getElementById('logoutModal');
        if (!form || !modal) return;

        const confirmBtn = document.getElementById('logoutConfirm');
        const cancelBtn = document.getElementById('logoutCancel');
        const panel = modal.querySelector('.remedi-modal__panel');
        let lastFocus = null;
        let submitting = false;
        let unlockScroll = null;

        function open() {
            lastFocus = document.activeElement;
            modal.classList.add('is-open');
            unlockScroll = REMEDI.lockScroll();
            confirmBtn.focus({ preventScroll: true });
        }

        function close() {
            modal.classList.remove('is-open');
            if (unlockScroll) { unlockScroll(); unlockScroll = null; }
            if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
        }

        form.addEventListener('submit', function (e) {
            if (submitting) return;          // the confirmed POST goes through
            e.preventDefault();
            open();
        });

        cancelBtn.addEventListener('click', close);

        // Backdrop click, but not a click that started inside the panel.
        modal.addEventListener('mousedown', function (e) {
            if (!panel.contains(e.target)) close();
        });

        document.addEventListener('keydown', function (e) {
            if (!modal.classList.contains('is-open')) return;

            if (e.key === 'Escape') { close(); return; }

            // Focus trap: only two controls, so Tab just alternates.
            if (e.key === 'Tab') {
                e.preventDefault();
                (document.activeElement === confirmBtn ? cancelBtn : confirmBtn).focus({ preventScroll: true });
            }
        });

        confirmBtn.addEventListener('click', function () {
            if (submitting) return;
            submitting = true;
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            confirmBtn.textContent = 'Signing out…';

            const body = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                // Follow Laravel's redirect ourselves so we know where it landed.
                redirect: 'follow',
            })
                .then(function (res) {
                    window.location.href = res.url || window.REMEDI_BOOT.loginUrl;
                })
                .catch(function () {
                    // Offline or blocked: fall back to a normal form post rather
                    // than stranding the user on a disabled dialog.
                    form.submit();
                });
        });
    })();

    /* -- Safeguard password pop-up -------------------------------------
       Intercepts the sidebar link in the CAPTURE phase so it runs before the
       navigation-skeleton handler, which bails on defaultPrevented -- without
       that the skeleton would paint behind a dialog that has not navigated.
       Success is only ever the server's answer: the page itself is gated by
       `password.confirm`, so nothing here can open it on a wrong password. */
    (function () {
        const modal = document.getElementById('passwordGateModal');
        if (!modal) return;

        const form = document.getElementById('passwordGateForm');
        const input = document.getElementById('passwordGateInput');
        const error = document.getElementById('passwordGateError');
        const okBtn = document.getElementById('passwordGateConfirm');
        const cancelBtn = document.getElementById('passwordGateCancel');
        let target = modal.dataset.target;
        let cancelHref = null;      // set when opened on the confirm page itself
        let unlockScroll = null;
        let busy = false;

        function showError(msg) {
            error.textContent = msg;
            error.hidden = !msg;
        }

        function open(keepError) {
            if (!keepError) showError('');
            input.value = '';
            modal.classList.add('is-open');
            unlockScroll = REMEDI.lockScroll();
            setTimeout(function () { input.focus({ preventScroll: true }); }, 0);
        }

        function close() {
            if (busy) return;
            if (cancelHref) { window.location.href = cancelHref; return; }
            modal.classList.remove('is-open');
            if (unlockScroll) { unlockScroll(); unlockScroll = null; }
        }

        document.addEventListener('click', function (e) {
            const link = e.target.closest('a[data-password-gate="required"]');
            if (!link) return;
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            target = link.href;
            open(false);
        }, true);

        cancelBtn.addEventListener('click', close);
        modal.addEventListener('mousedown', function (e) {
            if (!form.contains(e.target)) close();
        });
        document.addEventListener('keydown', function (e) {
            if (modal.classList.contains('is-open') && e.key === 'Escape') close();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (busy || !input.value) return;
            busy = true;
            okBtn.disabled = true;
            cancelBtn.disabled = true;
            okBtn.textContent = 'Checking...';
            showError('');

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        return { status: res.status, data: data };
                    });
                })
                .then(function (r) {
                    if (r.status === 200 && r.data.success) {
                        // Every gated link on the page is open now, so the
                        // next click goes straight through.
                        document.querySelectorAll('a[data-password-gate]').forEach(function (a) {
                            a.dataset.passwordGate = 'confirmed';
                        });
                        window.location.href = target;
                        // A DOWNLOAD target (Backup Database) never unloads the
                        // page, which would leave the dialog stuck on
                        // "Checking...". Close it shortly after; a real
                        // navigation replaces the page before this fires.
                        setTimeout(function () {
                            busy = false;
                            okBtn.disabled = false;
                            cancelBtn.disabled = false;
                            okBtn.textContent = 'Continue';
                            close();
                        }, 1200);
                        return;
                    }
                    busy = false;
                    okBtn.disabled = false;
                    cancelBtn.disabled = false;
                    okBtn.textContent = 'Continue';
                    // Validation 422 is {message, errors:{password:[...]}};
                    // the throttle answers 429 with a message only.
                    const msg = (r.data.errors && r.data.errors.password && r.data.errors.password[0])
                        || (r.status === 429 ? 'Too many attempts. Wait a minute and try again.' : r.data.message)
                        || 'That password could not be checked. Please try again.';
                    showError(msg);
                    input.value = '';
                    input.focus({ preventScroll: true });
                })
                .catch(function () { form.submit(); });
        });

        // auth/confirm-password.blade.php -- where someone lands on reaching
        // a gated URL directly. It opens this same pop-up over the app, and
        // Cancel leaves for the dashboard rather than revealing an empty page
        // behind the dialog.
        if (modal.dataset.autoOpen === '1') {
            target = modal.dataset.intended || target;
            cancelHref = modal.dataset.cancelHref || null;
            open(true);
        }
    })();

    /* -- Alert toasts ---------------------------------------------------
       One bottom-right card at a time, drawn from a queue.

       Two ways in:

         GREETING -- the alerts that were already open when the page rendered,
         played once per browser session.

         LIVE -- queued whenever the bell's poll reports a notification that was
         not in the list before, so someone already signed in and working is
         told without reloading anything.

       Both paths produce the same card and share one queue, one chime and one
       dismissal rule, so there is no second vocabulary to keep in step. */
    (function () {
        var stack = document.getElementById('remediToasts');
        if (!stack) return;                  // guest layout

        var seed = { items: [], counts: {} };
        var seedEl = document.getElementById('remediToastSeed');
        if (seedEl) { try { seed = JSON.parse(seedEl.textContent) || seed; } catch (e) {} }

        var DWELL_MS = 5000;                 // the "vanish in 5 seconds" window
        var EXIT_MS = 260;                   // must clear the toastOut animation
        var READY_FALLBACK_MS = 15000;
        var STAGGER_MS = 140;                // so they arrive as a stack, not a slab
        /* Five on screen at once. Past that the stack walks up the page and
           starts covering the thing it is reporting on, so the oldest gives
           way -- it has been readable longest. The bell still holds every one
           of them. */
        var MAX_VISIBLE = 5;

        var reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        /* -- The chime ---------------------------------------------------
           Synthesised with WebAudio rather than served as an .mp3, for the same
           reason the dashboard loader inlines its logo as a data URI: `php
           artisan serve` is single-threaded, so a sound file requested while
           something slow is in flight queues behind it and arrives after the
           toast it was meant to accompany.

           Fires once per BATCH -- one greeting, or one poll that turned up
           several new alerts at once. The cards arrive 140ms apart, and five
           chimes 140ms apart is an alarm rather than a notification.

           Muting: localStorage 'remedi.toastSound' = 'off', flipped by
           REMEDI.toastSound(false). Kept out of the per-user alert-read store
           because it is a property of the WORKSTATION -- the machine on the
           shop floor with customers next to it -- not of the account. */
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        var audio = null;                    // reused, so live pops need no unlock

        function muted() {
            try { return localStorage.getItem('remedi.toastSound') === 'off'; }
            catch (e) { return false; }
        }

        window.REMEDI = window.REMEDI || {};
        window.REMEDI.toastSound = function (on) {
            try {
                if (on === false) localStorage.setItem('remedi.toastSound', 'off');
                else localStorage.removeItem('remedi.toastSound');
            } catch (e) { /* storage unavailable; nothing to remember */ }
            return on !== false;
        };

        /* Ramped, never switched. A gain that jumps straight from 0 to full
           produces a click at the discontinuity that is harsher than the note
           itself -- and exponentialRamp cannot touch exact zero, hence 0.0001. */
        function note(ctx, freq, at, dur, peak) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();

            osc.type = 'sine';              // no harmonics, so nothing to rasp
            osc.frequency.value = freq;

            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(peak, at + 0.014);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + dur);

            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(at);
            osc.stop(at + dur + 0.02);
        }

        function chime() {
            if (!AudioCtx || muted()) return;

            /* One context for the tab, not one per chime. Browsers cap how many
               a document may create, and a register left open all day can pop
               dozens of alerts -- a fresh context each time eventually throws
               and takes the sound out for the rest of the shift. Reusing it
               also means that once it is unlocked it STAYS unlocked, which is
               what makes live pops audible where the greeting may not be. */
            if (!audio) {
                try { audio = new AudioCtx(); } catch (e) { return; }
            }

            function play() {
                var t = audio.currentTime + 0.02;
                // A5 up to D6 -- a perfect fourth, which reads as "look here"
                // without the minor-second edge that makes alarms unpleasant.
                note(audio, 880.0, t, 0.18, 0.05);
                note(audio, 1174.7, t + 0.12, 0.28, 0.045);
            }

            /* Autoplay policy: a context created before the user has interacted
               with the document starts suspended, and resume() may reject. Both
               are expected, not errors -- the toast is the notification and the
               sound is a courtesy, so a blocked chime must never surface
               anything or interrupt the pop. */
            if (audio.state === 'suspended') {
                var r = audio.resume();
                if (r && r.then) { r.then(play).catch(function () {}); }
                else { play(); }

                return;
            }

            play();
        }

        /* -- The card ----------------------------------------------------
           Built here rather than fetched as rendered HTML: /alerts answers JSON
           for the bell, and asking it for markup as well would mean two shapes
           of the same payload to keep in step. textContent throughout -- title
           and body carry product names, which are user input. */
        function build(row) {
            var el = document.createElement('div');
            el.className = 'remedi-toast ' + (row.cls || '');
            el.hidden = true;

            var link = document.createElement('a');
            link.className = 'remedi-toast__link';
            link.href = row.href || '#';

            var icon = document.createElement('span');
            icon.className = 'remedi-toast__icon';
            var i = document.createElement('i');
            i.className = 'ti ' + (row.icon || 'ti-bell');
            i.setAttribute('aria-hidden', 'true');
            icon.appendChild(i);

            var text = document.createElement('span');
            text.className = 'remedi-toast__text';

            var title = document.createElement('span');
            title.className = 'remedi-toast__title';
            title.textContent = row.title || 'Alert';
            text.appendChild(title);

            var body = document.createElement('span');
            body.className = 'remedi-toast__body';
            body.textContent = row.body || '';
            text.appendChild(body);

            // Absent on the summary fallback below, which describes a kind
            // rather than one batch and so has no single onset to quote.
            if (row.when) {
                var when = document.createElement('span');
                when.className = 'remedi-toast__when';
                when.textContent = row.when;
                text.appendChild(when);
            }

            // Same pill the bell row carries, and a span for the same reason:
            // the card body is an <a>, which may not contain a button or a
            // second link. The card's own href is the action's destination.
            if (row.action) {
                var action = document.createElement('span');
                action.className = 'remedi-toast__action';
                action.textContent = row.action;
                text.appendChild(action);
            }

            link.appendChild(icon);
            link.appendChild(text);

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'remedi-toast__close';
            close.setAttribute('aria-label', 'Dismiss ' + (row.title || 'alert'));
            var x = document.createElement('i');
            x.className = 'ti ti-x';
            x.setAttribute('aria-hidden', 'true');
            close.appendChild(x);

            el.appendChild(link);
            el.appendChild(close);

            return el;
        }

        /* -- Showing and dismissing --------------------------------------
           Up to MAX_VISIBLE cards stand together, each with its own countdown
           started when it actually appears -- otherwise the last of five would
           be on screen for 5s minus its own stagger, and a live pop landing
           beside a 4s-old card would inherit its remaining second. */
        var paused = false;

        function live() {
            return [].slice.call(stack.querySelectorAll('.remedi-toast'))
                .filter(function (el) { return !el.dataset.leaving; });
        }

        function drop(el) {
            if (!el || el.dataset.leaving) return;
            el.dataset.leaving = '1';
            clearTimeout(el._timer);
            el._timer = null;

            function remove() {
                el.remove();
                // The stack is a permanent mount point for live pops, so it is
                // emptied and hidden -- never removed.
                if (!stack.querySelector('.remedi-toast')) stack.classList.remove('is-open');
            }

            if (reduced) { remove(); return; }
            el.classList.add('is-leaving');
            setTimeout(remove, EXIT_MS);
        }

        function pauseOne(el) {
            if (!el._timer || el.dataset.leaving) return;
            clearTimeout(el._timer);
            el._timer = null;
            el._left -= Date.now() - el._since;
        }

        /* Hovering or focusing anywhere in the stack pauses EVERY card: pulling
           a row out from under someone who is reading it is the one thing a
           timed popup must not do, and with five of them the cursor is rarely
           over the one about to expire. */
        function pause() {
            paused = true;
            live().forEach(pauseOne);
        }

        function resume() {
            paused = false;
            live().forEach(function (el) {
                if (el._timer || el._left == null) return;
                if (el._left <= 0) { drop(el); return; }
                el._since = Date.now();
                el._timer = setTimeout(function () { drop(el); }, el._left);
            });
        }

        stack.addEventListener('mouseenter', pause);
        stack.addEventListener('mouseleave', resume);
        stack.addEventListener('focusin', pause);
        stack.addEventListener('focusout', resume);

        stack.addEventListener('click', function (e) {
            var btn = e.target.closest('.remedi-toast__close');
            if (!btn) return;
            drop(btn.closest('.remedi-toast'));
        });

        function trim() {
            var rows = live();
            for (var i = 0; i < rows.length - MAX_VISIBLE; i++) drop(rows[i]);
        }

        function show(el) {
            if (!el.isConnected) return;

            stack.classList.add('is-open');
            el.hidden = false;

            el._left = DWELL_MS;
            el._since = Date.now();
            el._timer = setTimeout(function () { drop(el); }, el._left);
            if (paused) pauseOne(el);        // landed while the cursor was in the stack

            trim();
        }

        /* One batch in, staggered so they read as a stack building rather than
           a slab appearing, and one chime for the whole batch. */
        function enqueue(rows) {
            if (!rows.length) return;

            rows.slice(0, MAX_VISIBLE).forEach(function (row, i) {
                var el = build(row);
                stack.appendChild(el);
                setTimeout(function () { show(el); }, reduced ? 0 : i * STAGGER_MS);
            });

            chime();
        }

        /* -- What has already been said ----------------------------------
           Seeded from the server-rendered payload whether or not the greeting
           plays, so a page opened later in the same session does not replay
           everything the first page already showed. */
        var WATCHED = {};
        (seed.kinds || []).forEach(function (k) { WATCHED[k] = true; });

        var seen = {};
        (seed.items || []).forEach(function (i) { seen[i.id] = true; });

        /* The per-device read store the bell and /notifications already share.
           Only the expired rule consults it -- see pickGreeting below. */
        function readIds() {
            try {
                return window.remediAlertReads ? window.remediAlertReads.get() : null;
            } catch (e) { return null; }
        }

        /* -- Live watch ---------------------------------------------------
           Driven by the bell's existing poll (see the remedi:alerts dispatch in
           the bell script) rather than a loop of its own, so there is exactly
           one request per interval and the toast can never disagree with the
           badge it appears beside.

           A card is raised for an ITEM ID that was not in the list before --
           never for a kind whose total merely moved. A summary card ("Low stock
           alert - 12 products") cannot say which product, cannot carry an onset
           and cannot link at anything but a filter, so it is not a notification;
           it is the badge restated.

           The cost of that is real and worth writing down: payload.items is
           capped at AlertService::PER_KIND per kind, so an alert that never
           reaches its kind's slice never pops. The bell still lists it and the
           badge still counts it -- this stack is deliberately the recent-news
           surface, not the complete one. */
        window.addEventListener('remedi:alerts', function (e) {
            var detail = e.detail || {};
            // `activity` is its own key on the polled payload, not part of
            // `items` -- so watching only `items` meant an account change could
            // be seeded into the greeting but could never pop LIVE. Empty for
            // staff, who never receive audit rows at all.
            var items = (detail.items || []).concat(detail.activity || []);

            var fresh = items.filter(function (i) {
                return WATCHED[i.kind] && !seen[i.id];
            });

            fresh.forEach(function (i) { seen[i.id] = true; });

            enqueue(fresh);
        });

        /* -- The greeting -------------------------------------------------
           "Freshly opened" is a browser-session fact, not a server one, so the
           gate is sessionStorage rather than a session flash: the app does full
           page loads on every navigation, and a purely server-side flag would
           either fire once at login only (missing someone who reopened a closed
           tab) or fire on every single page view. sessionStorage dies with the
           tab, which is exactly the lifetime of "this is a fresh visit".

           A fresh sign-in clears the mark, so logging out and back in as
           someone else greets the new user rather than staying silent because
           the tab has already been greeted. The key is per user id for the same
           reason. Note this gates the GREETING only -- live pops are never
           suppressed, because they are news rather than a summary. */

        /* What the greeting is allowed to say, in order.

           EXPIRED stock is the exception to "recent only". Those units are on
           the shelf right now and have to come off it, so they keep being
           raised every time the app is opened until someone has actually opened
           one -- read state, the same per-device store the bell paints from.
           Everything else is a standing condition the bell will still be
           holding tomorrow, so only the newest are worth interrupting for; the
           payload already arrives newest-first, so taking from the front is
           what "most recent, not the past" means here. */
        function pickGreeting() {
            var pool = (seed.items || []).filter(function (i) { return WATCHED[i.kind]; });
            var read = readIds();

            var expired = pool.filter(function (i) {
                return i.kind === 'expired' && !(read && read.has(i.id));
            });

            var recent = pool.filter(function (i) { return i.kind !== 'expired'; });

            return expired.concat(recent).slice(0, MAX_VISIBLE);
        }

        var greeting = pickGreeting();
        if (!greeting.length) return;

        var key = 'remedi.greeted:' + (stack.dataset.user || '0');

        /* Storage throws in some privacy modes. Treat that as "cannot remember"
           and still greet -- a few 5s cards are a smaller cost than silently
           losing the feature for those users. */
        function storage(fn, fallbackValue) {
            try { return fn(window.sessionStorage); } catch (e) { return fallbackValue; }
        }

        if (stack.dataset.freshLogin === '1') {
            storage(function (ss) { ss.removeItem(key); });
        } else if (storage(function (ss) { return ss.getItem(key); }, null)) {
            return;
        }

        storage(function (ss) { ss.setItem(key, '1'); });

        function reveal() { enqueue(greeting); }

        /* On /dashboard the body is fetched after the shell paints and the
           loader owns the screen for 5-12s. Firing now would spend the whole
           first card behind a loading card and be gone before the page the user
           is waiting for arrives, so wait for the injector's ready event --
           with a fallback timer, because a dashboard that fails to load must
           not swallow the alerts too.

           #dashboardRoot exists only in the shell (dashboard.index). The
           ?full=1 escape hatch renders admin/staff.dashboard directly and has
           no root, so its body is already on the page and needs no wait. */
        var pendingBody = !!document.getElementById('dashboardRoot');

        if (!pendingBody) { setTimeout(reveal, 400); return; }

        var fired = false;
        function once() {
            if (fired) return;
            fired = true;
            setTimeout(reveal, 300);
        }

        window.addEventListener('remedi:dashboard-ready', once);
        setTimeout(once, READY_FALLBACK_MS);
    })();

