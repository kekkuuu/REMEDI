<!DOCTYPE html>
<html lang="en" class="nav-no-anim">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'REMEDI') }} - @yield('title', 'Dashboard')</title>

    {{-- Browser-tab identity. The title reads from APP_NAME, which shipped as
         "Laravel" — so every tab said "Laravel - Dashboard" and the 'REMEDI'
         fallback here never fired, because the key was set, just set wrong.
         The favicon gives the tab the brand mark instead of a blank globe, and
         theme-color tints the browser chrome itself on mobile. --}}
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
    {{-- Pinned, not @latest: jsDelivr serves @latest with a one-week cache (and
         it resolved to 2.47.0 anyway -- byte-identical, checked 2026-09-28), while
         an exact version is immutable for a year, 779 KB font included. Moving to
         3.x renames icons; check every ti-* in use before bumping. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/tabler-icons.min.css">
    {{-- The shared styles live in public/assets/remedi.css (moved out of this file
         2026-09-28): 131 KB that every page used to carry inline, now fetched once
         and cached for a year under a content-hash URL. Edit THAT file. --}}
    <link rel="stylesheet" href="{{ \App\Support\StaticAsset::url('assets/remedi.css') }}">
</head>
<body>
<script>
    // Apply the saved sidebar state before the wrapper paints, so there's
    // no visible flash of the sidebar appearing then disappearing.
    (function () {
        // On phones the sidebar is an overlay drawer, so it always starts
        // closed regardless of the saved desktop preference -- otherwise the
        // first paint is the menu sitting on top of the page.
        var isMobile = window.matchMedia('(max-width: 767px)').matches;

        if (isMobile || localStorage.getItem('remedi_sidebar_hidden') === 'true') {
            document.documentElement.dataset.sidebarHidden = 'true';
        }
    })();
</script>
<div class="layout-wrapper" id="layoutWrapper">

    {{-- Backdrop for the mobile drawer; inert (and invisible) at
         wider widths where the sidebar is docked. --}}
    <div class="sidebar-scrim" id="sidebarScrim" aria-hidden="true"></div>

    <aside class="sidebar">
        <div class="brand">
            {{-- The real mark, not a stock Tabler glyph. Same drawing as
                 favicon.svg minus its tile, since the sidebar already supplies
                 a dark panel behind it. --}}
            <span class="brand-mark" aria-hidden="true">
                {{-- INLINED, not <img src="logo.png">, for the same reason the
                     dashboard loader inlines its mark. Every navigation in this
                     app is a full page load, so a linked logo is re-requested on
                     every click -- and this one was `logo.png`, **279 KB drawn
                     at 34x34**. On anything slower than localhost (a tunnel, a
                     phone, `artisan serve` while it is busy with a page) it
                     arrived after the rest of the sidebar had painted, so the
                     logo visibly popped in on every click.

                     logo-nav.webp is a 68px derivative (2.7 KB, ~3.6 KB inlined)
                     from `php artisan logo:mark --width=68 --out=logo-nav.webp`
                     -- regenerate it whenever logo.png changes, the same rule
                     logo-mark.webp already carries. If it is missing we fall
                     back to the linked PNG rather than rendering nothing. --}}
                @php
                    $navLogoFile = public_path('logo-nav.webp');
                    $navLogo = is_file($navLogoFile)
                        ? 'data:image/webp;base64,'.base64_encode(file_get_contents($navLogoFile))
                        : asset('logo.png');
                @endphp
                <img src="{{ $navLogo }}" alt="" width="34" height="34">
            </span>
            <span class="brand-text">
                <span class="brand-name">RE<span>ME</span>DI</span>
                <span class="brand-tagline">Pharmacy System</span>
            </span>
        </div>
        <div class="brand-divider"></div>

        <nav>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard
            </a>
            <a href="{{ route('pos.index') }}" class="{{ request()->routeIs('pos.*') ? 'active' : '' }}">
                <i class="ti ti-shopping-cart" aria-hidden="true"></i> Point of sale
            </a>
            @php
                $inventoryActive = request()->routeIs('inventory.*');
                $activeCategoryId = $inventoryActive ? (int) request('category_id') : null;
            @endphp
            <div class="nav-group {{ $inventoryActive ? 'open' : '' }}" id="inventoryNavGroup" data-active="{{ $inventoryActive ? '1' : '0' }}">
                {{-- Carries the All Categories URL so the script below can send
                     you there. It stays a <button>, not a link: on the
                     unfiltered inventory page there is nowhere to go and it
                     goes back to being a pure expand/collapse control. --}}
                <button type="button" class="nav-link-btn {{ $inventoryActive ? 'active' : '' }}" id="inventoryNavToggle"
                        data-all-url="{{ route('inventory.index') }}"
                        data-on-all="{{ $inventoryActive && ! $activeCategoryId ? '1' : '0' }}"
                        aria-expanded="{{ $inventoryActive ? 'true' : 'false' }}">
                    <i class="ti ti-box" aria-hidden="true"></i> Inventory
                    <i class="ti ti-chevron-down nav-caret" aria-hidden="true"></i>
                </button>
                <div class="nav-submenu" id="inventoryNavSubmenu">
                    <div class="nav-submenu-inner">
                        <a href="{{ route('inventory.index') }}" class="nav-sublink {{ $inventoryActive && !$activeCategoryId ? 'active' : '' }}">
                            <i class="ti ti-list" aria-hidden="true"></i> All Categories
                        </a>
                        @foreach($sidebarCategories ?? [] as $sidebarCategory)
                            <a href="{{ route('inventory.index', ['category_id' => $sidebarCategory->id]) }}" class="nav-sublink {{ $activeCategoryId === $sidebarCategory->id ? 'active' : '' }}">
                                <i class="ti ti-point" aria-hidden="true"></i> {{ $sidebarCategory->name }}
                                <span class="nav-sub-count">{{ $sidebarCategory->products_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
            <a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.*') ? 'active' : '' }}">
                <i class="ti ti-receipt" aria-hidden="true"></i> Sales history
            </a>
            {{-- Stock reports (2026-09-28): staff notify an admin about low or
                 expired stock, an admin approves. Shared -- staff see their
                 own. The count is what is WAITING, shown to admins only. --}}
            <a href="{{ route('stock-reports.index') }}" class="{{ request()->routeIs('stock-reports.*') ? 'active' : '' }}">
                <i class="ti ti-bell-ringing" aria-hidden="true"></i> Stock reports
                @if(auth()->user()->isAdmin() && ($stockReportsPending = \App\Models\StockReport::pendingCount()) > 0)
                    <span class="nav-pending" title="{{ $stockReportsPending }} waiting for approval">{{ $stockReportsPending }}</span>
                @endif
            </a>

            @if(auth()->user()->isAdmin())
                <hr>
                <div class="nav-section">Admin</div>
                <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">
                    <i class="ti ti-pill" aria-hidden="true"></i> Products
                </a>
                <a href="{{ route('barcodes.index') }}" class="{{ request()->routeIs('barcodes.*') ? 'active' : '' }}">
                    <i class="ti ti-barcode" aria-hidden="true"></i> Print barcodes
                </a>
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="ti ti-chart-bar" aria-hidden="true"></i> Reports
                </a>
                {{-- Demand and Sales Forecasting merged into one page/tab
                     2026-09-22 -- both were built from the same sales data
                     and per-product forecast, so two nav entries pointed at
                     two views of one thing. /sales-forecast still resolves
                     (redirects here) so an old bookmark or link keeps
                     working. --}}
                <a href="{{ route('forecast.index') }}" class="{{ request()->routeIs('forecast.*') || request()->routeIs('sales-forecast.*') ? 'active' : '' }}">
                    <i class="ti ti-trending-up" aria-hidden="true"></i> Forecasting
                </a>
                {{-- Between Forecasting and the audit trail: the two
                     administrative tabs sit together at the foot of the Admin
                     group, after the four that are about stock and trade. --}}
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="ti ti-users" aria-hidden="true"></i> User management
                </a>
                {{-- Safeguard (renamed from Settings 2026-09-24): store-wide
                     config an admin sets through the UI -- currently just the
                     POS void passcode. Opening it asks for the login password
                     first (`password.confirm`, routes/web.php). --}}
                {{-- data-password-gate: clicking opens #passwordGateModal
                     (a pop-up asking for the login password) instead of
                     navigating, unless this session already confirmed one
                     inside `auth.password_timeout`. The server gate
                     (`password.confirm`) is what actually protects the page;
                     this only saves a trip through it. --}}
                <a href="{{ route('safeguard.edit') }}" class="{{ request()->routeIs('safeguard.*') ? 'active' : '' }}"
                   data-password-gate="{{ \App\Support\PasswordGate::state() }}">
                    <i class="ti ti-shield-lock" aria-hidden="true"></i> Safeguard
                </a>
                <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}">
                    <i class="ti ti-list-check" aria-hidden="true"></i> Audit trail
                </a>
            @endif
        </nav>

        <div class="sidebar-promo">
            <strong>Health starts with Availability</strong>
            <i class="ti ti-heartbeat" aria-hidden="true"></i>
        </div>

        <div class="sidebar-footer">
            <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.*') ? 'active' : '' }}">
                <i class="ti ti-user-circle" aria-hidden="true"></i> My profile
            </a>
            {{-- No skeleton on logout: it tears down the session and lands on
                 the login page, so blanking the app behind a skeleton of a
                 page you are leaving is just a flash of nothing.

                 Confirmation is a centred modal, not window.confirm(): Log out
                 sits directly under "My profile" in the footer, so it is easy
                 to hit by accident, and at a till that means re-authenticating
                 mid-transaction. The browser dialog put that question in the
                 top-left chrome, away from where the click happened.

                 The form stays a real POST form — without JS it submits
                 normally, unconfirmed, rather than becoming a dead button. --}}
            <form method="POST" action="{{ route('logout') }}" data-no-skeleton id="logoutForm">
                @csrf
                <button type="submit" class="logout-btn">
                    <i class="ti ti-logout" aria-hidden="true"></i> Log out
                </button>
            </form>
        </div>
    </aside>

    {{-- These two run HERE, inline directly after the sidebar, and not with the
         rest of the scripts at the end of the body -- that is the whole point
         of the placement.

         Both change the sidebar's geometry: one sets the submenu's height, the
         other restores the menu's scroll offset from the previous page. Down at
         the end of the body they executed only after the entire content markup
         had been parsed, which on a long table is easily late enough for the
         browser to have painted the menu already. The result was a menu that
         appeared at the top and then snapped to its remembered position on
         every single page load -- the "menu jumping up on refresh".

         Running them while the parser is still inside the document, before the
         content exists, means the first paint already has the right geometry.
         Both are self-contained: DOM plus web storage, no REMEDI helpers. --}}
    <script>
    // Inventory nav group: expand/collapse the category sub-buttons.
    // Starts open automatically whenever we're already on an inventory
    // page (see the "open" class rendered server-side above).
    (function () {
        var toggle = document.getElementById('inventoryNavToggle');
        var group = document.getElementById('inventoryNavGroup');
        var submenu = document.getElementById('inventoryNavSubmenu');
        if (!toggle || !group) return;

        var KEY = 'remedi_inventory_nav_open';

        // Persist the expanded/collapsed state across navigations. It used to
        // be derived purely from "are we on an inventory page", so opening the
        // category list and then clicking any other tab collapsed it again and
        // you had to reopen it every time.
        function apply(open) {
            group.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            // Height from the actual content, not the CSS cap. The category
            // list is data -- it grew from 7 entries to 10 -- and the static
            // 640px was within one category of silently clipping the last
            // ones off the bottom of the menu. The CSS value stays as the
            // no-JS fallback.
            if (submenu) submenu.style.maxHeight = open ? submenu.scrollHeight + 'px' : '';
        }

        var stored = localStorage.getItem(KEY);
        var onInventory = group.dataset.active === '1';

        // Being on an inventory page still forces it open; otherwise the
        // remembered choice wins.
        apply(onInventory || stored === '1');

        toggle.addEventListener('click', function () {
            /* Clicking Inventory GOES to Inventory -- All Categories -- rather
               than only unfolding a menu you then have to click again. It used
               to be expand-only, so reaching the unfiltered list from anywhere
               else in the app took two clicks and looked like the tab did
               nothing.

               The exception is when you are already on the unfiltered list:
               there is nowhere to navigate to, so it behaves as the pure
               expand/collapse it always was. That keeps the remembered
               open/closed state below meaningful -- without it there would be
               no way to collapse the category list at all. */
            if (toggle.dataset.onAll !== '1' && toggle.dataset.allUrl) {
                // Remember it as open -- the destination renders it open anyway,
                // and animating it here only competes with the page change.
                localStorage.setItem(KEY, '1');

                // Same skeleton and "Loading Inventory…" pill every other tab
                // gets. Looked up at click time, not bind time: this block runs
                // before the navigation script further down has defined it.
                if (window.REMEDI && typeof window.REMEDI.showNavigating === 'function') {
                    window.REMEDI.showNavigating(toggle);
                }

                window.location.href = toggle.dataset.allUrl;

                return;
            }

            var open = !group.classList.contains('open');
            apply(open);
            localStorage.setItem(KEY, open ? '1' : '0');
        });

        // Choosing a category is an explicit "I'm using this menu", so keep it
        // open on the page you land on.
        group.addEventListener('click', function (e) {
            if (e.target.closest('.nav-sublink')) localStorage.setItem(KEY, '1');
        });
    })();

    // Sidebar scroll position, kept across navigations.
    //
    // .sidebar nav is the scroller (the sidebar itself is overflow:hidden), and
    // with the category submenu expanded its content is over twice the height
    // of the visible area. Every page here is a full server render, so the nav
    // came back scrolled to the top each time: scroll down to a category near
    // the bottom of the list, click it, and the menu jumped back up to
    // Dashboard -- away from the very item you had just chosen.
    (function () {
        var nav = document.querySelector('.sidebar nav');
        if (!nav) return;

        var KEY = 'remedi_sidebar_scroll';
        // sessionStorage, not localStorage: a scroll offset is per-tab state,
        // and it should not outlive the window it belongs to.
        var saved = parseInt(sessionStorage.getItem(KEY) || '', 10);

        if (saved > 0) {
            nav.scrollTop = saved;
        } else {
            // No stored offset (fresh tab, or a link opened directly). If the
            // item for this page is out of view, bring it in rather than
            // leaving the user looking at a menu that seems not to include it.
            //
            // Measured with getBoundingClientRect against the scroller, not
            // offsetTop: offsetTop resolves against .sidebar here, not .sidebar
            // nav, so it does not mean "distance down the scrollable content".
            // The rects are read synchronously on purpose -- getBoundingClientRect
            // flushes layout, so the submenu height applied by the nav-group
            // script just above is already accounted for. Do NOT defer this to
            // requestAnimationFrame: rAF does not run in a page that is not
            // compositing (background tab, hidden pane) and the adjustment
            // would silently never happen.
            var active = nav.querySelector('.nav-sublink.active') || nav.querySelector('a.active');

            if (active) {
                var a = active.getBoundingClientRect();
                var n = nav.getBoundingClientRect();

                if (a.top < n.top || a.bottom > n.bottom) {
                    nav.scrollTop += (a.top - n.top) - (n.height - a.height) / 2;
                }
            }
        }

        var pending;
        nav.addEventListener('scroll', function () {
            clearTimeout(pending);
            pending = setTimeout(function () {
                sessionStorage.setItem(KEY, nav.scrollTop);
            }, 100);
        }, { passive: true });

        // A click navigates away before that debounce can fire, so record the
        // position up front on the way out.
        nav.addEventListener('click', function () {
            sessionStorage.setItem(KEY, nav.scrollTop);
        });
    })();
    </script>

    {{-- Log out confirmation. Lives outside .main-content so the backdrop
         covers the sidebar too — a dialog you can click "behind" is not one. --}}
    <div class="remedi-modal" id="logoutModal" role="dialog" aria-modal="true"
         aria-labelledby="logoutModalTitle" aria-describedby="logoutModalBody">
        <div class="remedi-modal__panel">
            <div class="remedi-modal__icon"><i class="ti ti-logout" aria-hidden="true"></i></div>
            <h3 id="logoutModalTitle">Log out of REMEDI?</h3>
            <p id="logoutModalBody">
                You will need to sign in again to get back to the register.
            </p>
            <div class="remedi-modal__actions">
                <button type="button" class="btn btn-secondary" id="logoutCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="logoutConfirm">Log out</button>
            </div>
        </div>
    </div>

    {{-- Login-password pop-up guarding Safeguard (routes/web.php,
         `password.confirm`). Opened by the sidebar link's click, and opened on
         load by auth/confirm-password.blade.php when someone reaches the page
         directly -- so it is the SAME dialog either way, never a separate
         sign-in screen. A real POST form: with JavaScript off it still submits
         to password.confirm and the server redirects onward. --}}
    <div class="remedi-modal" id="passwordGateModal" role="dialog" aria-modal="true"
         aria-labelledby="passwordGateTitle" aria-describedby="passwordGateBody"
         data-target="{{ route('safeguard.edit') }}"
         @if(request()->routeIs('password.confirm'))
             data-auto-open="1"
             data-intended="{{ session('url.intended', route('safeguard.edit')) }}"
             data-cancel-href="{{ route('dashboard') }}"
         @endif>
        <form class="remedi-modal__panel" id="passwordGateForm" method="POST" action="{{ route('password.confirm') }}">
            @csrf
            <div class="remedi-modal__icon is-neutral"><i class="ti ti-shield-lock" aria-hidden="true"></i></div>
            <h3 id="passwordGateTitle">Confirm it's you</h3>
            <p id="passwordGateBody">
                This area holds the store's security controls and data. Enter your login password to continue.
            </p>
            <div class="remedi-modal__passcode">
                <label for="passwordGateInput">Login password</label>
                <input type="password" name="password" id="passwordGateInput" autocomplete="current-password" required>
                <p id="passwordGateError" role="alert" @if(! $errors->has('password')) hidden @endif
                   style="margin:8px 0 0; font-size:12.5px; color:#b91c1c;">{{ $errors->first('password') }}</p>
            </div>
            <div class="remedi-modal__actions">
                <button type="button" class="btn btn-secondary" id="passwordGateCancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="passwordGateConfirm">Continue</button>
            </div>
        </form>
    </div>

    {{-- Shared confirm dialog for every destructive or state-changing action:
         delete, activate/deactivate, mark-as-returned. Driven entirely by
         data-* attributes on the form (see the js-confirm handler at the foot
         of this file), so a new one costs a class and two attributes rather
         than another copy of this markup. Sits outside .main-content for the
         same reason #logoutModal does — the backdrop has to cover the sidebar.

         The title/body/label/icon are filled in per form; the tone class
         swaps the icon disc between destructive red and a neutral blue for
         actions that are reversible. --}}
    <div class="remedi-modal" id="confirmModal" role="dialog" aria-modal="true"
         aria-labelledby="confirmModalTitle" aria-describedby="confirmModalBody">
        <div class="remedi-modal__panel">
            <div class="remedi-modal__icon" id="confirmModalIcon">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            </div>
            <h3 id="confirmModalTitle">Are you sure?</h3>
            <p id="confirmModalBody"></p>
            {{-- Populated only when the form carries data-confirm-passcode="1"
                 (staff voiding a sale -- see sales/show.blade.php). Every
                 button below (reasons, or Confirm itself) stays disabled
                 until exactly 6 digits are entered, and the value is copied
                 into the form's own [name="passcode"] field the same way a
                 chosen reason is -- see the reason-button and confirmBtn
                 handlers below. --}}
            <div class="remedi-modal__passcode" id="confirmModalPasscode" hidden>
                <label for="confirmModalPasscodeInput">Manager passcode</label>
                {{-- type="password", not "text" -- this is a credential
                     someone else's screen or shoulder can be watching, same
                     as any other password field. inputmode/pattern still
                     bring up a numeric keypad on mobile; a password input
                     supports both. --}}
                <input type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="6"
                       id="confirmModalPasscodeInput" placeholder="6-digit code">
            </div>
            {{-- Populated only when the form carries data-confirm-reasons (a
                 JSON {value: label} map, e.g. User::ARCHIVE_REASONS) — one
                 button per reason, replacing the single generic Confirm
                 button below. Choosing one both answers "are you sure?" and
                 supplies the field the server requires, in one click rather
                 than a dropdown filled in beforehand and a second click to
                 confirm it. Empty and hidden for every other js-confirm
                 form, which keeps the single-button behaviour unchanged. --}}
            <div class="remedi-modal__reasons" id="confirmModalReasons" hidden></div>
            {{-- Shown only after picking the reason a form names in
                 data-confirm-note-for (Void -> "Other"): that reason has to be
                 explained, so it opens this box instead of submitting, and
                 the Confirm button stands in until something is typed.
                 Also shown from the start, OPTIONAL, for a form with
                 data-confirm-note="optional" (staff Notify admin): whatever
                 is typed goes in the form's [name="note"], blank is fine. --}}
            <div class="remedi-modal__note" id="confirmModalNote" hidden>
                <label for="confirmModalNoteInput" id="confirmModalNoteLabel">Reason</label>
                <input type="text" id="confirmModalNoteInput" maxlength="255" autocomplete="off"
                       placeholder="Type the reason">
            </div>
            {{-- The Yes / No question, for a form with data-confirm-ask="1"
                 (Void): picking a reason asks this instead of submitting, and
                 the buttons below read Yes / No. --}}
            <p class="remedi-modal__ask" id="confirmModalAsk" hidden></p>
            <div class="remedi-modal__actions">
                <button type="button" class="btn btn-secondary" id="confirmModalCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalConfirm">Confirm</button>
            </div>
        </div>
    </div>

    {{-- Message popup. Replaces window.alert() — the POS out-of-stock and
         over-cart-limit warnings used to be browser alerts, which look like a
         browser error, block the whole tab, and cannot say anything the server
         knows. This is the same card as the confirm dialog, and the POS fills
         its detail line from a live /pos/lookup, so the figure the cashier is
         told is the figure on the shelf right now rather than whatever the page
         was rendered with. --}}
    <div class="remedi-modal" id="messageModal" role="dialog" aria-modal="true"
         aria-labelledby="messageModalTitle" aria-describedby="messageModalBody">
        <div class="remedi-modal__panel">
            <div class="remedi-modal__icon" id="messageModalIcon">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            </div>
            <h3 id="messageModalTitle">Notice</h3>
            <p id="messageModalBody"></p>
            <ul class="alert-modal-list" id="messageModalList" hidden></ul>
            <p class="alert-modal-state" id="messageModalState" hidden></p>
            <div class="remedi-modal__actions">
                <button type="button" class="btn btn-primary" id="messageModalOk">OK</button>
            </div>
        </div>
    </div>

    {{-- Alert toasts. Bottom-right, ONE AT A TIME, each self-dismissing after
         5s with a chime.

         Two ways in, one queue:
           - the GREETING, the open alerts as they stood when the page was
             rendered, played once per browser session; and
           - LIVE pops, queued when the bell's poll turns up a notification that
             was not in the list before.

         Both name the individual product ("BIOGESIC 500MG — 2 PCS left") rather
         than summarising a kind, and carry the moment the alert began. The seed
         below is the same $topbarAlertItems the bell renders, so this costs no
         query and cannot disagree with the panel it sits under. --}}
    @php
        // All four stock kinds. fail_to_return is deliberately left to the bell:
        // a missed return window is a standing regret, not something to
        // interrupt anyone about.
        //
        // Plus account CHANGES -- a user added, updated, deleted, activated or
        // deactivated. Those are rare, deliberate, and usually done by someone
        // else, which is exactly the shape of thing a pop-up is for; a shelf
        // running low is a standing condition the bell can hold. Sign-ins are
        // excluded at the source (AlertService::ACCOUNT_KIND) -- a card every
        // time anybody logs in would make the stack useless by lunchtime.
        // And a staff member's stock report (STOCK_REPORT_KIND, 2026-09-28):
        // a colleague waiting on a decision.
        $toastKinds = ['low_stock', 'expiring', 'expired', 'need_to_return', \App\Services\AlertService::ACCOUNT_KIND, \App\Services\AlertService::STOCK_REPORT_KIND];

        // $topbarActivity is ALREADY admin-only -- the view composer resolves it
        // to [] for staff, and AlertController does the same for the polled
        // feed. So this adds nothing to a staff bell and needs no second gate;
        // note the rows are merged HERE, in a per-request render, never into
        // payload()'s cache, which every signed-in user shares.
        $toastSeed = [
            'kinds' => $toastKinds,

            // AlertService orders each source newest-first, but MERGING two of
            // them appends rather than interleaves -- so account rows landed at
            // the END of the list however recent they were, and the greeting,
            // which takes the first MAX_VISIBLE, could never reach them. Sorted
            // on `sort_at`, the onset stamp every row on both sides carries, so
            // "most recent first" means it across both sources.
            'items' => collect($topbarAlertItems ?? [])
                ->merge($topbarActivity ?? [])
                ->whereIn('kind', $toastKinds)
                ->sortByDesc('sort_at')
                ->values()
                ->all(),
        ];
    @endphp

    {{-- Always rendered, even with nothing to say: it is the mount point live
         pops are appended to, and a container that only existed when the page
         happened to load with an open alert could never receive one. --}}
    <div class="remedi-toasts" id="remediToasts" role="status" aria-live="polite"
         data-user="{{ auth()->id() }}"
         data-fresh-login="{{ session('remedi.just_signed_in') ? '1' : '0' }}"></div>

    {{-- JSON rather than data-attributes: these strings are product names, and
         the hex flags keep a name containing </script> or a quote from breaking
         out of the block. --}}
    <script type="application/json" id="remediToastSeed">@json($toastSeed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" title="Show/hide sidebar" aria-label="Show/hide sidebar">
                    <i class="ti ti-menu-2" aria-hidden="true"></i>
                </button>
                <h2>@yield('title', 'Dashboard')</h2>
            </div>


            <div class="topbar-user">
                {{-- Notification bell. Count and list both come from
                     AlertService (see AppServiceProvider), so the badge can
                     never disagree with the panel it opens or with the
                     dashboard's Alerts card.

                     Rendered server-side first so it is correct before any JS
                     runs and without a request on load; the script below then
                     polls /alerts to keep it live. --}}
                <div class="topbar-bell-wrap" id="bellWrap">
                    <button type="button" class="topbar-bell" id="bellBtn"
                            aria-haspopup="true" aria-expanded="false" aria-controls="bellPanel"
                            title="{{ $topbarAlertCount ?? 0 }} {{ Str::plural('notification', $topbarAlertCount ?? 0) }}"
                            aria-label="Alerts ({{ $topbarAlertCount ?? 0 }} notifications)">
                        <i class="ti ti-bell" aria-hidden="true"></i>
                        <span class="count" id="bellCount"
                              @if(($topbarAlertCount ?? 0) < 1) hidden @endif>{{ ($topbarAlertCount ?? 0) > 9 ? '9+' : ($topbarAlertCount ?? 0) }}</span>
                    </button>

                    <div class="topbar-bell-panel" id="bellPanel" role="region" aria-label="Alerts">
                        <div class="topbar-bell-head">
                            <strong>Notifications</strong>
                            <div class="topbar-bell-headactions">
                                <button type="button" id="bellMarkRead" class="topbar-bell-markread" hidden>
                                    Mark all as read
                                </button>
                                <button type="button" id="bellClose" class="topbar-bell-close" aria-label="Close notifications">
                                    <i class="ti ti-x" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        {{-- Tabs. Staff only ever see inventory alerts, so the
                             System/Updates tabs are not rendered for them at
                             all rather than shown empty — the audit trail they
                             read from is admin-only. --}}
                        {{-- Staff get the Alerts tab alone. System and Updates
                             read from the audit trail, which is admin-only, so a
                             staff bell has nothing but alerts in it -- which made
                             "All" and "Alerts" two buttons producing the identical
                             list. The remaining tab is not really a filter for
                             them; it labels what they are looking at. --}}
                        <div class="topbar-bell-tabs" role="tablist">
                            @if(auth()->user()?->isAdmin())
                                <button type="button" class="bell-tab is-active" data-tab="all" role="tab" aria-selected="true">All</button>
                                <button type="button" class="bell-tab" data-tab="alerts" role="tab" aria-selected="false">Alerts</button>
                                <button type="button" class="bell-tab" data-tab="system" role="tab" aria-selected="false">System</button>
                                <button type="button" class="bell-tab" data-tab="updates" role="tab" aria-selected="false">Updates</button>
                            @else
                                <button type="button" class="bell-tab is-active" data-tab="alerts" role="tab" aria-selected="true">Alerts</button>
                            @endif
                        </div>
                        <small id="bellStamp" hidden>just now</small>
                        {{-- Item-level rows: each names the product it is about,
                             the way a notification feed does. The kind-level
                             totals moved to the footer below. --}}
                        <div id="bellList">
                            @php
                                // One feed, newest first — the same order the
                                // script re-applies after every poll, so the
                                // server-rendered first frame already matches
                                // what the panel settles on.
                                $bellFeed = collect(array_merge($topbarAlertItems ?? [], $topbarActivity ?? []))
                                    ->sortByDesc(fn ($i) => strtotime($i['sort_at'] ?? $i['at'] ?? '@0'))
                                    ->values();
                            @endphp
                            @forelse($bellFeed as $item)
                                <a href="{{ $item['href'] }}" class="topbar-bell-row {{ $item['cls'] }}"
                                   data-alert-id="{{ $item['id'] ?? '' }}"
                                   data-group="{{ $item['group'] ?? 'alerts' }}"
                                   data-sort-at="{{ $item['sort_at'] ?? $item['at'] ?? '' }}"
                                   @if(!empty($item['at'])) data-at="{{ $item['at'] }}" @endif>
                                    <span class="bell-icon" aria-hidden="true"><i class="ti {{ $item['icon'] }}"></i></span>
                                    <span class="bell-text">
                                        <strong>{{ $item['title'] }}</strong>
                                        <small>{{ $item['body'] }}</small>
                                        {{-- Every row carries a time now. data-when is the
                                             absolute label AlertService formatted (an audit
                                             row's event time, an alert's "Since <onset>");
                                             data-at is added only for rows that are a real
                                             EVENT, and drives the "x ago" the script
                                             re-stamps every minute. An inventory alert gets
                                             no "x ago" because it is a standing condition --
                                             saying it happened 2 minutes ago would be a lie,
                                             which is why the onset is labelled instead. --}}
                                        @if(!empty($item['when']))
                                            <em class="bell-time" data-when="{{ $item['when'] }}"
                                                @if(!empty($item['at'])) data-at="{{ $item['at'] }}" @endif
                                                @if(empty($item['at']) && !empty($item['sort_at'])) data-since="{{ $item['sort_at'] }}" @endif
                                            >{{ $item['when'] }}</em>
                                        @endif
                                        {{-- Styled as a button, and deliberately a SPAN.
                                             The row is already an <a>, and interactive
                                             content cannot nest inside one -- a <button>
                                             or second <a> here is invalid HTML and
                                             browsers recover from it by splitting the
                                             anchor, which breaks the row. The whole row
                                             carries the same href, so the pill is
                                             clickable in the only sense that matters; it
                                             is there to name the destination. --}}
                                        @if(!empty($item['action']))
                                            <span class="bell-action">{{ $item['action'] }} <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
                                        @endif
                                    </span>
                                    <span class="unread-dot" aria-hidden="true"></span>
                                </a>
                            @empty
                                <p class="topbar-bell-empty">Nothing needs attention right now.</p>
                            @endforelse
                        </div>

                        {{-- The panel shows a few of each kind, so the totals
                             have to stay reachable or the bell would imply
                             three low-stock products when there are 645. --}}
                        <div id="bellFoot" class="topbar-bell-foot">
                            @foreach(($topbarAlerts ?? []) as $alert)
                                <a href="{{ $alert['href'] }}">
                                    View all {{ number_format($alert['count']) }} {{ $alert['short'] }}
                                </a>
                            @endforeach
                        </div>

                        <a href="{{ route('notifications.index') }}" class="topbar-bell-viewall">
                            View all notifications <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>

                {{-- The avatar and name are the obvious thing to click to get to
                     your own account, so they are a link rather than decoration.
                     data-no-skeleton: this is one page, not a section change --
                     see the navigation-skeleton note in the script below. --}}
                <a href="{{ route('profile.edit') }}" class="topbar-identity" data-no-skeleton
                   title="Go to My Profile">
                <div class="user-avatar">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </div>
                <div class="topbar-role">
                    <strong>{{ auth()->user()->name }}</strong>
                    <small>{{ auth()->user()->isAdmin() ? 'Administrator' : 'Staff' }}</small>
                </div>
                </a>
            </div>
        </div>

        <div class="content-body">
            {{-- Server-rendered outcomes. data-flash marks them for the script
                 below, which re-shows each one in the shared message dialog and
                 hides the banner. Rendered in the page rather than injected by
                 JS so that with JavaScript off they are still read normally --
                 the dialog is the enhancement, the banner is the floor. --}}
            @if(session('success'))
                <div class="alert alert-success" data-flash="success">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger" data-flash="error">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger" data-flash="error" data-flash-title="Please check the form">
                    <i class="ti ti-alert-circle" aria-hidden="true"></i>
                    <div>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- Shown in place of the real content while a sidebar navigation
                 is in flight. Intentionally generic: it stands in for every
                 page, so it suggests "a page is coming" rather than mimicking
                 any one layout. --}}
            {{-- Shaped like the dashboard it most often stands in for: greeting
                 bar, six KPI tiles, two charts. Close enough that the real page
                 lands in roughly the same places instead of jumping. --}}
            @include('partials._page-skeleton')

            @yield('content')
        </div>
    </div>

</div>

{{-- The shared page script lives in public/assets/remedi.js (moved out 2026-09-28,
     111 KB per page before). Still synchronous and still HERE, at the end of the
     body after @yield('content'), so it runs in the same order it always did.
     The three values it needs from the server ride in REMEDI_BOOT. --}}
<script>window.REMEDI_BOOT = @json(['userId' => auth()->id() ?? 0, 'alertsUrl' => route('alerts.index'), 'loginUrl' => route('login')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
<script src="{{ \App\Support\StaticAsset::url('assets/remedi.js') }}"></script>
</body>
</html>