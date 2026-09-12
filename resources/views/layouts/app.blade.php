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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <style>
        /* ── Brand palette ──
           One place to change the theme. The app was indigo on a dark slate
           sidebar; it is now emerald on white, matching the reference design.
           Everything below refers to these rather than repeating hex codes. */
        :root {
            --brand:        #10b981;   /* primary actions, active nav      */
            --brand-dark:   #059669;   /* hover/pressed                    */
            --brand-darker: #047857;   /* text on tinted backgrounds       */
            --brand-tint:   #ecfdf5;   /* active nav pill, hover wash      */
            --brand-soft:   #d1fae5;   /* borders, focus rings             */

            --ink:          #1e293b;   /* headings and primary text        */
            --ink-soft:     #64748b;   /* labels, secondary text           */
            --line:         #e2e8f0;   /* borders and dividers             */
            --surface:      #fff;

            /* Sidebar is a dark slab, as in the reference designs. Its own
               tokens rather than reusing --ink, so the nav can be retinted
               without touching body text. */
            --nav-bg:       #0c3b33;   /* deep teal-green            */
            --nav-fg:       #9fbdb4;   /* idle labels                */
            --nav-fg-hover: #e6f5f0;   /* hover / emphasis           */
            --nav-line:     #1b5148;   /* dividers on the dark slab  */
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
            background: #f1f5f9;
            margin: 0;
            color: #1e293b;
            font-size: 16px;
        }

        .layout-wrapper { display: flex; min-height: 100vh; }

        /* Only the mobile breakpoint turns this on. */
        .sidebar-scrim { display: none; }

        /* ── Sidebar ── */
        .sidebar {
            width: 280px;
            min-width: 280px;
            min-height: 100vh;
            background: var(--nav-bg);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow: hidden;
            transition: width 0.22s ease, min-width 0.22s ease, opacity 0.18s ease;
        }

        .sidebar > * { width: 280px; }

        /* Hidden state: collapse the sidebar away entirely */
        html[data-sidebar-hidden="true"] .sidebar {
            width: 0;
            min-width: 0;
            opacity: 0;
            pointer-events: none;
        }

        /* Hamburger toggle button, lives in the topbar */
        .sidebar-toggle-btn {
            background: none;
            border: none;
            cursor: pointer;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            font-size: 20px;
            flex-shrink: 0;
        }

        .sidebar-toggle-btn:hover { background: #f1f5f9; color: #1e293b; }

        .topbar-left { display: flex; align-items: center; gap: 14px; }

        /* ── Brand ── */
        .sidebar .brand {
            padding: 24px 20px 18px;
            display: flex;
            align-items: center;
            /* Mark + wordmark centred as one group in the sidebar's width,
               rather than flush left against the padding. */
            justify-content: center;
            gap: 12px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 12px;
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .16);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #6ee7b7;
            overflow: hidden;
        }

        /* 34px, not 30: the mark carries a capsule, a cross, an R and a chart,
           and every extra pixel counts at this size.

           object-fit because the artwork is 512x477, not square -- given a
           square box without it the browser stretches it and the capsule goes
           fat. */
        .brand-mark img { display: block; width: 34px; height: 34px; object-fit: contain; }

        /* align-items so REMEDI and the tagline centre on each other; without
           it the two lines stay left-aligned inside a centred group, which
           reads as off-centre. */
        .brand-text { display: flex; flex-direction: column; align-items: center; line-height: 1; }

        .sidebar .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            color: #fff;
            display: block;
            line-height: 1;
        }

        .sidebar .brand-name span { color: var(--brand); }

        .sidebar .brand-tagline {
            font-family: 'Outfit', sans-serif;
            font-size: 9px;
            font-weight: 500;
            letter-spacing: 0.22em;
            color: #6f938a;
            text-transform: uppercase;
            margin-top: 5px;
            display: block;
        }

        /* ── Promo card at the foot of the nav ── */
        .sidebar-promo {
            margin: 14px 12px 4px;
            padding: 16px 15px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .06);
            border: 1px solid var(--nav-line);
        }

        .sidebar-promo strong {
            display: block;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            line-height: 1.45;
            color: #e6f5f0;
        }

        .sidebar-promo i {
            display: block;
            margin-top: 10px;
            font-size: 30px;
            color: #6ee7b7;
            opacity: .9;
        }

        .sidebar .brand-divider {
            height: 0.5px;
            background: var(--nav-line);
            margin: 0 22px 16px;
        }

        /* ── Nav ── */
        .sidebar nav {
            padding: 0 12px;
            flex: 1;
            overflow-y: auto;

            /* The default chrome scrollbar is a light-grey slab, which on the
               dark teal slab reads as a seam down the edge of the panel. Tint
               it to the nav's own palette instead. Firefox/standards first,
               WebKit below. */
            /* --nav-line (#1b5148) was too close to the slab behind it to see.
               The thumb is a mid emerald with a faint track behind it, so the
               category list reads as scrollable at a glance -- which matters,
               because with the submenu open more than half of it is out of
               view. */
            scrollbar-width: thin;
            scrollbar-color: #3f9d7c rgba(255, 255, 255, .07);
        }

        .sidebar nav::-webkit-scrollbar { width: 10px; }

        .sidebar nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, .07);
            border-radius: 999px;
        }

        .sidebar nav::-webkit-scrollbar-thumb {
            background: #3f9d7c;
            border-radius: 999px;
            /* Inset the thumb so it reads as a pill inside the track rather
               than filling the gutter edge to edge. */
            border: 2px solid transparent;
            background-clip: padding-box;
        }

        .sidebar nav::-webkit-scrollbar-thumb:hover {
            background: var(--brand);
            background-clip: padding-box;
        }

        .sidebar a {
            color: var(--nav-fg);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 15px;
            border-radius: 7px;
            font-size: 15px;
            margin-bottom: 3px;
        }

        .sidebar a i { font-size: 19px; }

        .sidebar a:hover {
            background: rgba(255, 255, 255, .07);
            color: var(--nav-fg-hover);
        }

        /* Active nav reads as a tinted pill with a brand-coloured label, the
           way the reference design marks the current page. */
        .sidebar a.active {
            background: var(--brand);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 6px 14px -6px rgba(16, 185, 129, .7);
        }

        .sidebar a.active i { color: #fff; }

        .sidebar .nav-section {
            font-size: 11px;
            font-weight: 500;
            color: #6f938a;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 6px 15px;
        }

        /* ── Inventory nav group: a toggle button that expands into one
               sub-link per product category ── */
        .nav-group { margin-bottom: 3px; }

        .nav-link-btn {
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            color: var(--nav-fg);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 15px;
            border-radius: 7px;
            font-size: 15px;
        }

        .nav-link-btn i:first-child { font-size: 19px; }

        .nav-link-btn:hover {
            background: rgba(255, 255, 255, .07);
            color: var(--nav-fg-hover);
        }

        .nav-link-btn.active {
            background: var(--brand);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 6px 14px -6px rgba(16, 185, 129, .7);
        }

        .nav-link-btn.active i { color: #fff; }

        .nav-caret {
            margin-left: auto;
            font-size: 15px !important;
            transition: transform 0.18s ease;
        }

        .nav-group.open .nav-caret { transform: rotate(180deg); }

        .nav-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.2s ease;
        }

        /* Suppress the expand animation on first paint when the group is
           restored already-open, so it doesn't unfurl on every page load. */
        html.nav-no-anim .nav-submenu { transition: none; }

        .nav-group.open .nav-submenu {
            max-height: 640px;
        }

        /* Indent the whole submenu under the "Inventory" label (15px sidebar
           padding + 19px icon + 11px gap = 45px) and run a guide line down
           the left edge, so the category links read as children of the tab
           rather than as siblings of it. */
        .nav-submenu-inner {
            margin: 2px 0 4px 26px;
            padding-left: 12px;
            border-left: 1.5px solid var(--nav-line);
        }

        .nav-sublink {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 12px;
            border-radius: 7px;
            font-size: 13.5px;
            color: var(--nav-fg);
            text-decoration: none;
            margin-bottom: 2px;
        }

        .nav-sublink:hover {
            background: rgba(255, 255, 255, .07);
            color: var(--nav-fg-hover);
        }

        .nav-sublink.active {
            background: var(--brand-tint);
            color: var(--brand-darker);
            font-weight: 600;
        }

        .nav-sublink .nav-sub-count {
            margin-left: auto;
            font-size: 11px;
            color: #6f938a;
        }

        .nav-sublink.active .nav-sub-count { color: #d1fae5; }

        /* ── Navigation feedback ──
           Pages are server-rendered, so between the click and the new
           document the browser shows nothing at all — on the heavier pages
           that reads as "my click didn't register". Clicking a nav item
           highlights it (so you can see which one you hit) and swaps the
           content area for a skeleton. Both are torn down by the incoming
           document.

           Deliberately no spinner on the nav item itself — the highlight
           marks the target and the skeleton carries the loading signal. */
        @keyframes remedi-shimmer {
            0%   { background-position: -420px 0; }
            100% { background-position: 420px 0; }
        }

        .sidebar nav a.is-loading,
        .sidebar nav .nav-link-btn.is-loading {
            background: var(--brand-tint);
            color: var(--brand-darker);
        }

        .page-skeleton { display: none; }

        .content-body.is-navigating > *:not(.page-skeleton) { display: none !important; }
        .content-body.is-navigating > .page-skeleton { display: block; }

        /* Neutral grey on purpose: a skeleton stands in for content, so it
           should recede rather than compete with it. A brand-tinted version was
           tried and read as a coloured panel in its own right. */
        /* ── "Still working" pill in the header ────────────────────────
           A live marker beside the page title, so a wait is legible without
           looking at the content area at all -- and it reads the same on every
           page, because the header is the one thing that does not change.

           Moved here from dashboard/index.blade.php, which used to own it: the
           dashboard raises one while it fetches its own body over AJAX, and the
           sidebar navigation below raises one while a whole document is in
           flight. Same class, same look, two occasions -- so there is one
           definition rather than two that drift. */
        .topbar-loading {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--ink-soft);
            white-space: nowrap;
        }

        .topbar-loading::before {
            content: '';
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--brand);
            animation: dash-pulse 1.1s ease-in-out infinite;
        }

        @keyframes dash-pulse { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }

        @media (max-width: 640px) {
            .topbar-loading { display: none; }   /* no room beside the title */
        }

        @media (prefers-reduced-motion: reduce) {
            .topbar-loading::before { animation: none; }
        }

        /* One skeleton partial, three silhouettes.
           A dashboard-shaped placeholder in front of the Sales table was worse
           than none: six KPI tiles and two charts flashed up, then the real page
           landed as a header over rows, so every tab change was two unrelated
           layouts in a row. The shape is chosen from the destination link; no
           attribute means the dashboard, which is what the dashboard's own
           loading backdrop wants.
           POS got its own shape for the same reason: it's a product-tile grid
           beside a cart panel, nothing like either a KPI dashboard or a table
           of rows, and standing in with "list" showed nine placeholder table
           rows for a page that has never had a table. Forecast and Sales
           Forecasting route to "dash" -- stat tiles over charts, no big row
           table -- which is a closer match than "list" even though neither is
           the dashboard itself. */
        .page-skeleton .sk-shape { display: none; }
        .page-skeleton[data-shape="dash"] .sk-shape-dash,
        .page-skeleton:not([data-shape]) .sk-shape-dash { display: block; }
        .page-skeleton[data-shape="list"] .sk-shape-list { display: block; }
        .page-skeleton[data-shape="pos"] .sk-shape-pos { display: block; }
        .page-skeleton[data-shape="feed"] .sk-shape-feed { display: block; }

        .sk-head { height: 26px; width: 210px; margin-bottom: 10px; }
        .sk-head-sub { height: 13px; width: 330px; margin-bottom: 22px; }
        .sk-toolbar { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .sk-search { height: 40px; flex: 1 1 320px; border-radius: 8px; }
        .sk-btn { height: 40px; width: 104px; border-radius: 8px; }

        .sk-table {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 8px 18px;
            background: var(--surface);
        }

        .sk-trow { display: flex; gap: 18px; align-items: center; padding: 13px 0; }
        .sk-trow + .sk-trow { border-top: 1px solid #f1f5f9; }
        .sk-trow .sk-block { height: 13px; }
        .sk-td { flex: 1 1 0; }
        .sk-td.narrow { flex: 0 0 46px; }
        .sk-td.wide { flex: 2 1 0; }

        @media (max-width: 700px) {
            .sk-trow .sk-td:nth-child(n+4) { display: none; }
        }

        .sk-block {
            /* Deliberately pale. #e2e8f0 was heavy enough to read as real
               content, which is the opposite of what a placeholder should do —
               it should sit just above the page background and no more. */
            background: #eef2f7;
            background-image: linear-gradient(90deg, #eef2f7 0px, #f8fafc 180px, #eef2f7 360px);
            background-size: 840px 100%;
            border-radius: 8px;
            animation: remedi-shimmer 1.3s linear infinite;
        }

        .sk-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .sk-card { flex: 1 1 200px; height: 92px; }
        .sk-title { width: 240px; height: 26px; margin-bottom: 18px; }
        .sk-panel { height: 300px; margin-bottom: 16px; }
        .sk-line { height: 40px; margin-bottom: 10px; }
        .sk-line.w80 { width: 80%; }
        .sk-line.w60 { width: 60%; }

        /* Greeting bar: heading + subtitle on the left, date pill on the right. */
        .sk-greeting {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
        }

        .sk-greeting-text { flex: 1; max-width: 380px; }
        .sk-h { height: 24px; width: 260px; margin-bottom: 8px; }
        .sk-sub { height: 14px; width: 320px; }
        .sk-pill { height: 38px; width: 230px; border-radius: 10px; }

        /* Six KPI tiles on .kpi-grid's own track. Each is a real card outline
           with the tile's internals sketched inside -- icon disc, value, sub —
           rather than one flat block, so the shimmer sits where the content
           will actually land. */
        .sk-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(178px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .sk-kpi {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            padding: 16px 18px;
        }

        .sk-kpi-head { display: flex; align-items: center; gap: 11px; margin-bottom: 12px; }
        .sk-disc { width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0; }
        .sk-cap { height: 10px; width: 74px; }
        .sk-num { height: 22px; width: 90px; margin-bottom: 8px; }
        .sk-note { height: 10px; width: 118px; }

        /* Tab pills + section heading, so the page does not shift when the
           real switcher and title arrive. */
        .sk-tabs { display: flex; gap: 10px; margin-bottom: 26px; flex-wrap: wrap; }
        .sk-pill-tab { height: 41px; width: 104px; border-radius: 999px; }
        .sk-sec-title { height: 20px; width: 130px; margin-bottom: 8px; }
        .sk-sec-sub { height: 12px; width: 210px; margin-bottom: 18px; }

        /* Two charts side by side, then a wide/narrow row, matching the
           dashboard's chart row and lower strip. */
        .sk-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .sk-lower {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 16px;
        }

        @media (max-width: 1100px) {
            .sk-charts, .sk-lower { grid-template-columns: 1fr; }
        }

        .sk-chart {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            height: 290px;
            padding: 18px;
        }

        .sk-chart-title { height: 13px; width: 160px; margin-bottom: 16px; }
        .sk-chart-body { height: 220px; border-radius: 8px; }

        /* POS: a product-tile grid beside a sticky cart panel -- the same
           2fr/1fr split pos/index.blade.php's own .pos-layout uses, so the
           real grid and cart land in the same place the placeholders were. */
        .sk-pos-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }

        .sk-pos-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .sk-pos-tile {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 10px;
            padding: 14px;
            height: 108px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .sk-pos-tile-title { height: 12px; width: 85%; }
        .sk-pos-tile-sub { height: 10px; width: 55%; }
        .sk-pos-tile-price { height: 15px; width: 40%; }

        .sk-pos-cart {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            padding: 16px;
            height: fit-content;
        }

        .sk-pos-cart-title { height: 16px; width: 96px; margin-bottom: 16px; }
        .sk-pos-cart-row { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
        .sk-pos-cart-name { height: 12px; flex: 1 1 auto; }
        .sk-pos-cart-qty { height: 12px; width: 28px; flex: 0 0 auto; }
        .sk-pos-cart-total { height: 20px; margin-top: 10px; margin-bottom: 14px; }
        .sk-pos-cart-btn { height: 44px; border-radius: 8px; }

        /* Mirrors pos/index.blade.php's own breakpoint: the cart drops below
           the grid on tablet and phone rather than squeezing to a sliver. */
        @media (max-width: 1024px) {
            .sk-pos-layout { grid-template-columns: 1fr; }
        }

        /* ── The "list" shape's data-* switches ──────────────────────────
           _page-skeleton.blade.php renders the full superset (4 KPI tiles,
           a rich toolbar, 7 tabs, 11 table columns) once; these rules hide
           whatever a given destination page doesn't actually have, driven
           by data-header/-kpis/-toolbar/-tabs/-cols on .page-skeleton (set
           per route in the nav-skeleton JS below). Defaults with no
           attribute match the plainest case -- a header, no KPIs, a
           simple two-control toolbar, no tabs, six columns -- so a route
           this table doesn't yet know about degrades to that rather than
           to the fullest (busiest) rendering. */

        /* Header: off for Sales, Products, Inventory (none of the three
           renders a heading inside its own content body). */
        .page-skeleton[data-header="0"] .sk-shape-list .sk-head,
        .page-skeleton[data-header="0"] .sk-shape-list .sk-head-sub { display: none; }

        /* KPI row: 0 by default. Users and the audit trail show 4 real
           stat cards, Sales shows 2 -- everyone else (Products, Categories,
           Inventory) shows none. */
        .sk-kpis-sm {
            display: none;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .page-skeleton[data-kpis="2"] .sk-shape-list .sk-kpis-sm,
        .page-skeleton[data-kpis="4"] .sk-shape-list .sk-kpis-sm { display: grid; }
        .page-skeleton[data-kpis="2"] .sk-shape-list .sk-kpi-sm:nth-child(n+3) { display: none; }

        .sk-kpi-sm {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .sk-kpi-sm-cap { height: 10px; width: 70px; margin-bottom: 10px; }
        .sk-kpi-sm-num { height: 20px; width: 54px; }

        /* Toolbar: "simple" (default) shows just the search box and one
           button, matching Users/Products/Inventory. "rich" adds the two
           extra fields and second button Sales (date range) and the audit
           trail (its several selects/dates) actually have -- .sk-extra
           marks exactly those, rather than picking them out by position,
           which broke the first time (nth-child(n+4) matched BOTH buttons,
           since both sit at position 4+ in the row, hiding the one button
           "simple" is supposed to keep). */
        .sk-toolbar .sk-extra { display: none; }
        .page-skeleton[data-toolbar="rich"] .sk-shape-list .sk-toolbar .sk-extra { display: block; }

        .sk-field { height: 40px; flex: 1 1 130px; border-radius: 8px; }

        /* Card toolbar: Categories replaces the whole search row with an
           "Add Category" trigger card, so this is an alternate ELEMENT, not
           a variant of .sk-toolbar -- data-toolbar="card" swaps one for
           the other rather than showing/hiding pieces of one shape. */
        .sk-toolbar-card { display: none; }

        .page-skeleton[data-toolbar="card"] .sk-shape-list .sk-toolbar { display: none; }
        .page-skeleton[data-toolbar="card"] .sk-shape-list .sk-toolbar-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .sk-toolbar-card-text { flex: 1; }

        /* Tabs: none by default (Users/Sales/the audit trail/Products/
           Categories have no filter chips at all); Inventory's real 7
           status chips (All/Low Stock/Expiring/Expired/Need to Return/
           Fail to Return/Returned) are the one case that needs them. */
        .sk-shape-list .sk-tabs { display: none; }
        .page-skeleton[data-tabs="7"] .sk-shape-list .sk-tabs { display: flex; }

        /* Table columns: 6 by default (Users/Sales/the audit trail all
           land close to six real columns). Categories' sparse 3-column
           table and Products/Inventory's dense 11-column tables are the
           two real departures from that. */
        .sk-shape-list .sk-td:nth-child(n+7) { display: none; }
        .page-skeleton[data-cols="3"] .sk-shape-list .sk-td:nth-child(n+4) { display: none; }
        .page-skeleton[data-cols="11"] .sk-shape-list .sk-td { display: block; }

        /* Notifications' feed: icon, title/body, time -- three cards per
           row, not six-to-eleven table cells, which is what made forcing
           it through the list shape the worst mismatch of any page. */
        .sk-feed {
            background: #fff;
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            padding: 6px 18px;
        }

        .sk-feed-row { display: flex; align-items: center; gap: 14px; padding: 14px 0; }
        .sk-feed-row + .sk-feed-row { border-top: 1px solid #f1f5f9; }
        .sk-feed-icon { width: 36px; height: 36px; flex-shrink: 0; }
        .sk-feed-text { flex: 1; min-width: 0; }
        .sk-feed-title { height: 12px; width: 65%; margin-bottom: 8px; }
        .sk-feed-body { height: 10px; width: 85%; }
        .sk-feed-time { width: 64px; height: 10px; flex-shrink: 0; }

        /* Notifications' own 7 tabs are narrower than the dashboard's
           104px pills -- at that width, seven of them plus gaps would run
           to ~800px and wrap awkwardly on anything but a wide desktop. */
        .sk-pill-tab-sm { width: 74px; height: 34px; }

        @media (prefers-reduced-motion: reduce) {
            .sk-block { animation: none; }
        }

        .sidebar hr {
            border: none;
            border-top: 0.5px solid var(--nav-line);
            margin: 10px 0;
        }

        .sidebar-footer {
            padding: 14px 20px;
            border-top: 0.5px solid var(--nav-line);
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 0;
            color: var(--nav-fg);
            text-decoration: none;
            font-size: 15px;
        }

        .sidebar-footer a i { font-size: 19px; }
        .sidebar-footer a:hover { color: var(--nav-fg-hover); }

        .logout-btn {
            background: none;
            border: none;
            color: var(--nav-fg);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 0;
            width: 100%;
            font-family: inherit;
        }

        .logout-btn i { font-size: 19px; }
        .logout-btn:hover { color: #fca5a5; }

        /* ── Main content ── */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow: hidden;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 32px;
            background: #fff;
            border-bottom: 0.5px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 500;
            color: #1e293b;
        }

        .topbar-user { display: flex; align-items: center; gap: 12px; }

        .topbar-bell {
            position: relative;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid var(--line);
            background: #fff;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            text-decoration: none;
            transition: background .15s ease, border-color .15s ease;
        }

        .topbar-bell:hover { background: var(--brand-tint); border-color: var(--brand-soft); }

        .topbar-bell .count {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Notification bell dropdown ──
           The bell is a real control, not a shortcut link: it opens the list of
           what is actually open so you can pick the one you care about, instead
           of guessing that the badge means "low stock" and landing on the wrong
           filter. The panel is anchored to .topbar-bell-wrap. */
        .topbar-bell-wrap { position: relative; display: inline-flex; }

        .topbar-bell-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            /* Sized for what the panel now holds: a tab strip, 38px icon discs
               and a timestamp line per row. At the old 330x420 the rows wrapped
               to three lines each and 18 notifications sat in a box that showed
               four. Still anchored to the bell (right: 0 on the wrapper), so it
               stays under the button rather than floating. */
            width: 380px;
            max-height: 540px;
            overflow-y: auto;
            overscroll-behavior: contain;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 4px 6px -2px rgba(15, 23, 42, .06),
                        0 18px 36px -12px rgba(15, 23, 42, .28);
            padding: 6px;
            z-index: 120;
            display: none;
        }

        .topbar-bell-panel.is-open { display: block; }

        .topbar-bell-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 10px 10px;
            border-bottom: 1px solid var(--line);
            margin-bottom: 6px;
        }

        .topbar-bell-head { align-items: center; }
        .topbar-bell-head strong { font-size: 16px; font-weight: 700; color: var(--ink); }
        .topbar-bell-head small { font-size: 11px; color: #94a3b8; }

        .topbar-bell-headactions { display: flex; align-items: center; gap: 10px; }

        .topbar-bell-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            padding: 0;
            border: 0;
            border-radius: 7px;
            background: none;
            color: #94a3b8;
            font-size: 17px;
            cursor: pointer;
        }

        .topbar-bell-close:hover { background: #f1f5f9; color: var(--ink); }

        /* Tabs. Underline on the active one rather than a filled pill: the rows
           below already carry a lot of colour, and two competing fills made the
           panel read as two separate lists. */
        .topbar-bell-tabs {
            display: flex;
            gap: 2px;
            padding: 0 4px;
            margin-bottom: 6px;
            border-bottom: 1px solid var(--line);
        }

        .bell-tab {
            flex: 1;
            padding: 9px 4px;
            border: 0;
            border-bottom: 2px solid transparent;
            background: none;
            font: inherit;
            font-size: 12.5px;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
        }

        .bell-tab:hover { color: var(--ink); }
        .bell-tab.is-active { color: var(--brand-darker); font-weight: 600; border-bottom-color: var(--brand); }

        /* Circular tinted disc, one per severity, in place of the bare glyph. */
        .bell-icon {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: #f1f5f9;
            color: #64748b;
        }

        .topbar-bell-row.is-low .bell-icon      { background: #fef9c3; color: #a16207; }
        .topbar-bell-row.is-out .bell-icon     { background: #e2e8f0; color: #334155; }
        .topbar-bell-row.is-expiring .bell-icon { background: #ffedd5; color: #c2410c; }
        .topbar-bell-row.is-expired .bell-icon  { background: #fee2e2; color: #b91c1c; }
        .topbar-bell-row.is-return .bell-icon   { background: #e0f2fe; color: #0369a1; }
        .topbar-bell-row.is-missed .bell-icon   { background: #ede9fe; color: #6d28d9; }
        .topbar-bell-row.is-system .bell-icon   { background: #e0e7ff; color: #4338ca; }
        .topbar-bell-row.is-update .bell-icon   { background: #dcfce7; color: #15803d; }

        /* The All tab used to break into "Alerts / System / Updates" headings.
           It no longer does: All is one chronological feed, so there is no
           contiguous run of a single group left for a heading to sit above.
           The icon discs still carry severity, and the tabs carry the kind. */

        /* ── Inventory panels shared by BOTH dashboards ──
           Moved here from admin/dashboard.blade.php. These rules used to live
           only in the admin page's style block, so the staff dashboard could
           not reuse the same markup and grew its own near-copies — exactly the
           drift REMEDI.md warns about under "Fixes applied to the admin
           dashboard do NOT reach staff". One definition, both pages. */
        .demand-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        /* ── Panel meta line ──
           The count and the rule that governs a panel ("15 · supplier-return
           window applies") used to sit inside .demand-head between the title
           and "View All". Three items competing for ~320px meant the longer
           title wrapped and the shorter one did not, so two cards standing side
           by side had headers of 45px and 36px and their bodies started at
           different heights — a step you read as misalignment before you read
           either title.

           On its own line the meta cannot push the title, both headers are one
           line tall, and the two cards line up. It also reads better: a caption
           under a heading rather than a third thing in a row. */
        .panel-note {
            margin: -4px 0 12px;
            font-size: 11.5px;
            color: #94a3b8;
        }

        /* "View All" is a secondary action; as a solid navy pill it outweighed
           the panel title next to it. Now a quiet link that only asserts itself
           on hover. */
        .view-all {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--brand-darker);
            background: transparent;
            border-radius: 6px;
            padding: 3px 7px;
            text-decoration: none;
            transition: background 0.14s ease, color 0.14s ease;
        }

        .view-all::after {
            content: '\203A'; /* single right angle quote — a light "go" cue */
            font-size: 14px;
            line-height: 1;
        }

        .view-all:hover {
            background: var(--brand-tint);
            color: var(--brand-darker);
        }

        .demand-list { list-style: none; margin: 0; padding: 0; }


        .expiry-list { list-style: none; margin: 0; padding: 0; }

        .expiry-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 13.5px;
            color: #334155;
            padding: 9px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .expiry-list li:last-child { border-bottom: none; }

        /* A table row that navigates. The affordance used to ride on an inline
           style beside the inline onclick; both moved out together — see the
           delegated handler in the script below. */
        tr.clickable-row { cursor: pointer; }
        tr.clickable-row:hover > td { background: #f8fafc; }

        /* ── Rows that lead somewhere ──
           A dashboard row naming one product or one category is a question
           ("which batches?", "what else is in Analgesics?") whose answer is a
           page this app already has, so the whole row is the target rather than
           a "View All" at the top of the card being the only way out.

           The anchor takes over the li's flex layout instead of sitting inside
           it: a link that only wraps the name leaves most of the row dead, and
           the badge on the right is the part people aim at. Negative margins
           let the hover tint bleed to the card's padding so it reads as a row
           and not as a button pasted into one.

           Shared by both dashboards — see "The Inventory band is shared" in
           REMEDI.md. */
        .expiry-list li > .expiry-row,
        .staff-expiry-list li > .expiry-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex: 1;
            min-width: 0;
            /* Vertical bleed only. Negative side margins made the anchor 16px
               wider than the row it sits in — every li reported
               scrollWidth 300 against clientWidth 292, and in the scrolled
               lists the tint ran under the scrollbar gutter. Horizontal padding
               inside the row gives the same inset highlight without the
               overflow. */
            margin: -5px 0;
            padding: 5px 8px;
            border-radius: 8px;
            color: inherit;
            text-decoration: none;
            transition: background .12s ease;
        }

        .expiry-list li > .expiry-row:hover,
        .staff-expiry-list li > .expiry-row:hover { background: #f8fafc; }

        /* Same idea for the returns legends, whose counts each correspond to an
           Inventory filter exactly. `.item` is already the flex row, so an
           anchor with that class needs only the link resets. */
        .returns-legend a.item {
            color: inherit;
            text-decoration: none;
            margin: -4px 0;           /* vertical only — see .expiry-row above */
            padding: 4px 8px;
            border-radius: 8px;
            transition: background .12s ease;
        }

        .returns-legend a.item:hover { background: #f8fafc; }

        /* Scroll the list instead of truncating it.
           These panels used to render take(8) / take(5) and simply drop the rest
           on the floor: with 15 medicines expiring, 27 batches due for return and
           50 non-pharma ones, most of what the card counted in its own header was
           unreachable without leaving for the Inventory page. The cap is now on
           height, not on rows. */
        .expiry-scroll {
            max-height: 320px;
            overflow-y: auto;
            overscroll-behavior: contain;
            /* Room for the scrollbar so it never sits on top of the badges. */
            padding-right: 6px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        /* The return panels carry a doughnut above the list, so they get less
           room before the card grows taller than its neighbour. */
        .expiry-scroll.is-compact { max-height: 220px; }

        .expiry-scroll::-webkit-scrollbar { width: 8px; }
        .expiry-scroll::-webkit-scrollbar-track { background: transparent; }
        .expiry-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .expiry-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Expiring Soon now spans the full row width; lay its items out in
           two columns so that space is actually used instead of leaving one
           long, narrow list. */
        .expiry-list-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 24px;
        }

        @media (max-width: 700px) {
            .expiry-list-2col { grid-template-columns: 1fr; }
        }

        /* flex:1, not just min-width:0. The row holds a product name, a batch
           number, an expiry date and a severity badge; the last two are
           no-wrap, so every pixel the row was short came out of this block —
           the product name, the one thing the row exists to tell you, rendered
           in 40px of a 302px row while "Expires: Aug 24, 2026" sat beside it in
           127px. Now this block claims the space and the fixed pieces take what
           they need. */
        .expiry-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 0;
        }

        /* Wraps to two lines rather than truncating. With the date in its own
           column these cards leave the name ~150px, and nowrap+ellipsis cut
           "SALBUTAMOL 2MG/5ML SYR (BUTAMOL) 60ML" mid-word — a product you
           cannot identify is worse than a taller row. */
        .expiry-name {
            font-weight: 600;
            color: #1e293b;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            overflow-wrap: anywhere;
            line-height: 1.3;
        }

        /* .expiry-when (the date as its own column) is gone: it was no-wrap and
           rigid, so it took 127px of a 302px row while the product name beside
           it got 40px. The date now rides on the batch sub-line inside
           .expiry-info — see the note there. */

        .expiry-batch {
            font-size: 11.5px;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* The date stays on the info line with the batch number rather than taking
           a column of its own. Measured: these cards are ~424px wide even on a
           1585px screen (three per row), and a third column squeezed the product
           name to 147px, truncating "EFFICASCENT OINMENT 10g". A labelled second
           line reads the same and keeps the name whole. */

        .expiry-badge {
            margin-left: 10px;
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }

        /* .critical/.warning removed: expiry now uses the shared
           .badge-expiry-* scale and returns use .badge-return-*, so the two
           thresholds never share a colour. */

        .expiry-empty {
            color: #94a3b8;
            font-size: 13.5px;
            padding: 6px 0;
        }

        /* Section separation: Sales vs Inventory */
        .dash-section {
            margin-bottom: 32px;
        }

        /* stretch, not start: the three columns hold different things (two
           scrolling lists and a stack of two summary cards), so their natural
           heights never match — the returns column ran 135px past the lists
           once its rings stacked, and before that the lists ran past it. Rather
           than pinning the lists to whatever the returns column happens to
           measure this month, let the row set one height and give the scroll
           areas the remainder. Self-balancing, and the lists show more rows for
           free. */
        .inv-bottom {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 0.85fr);
            gap: 16px;
            align-items: stretch;
        }

        .inv-bottom > .card { display: flex; flex-direction: column; }

        .inv-returns-col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

        /* These two sit beside the expiring lists rather than under them, so their
           batch lists get less room than a full-width panel would give: the point
           of the column is the doughnut and the counts, with the rows there for
           reference and "View All" for the whole set. */
        /* The two returns panels stack to ~548px, so let the expiring lists run to
           the same height rather than stopping at the shared 320px and leaving a
           ragged bottom edge. More height here is free: both lists have more rows
           than fit either way, so the extra room just shows more of them. */
        /* Fills whatever the stretched card leaves, instead of a fixed cap that
           has to be re-guessed every time a neighbouring panel changes height.
           The floor keeps the lists usable if the returns column is ever short
           (no returns due), and overflow-y:auto on .expiry-scroll still bounds
           them — a flex item with a definite height scrolls normally. */
        /* basis 0, NOT auto. With `auto` the list's own content counts toward
           the card's natural height, so the tallest thing in the row became the
           full 926px list and the band grew to 1041px — the cap this band has
           always had, undone. At basis 0 the scroller asks for nothing beyond
           its floor, the row height is set by the shortest-fitting column, and
           the list takes the remainder. */
        .inv-bottom > .card .expiry-scroll { flex: 1 1 0; min-height: 320px; max-height: none; }

        /* The two Expiring Soon cards stand side by side, and their titles are
           not the same length: "Expiring Soon · Medicine / Pharmaceutical"
           needs 296px and wraps in the ~265px it gets, while "Expiring Soon ·
           Other Categories" does not. Moving the meta line out of the header
           (see .panel-note) bought room but not enough, so the two headers
           still measured 36px and 21px — and the lists under them started 15px
           apart, which reads as one card sagging.

           A floor of two label lines makes both headers the same box whether
           the title wraps or not. Centred inside it, so the short title sits on
           the same optical line as the tall one's first line.

           42px = two lines of the 16px .label at normal line-height, measured
           on the staff dashboard where the medicine title does wrap. A floor of
           36 left that card 6px taller than its neighbour. */
        .inv-bottom > .card .demand-head { min-height: 42px; }

        @media (max-width: 1400px) {
            /* Returns drop under the pair rather than squeezing a doughnut and its
               legend into a third of a laptop screen. */
            .inv-bottom { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
            .inv-returns-col { grid-column: 1 / -1; flex-direction: row; }
            .inv-returns-col > .card { flex: 1 1 0; min-width: 0; }
        }

        @media (max-width: 900px) {
            .inv-bottom { grid-template-columns: minmax(0, 1fr); }
            .inv-returns-col { flex-direction: column; }
        }

        /* Returns panels: doughnut left, legend right -- the same shape as Stock
           Status. Centred chips under the ring needed the full card width, which
           this column no longer has. */
        /* Ring above the legend, not beside it. Side by side asked for a fixed
           150px ring + a 140px legend floor + a 14px gap = 304px inside a
           column that measures 263px, so the card overflowed its own grid track
           by 41px and "Successfully Returned" ran under the card edge. Stacked,
           each legend row gets the full 263px and the card ends up the same
           height it was — the ring simply moved. */
        .returns-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            justify-items: center;
            gap: 12px;
        }

        /* Full width so the rows align left under the centred ring rather than
           forming a centred block of ragged lines. */
        .returns-grid > .returns-legend { width: 100%; }

        @media (max-width: 520px) {
            .returns-grid { grid-template-columns: 1fr; }
        }

        /* Footer line on the returns panels: says how many batches sit behind the
           ring and links to them. Replaces the inline batch list these cards used
           to carry, which no longer fits now they share a column. */
        .returns-more {
            display: block;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
            font-size: 12px;
            font-weight: 600;
            color: var(--brand-darker);
            text-decoration: none;
        }

        .returns-more:hover { text-decoration: underline; }

        .returns-legend { display: flex; flex-direction: column; gap: 9px; font-size: 12.5px; color: #334155; }
        .returns-legend .item { display: flex; align-items: center; gap: 7px; }
        .returns-legend .dot { width: 9px; height: 9px; border-radius: 2px; flex-shrink: 0; }
        .returns-legend .lbl { flex: 1; white-space: nowrap; }

        /* .inv-pair removed with the Stock Status card it paired — nothing in
           either dashboard carries the class any more. */

        /* The expiry date inside a batch line. Medium, not <strong>: it sits
           directly under the product name, which is already bold, and two bold
           lines stacked made every expiry row read as a heading. Still darker
           than the batch number beside it, so the date stays the thing you
           scan for. */
        .expiry-date { font-weight: 500; color: #475569; }

        .bell-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
        .bell-time { font-style: normal; font-size: 11px; color: #94a3b8; margin-top: 3px; }

        .topbar-bell-viewall {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 11px;
            margin-top: 4px;
            border-top: 1px solid var(--line);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--brand-darker);
            text-decoration: none;
        }

        .topbar-bell-viewall:hover { background: var(--brand-tint); }

        .topbar-bell-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            border-left: 3px solid transparent;
        }

        .topbar-bell-row:hover { background: #f8fafc; }

        /* The tab filter sets the hidden ATTRIBUTE, and [hidden]'s display:none
           comes from the UA stylesheet -- which the display:flex above beats on
           every row. Without this rule the rows are marked hidden and painted
           anyway, so every tab looked identical to All. */
        .topbar-bell-row[hidden] { display: none; }

        /* Read vs unread. Unread carries a filled dot and full-strength text;
           a read row keeps its severity stripe (it is still an open alert —
           "read" means you have seen it, not that the stock is fine) but drops
           back in weight so new things stand out in a list you have already
           been through. */
        .topbar-bell-row { position: relative; }

        .topbar-bell-row .unread-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--brand);
            flex-shrink: 0;
            align-self: center;
            margin-left: auto;
        }

        .topbar-bell-row.is-read { opacity: .62; }
        .topbar-bell-row.is-read strong { font-weight: 500; }
        .topbar-bell-row.is-read .unread-dot { display: none; }

        /* Now a header action beside the close button, not a footer strip. */
        .topbar-bell-markread {
            padding: 0;
            background: none;
            border: 0;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            color: var(--brand-darker);
            cursor: pointer;
            white-space: nowrap;
        }

        .topbar-bell-markread:hover { text-decoration: underline; }
        .topbar-bell-markread[hidden] { display: none; }
        .topbar-bell-row i { font-size: 17px; line-height: 1.2; flex-shrink: 0; }
        .topbar-bell-row span { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .topbar-bell-row strong { font-size: 13px; font-weight: 600; color: var(--ink); }
        .topbar-bell-row small { font-size: 11.5px; color: #64748b; }

        /* Same legend hues the dashboards and the Inventory tabs use, so a row
           here is recognisably the same state you land on after clicking it. */
        .topbar-bell-row.is-low      { border-left-color: #eab308; }
        .topbar-bell-row.is-low i    { color: #a16207; }
        /* Out of stock is graphite, and deliberately NOT another shade on the
           amber-to-red warning ramp. Rose was tried and read as a variant of
           the #dc2626 red that expired stock owns, which is the one thing this
           must not look like: expired is a shelf to CLEAR, empty is a shelf to
           REFILL. A neutral dark says "there is nothing here" rather than
           competing for a place in the severity ladder -- it is an absence, not
           a louder warning. Kept high-contrast (slate-700 on slate-200) so it
           reads as deliberate rather than as a disabled row. */
        .topbar-bell-row.is-out      { border-left-color: #334155; }
        .topbar-bell-row.is-out i    { color: #334155; }
        .topbar-bell-row.is-expiring { border-left-color: #f97316; }
        .topbar-bell-row.is-expiring i { color: #c2410c; }
        .topbar-bell-row.is-expired  { border-left-color: #dc2626; }
        .topbar-bell-row.is-expired i { color: #b91c1c; }
        .topbar-bell-row.is-return   { border-left-color: #3b82f6; }
        .topbar-bell-row.is-return i { color: #1d4ed8; }
        /* The action pill. A span, not a button -- see the note in the row
           markup: it lives inside the row's own anchor, which may not contain
           interactive content. Styled as a control because it names where the
           row goes, which for an account change is User Management rather than
           the audit trail. */
        .bell-action {
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
            padding: 3px 9px;
            border: 1px solid #ddd6fe;
            border-radius: 999px;
            background: #f5f3ff;
            color: #6d28d9;
            font-size: 11px;
            font-weight: 600;
        }

        .bell-action i { font-size: 12px; }
        .topbar-bell-row:hover .bell-action { background: #ede9fe; border-color: #c4b5fd; }

        .topbar-bell-row.is-missed   { border-left-color: #9f1239; }
        .topbar-bell-row.is-missed i { color: #9f1239; }

        .topbar-bell-empty {
            padding: 18px 12px;
            text-align: center;
            font-size: 12.5px;
            color: #94a3b8;
        }

        /* The panel lists a few of each kind, so the totals live down here --
           otherwise the bell would imply three low-stock products when there
           are 645. */
        .topbar-bell-foot {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 6px;
            padding: 10px 10px 4px;
            border-top: 1px solid var(--line);
        }

        .topbar-bell-foot a {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--brand-darker);
            text-decoration: none;
            padding: 4px 0;
        }

        .topbar-bell-foot a:hover { text-decoration: underline; }
        .topbar-bell-foot:empty { display: none; }

        @media (max-width: 480px) {
            /* Anchored to the bell, a 330px panel runs off a 375px screen.
               Pin it to the viewport edges instead. */
            .topbar-bell-panel { position: fixed; top: 64px; left: 10px; right: 10px; width: auto; }
        }

        /* ── Startup alert toasts ──
           A passive, self-dismissing summary of what needs attention, shown
           once when the app is first opened. Deliberately NOT REMEDI.showMessage:
           that card is modal and waits to be acknowledged, which is right for
           "you just did something, here is the result" and wrong for a standing
           condition nobody asked about -- a dialog on every sign-in is a door
           you have to close before you can start work.

           This is also not the #ajaxFlash banner that was removed (see the note
           by .remedi-modal__icon). That one announced the outcome of an action
           the user had just taken, where a dismissible strip meant the answer
           could scroll away unread. Nothing here is an outcome and nothing here
           is lost by ignoring it: every row links to the same Inventory filter
           the bell opens, and the bell keeps the same counts permanently. */
        .remedi-toasts {
            position: fixed;
            right: 18px;
            bottom: 18px;
            /* Over the dashboard loader (190) so it stays legible during the
               5-12s body build, under .remedi-modal (200) so a real dialog wins. */
            z-index: 195;
            display: none;              /* JS opts in -- see the gate in the script */
            flex-direction: column;
            gap: 10px;
            width: 340px;
            max-width: calc(100vw - 36px);
            /* The stack is inert; only the rows take the pointer, so the corner
               of the page underneath stays clickable between toasts. */
            pointer-events: none;
        }

        .remedi-toasts.is-open { display: flex; }

        .remedi-toast {
            pointer-events: auto;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 4px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-left: 4px solid #94a3b8;
            border-radius: 12px;
            /* Same lift as .remedi-modal__panel, so it reads as the same family
               of floating object rather than as a browser notification. */
            box-shadow: 0 12px 32px rgba(15, 23, 42, .18);
            animation: toastIn .3s cubic-bezier(.16, 1, .3, 1) both;
        }

        .remedi-toast.is-leaving { animation: toastOut .24s ease forwards; }

        .remedi-toast__link {
            flex: 1;
            display: flex;
            align-items: flex-start;
            gap: 11px;
            min-width: 0;               /* so the body text may wrap, not overflow */
            padding: 12px 4px 12px 13px;
            text-decoration: none;
            color: inherit;
            border-radius: 12px 0 0 12px;
        }

        .remedi-toast__link:hover .remedi-toast__title { text-decoration: underline; }
        .remedi-toast__link:focus-visible { outline: 2px solid var(--brand); outline-offset: -2px; }

        .remedi-toast__icon {
            flex: none;
            display: grid;
            place-items: center;
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: #f1f5f9;
            color: var(--ink-soft);
            font-size: 17px;
        }

        .remedi-toast__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }

        .remedi-toast__title {
            font-family: 'Outfit', sans-serif;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.25;
        }

        .remedi-toast__body { font-size: 12px; color: var(--ink-soft); line-height: 1.4; }

        /* The onset, or the event time for an audit row. Muted and last,
           because it qualifies the message rather than being the message. */
        .remedi-toast__when {
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.4;
            margin-top: 1px;
        }

        .remedi-toast__close {
            flex: none;
            margin: 7px 7px 0 0;
            width: 24px;
            height: 24px;
            display: grid;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: #94a3b8;
            font-size: 15px;
            cursor: pointer;
        }

        .remedi-toast__close:hover { background: #f1f5f9; color: var(--ink); }
        .remedi-toast__close:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }

        /* Same legend as the bell rows above -- a kind must not change colour
           depending on which surface happens to be showing it. */
        .remedi-toast.is-low      { border-left-color: #eab308; }
        .remedi-toast.is-low .remedi-toast__icon      { background: #fef9c3; color: #a16207; }
        .remedi-toast.is-out      { border-left-color: #334155; }
        .remedi-toast.is-out .remedi-toast__icon      { background: #e2e8f0; color: #334155; }
        .remedi-toast.is-expiring { border-left-color: #f97316; }
        .remedi-toast.is-expiring .remedi-toast__icon { background: #ffedd5; color: #c2410c; }
        .remedi-toast.is-expired  { border-left-color: #dc2626; }
        .remedi-toast.is-expired .remedi-toast__icon  { background: #fee2e2; color: #b91c1c; }
        .remedi-toast.is-return   { border-left-color: #3b82f6; }
        .remedi-toast.is-return .remedi-toast__icon   { background: #e0f2fe; color: #1d4ed8; }
        /* Account changes. Deliberately OFF the amber-to-red severity ramp the
           stock kinds use: nothing is wrong with the shelf, someone changed who
           can get in. Violet says "different sort of thing" without claiming a
           rung on a ladder it does not belong on -- the same argument that keeps
           out-of-stock graphite rather than a deeper red. */
        .remedi-toast__action {
            align-self: flex-start;
            margin-top: 6px;
            padding: 3px 9px;
            border: 1px solid #ddd6fe;
            border-radius: 999px;
            background: #f5f3ff;
            color: #6d28d9;
            font-size: 11px;
            font-weight: 600;
        }

        .remedi-toast.is-system   { border-left-color: #7c3aed; }
        .remedi-toast.is-system .remedi-toast__icon   { background: #ede9fe; color: #6d28d9; }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(24px) scale(.97); }
            to   { opacity: 1; transform: none; }
        }

        @keyframes toastOut {
            from { opacity: 1; transform: none; }
            to   { opacity: 0; transform: translateX(24px); }
        }

        @media (max-width: 480px) {
            /* Pin to the viewport edges rather than keep a 340px card on a
               375px screen -- the same move .topbar-bell-panel makes above. */
            .remedi-toasts { right: 10px; left: 10px; bottom: 10px; width: auto; }
        }

        @media (prefers-reduced-motion: reduce) {
            .remedi-toast, .remedi-toast.is-leaving { animation: none; }
        }

        /* ── Centred confirm dialog ──
           Used by Log out. Fixed and flex-centred so the question appears where
           the eye already is, rather than in the browser's top-left chrome the
           way window.confirm() does. */
        .remedi-modal {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .55);
            /* The password dialog is ~430px tall; on a short window (a laptop
               with devtools open, a landscape phone) a centred panel taller
               than the viewport would be clipped with no way to reach the
               submit button. */
            overflow-y: auto;
        }

        .remedi-modal.is-open { display: flex; }

        .remedi-modal__panel {
            width: 100%;
            max-width: 400px;
            background: var(--surface);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 24px 60px -20px rgba(15, 23, 42, .45);
            animation: remedi-modal-in .18s ease-out;
        }

        @keyframes remedi-modal-in {
            from { opacity: 0; transform: translateY(10px) scale(.97); }
            to   { opacity: 1; transform: none; }
        }

        .remedi-modal__icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            background: #fee2e2;
            color: #b91c1c;
        }

        /* Destructive red is the default. Reversible actions — activate a user,
           mark a batch returned — get a neutral disc instead, so the colour
           still means "this cannot be undone" when it is red. */
        .remedi-modal__icon.is-neutral { background: #e0f2fe; color: #0369a1; }

        /* #ajaxFlash and its toast styling are gone: AJAX outcomes now open the
           shared message dialog (see REMEDI.showMessage), so there is no banner
           to place, and nothing scrolls itself into view to be read. */

        .remedi-modal__panel h3 {
            margin: 0 0 6px;
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
        }

        .remedi-modal__panel p { margin: 0 0 20px; font-size: 13.5px; color: var(--ink-soft); }

        .remedi-modal__actions { display: flex; gap: 10px; }
        .remedi-modal__actions .btn { flex: 1; justify-content: center; }

        /* ── Alert detail modal ──
           The named rows behind a count, fetched when the modal opens. Capped
           in height rather than in rows: "645 products" cannot be listed, but
           the ones it does show should be the ones you act on first. */
        .alert-modal-state { font-size: 12.5px; color: #94a3b8; margin: 0 0 12px !important; }
        .alert-modal-state[hidden] { display: none; }

        .alert-modal-list {
            list-style: none;
            margin: 0 0 20px;
            padding: 0;
            max-height: 230px;
            overflow-y: auto;
            overscroll-behavior: contain;
            text-align: left;
            border: 1px solid var(--line);
            border-radius: 10px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        .alert-modal-list[hidden] { display: none; }
        .alert-modal-list::-webkit-scrollbar { width: 8px; }
        .alert-modal-list::-webkit-scrollbar-track { background: transparent; }
        .alert-modal-list::-webkit-scrollbar-thumb { background: #dbe3ec; border-radius: 4px; }

        .alert-modal-list li {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .alert-modal-list li:last-child { border-bottom: none; }
        .alert-modal-list strong { font-size: 13px; font-weight: 600; color: var(--ink); }
        .alert-modal-list small { font-size: 11.5px; color: var(--ink-soft); }

        @media (prefers-reduced-motion: reduce) {
            .remedi-modal__panel { animation: none; }
        }

        /* Avatar + name as one clickable target for My Profile. */
        .topbar-identity {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 4px 8px 4px 4px;
            border-radius: 999px;
            text-decoration: none;
            color: inherit;
            transition: background .15s ease;
        }

        .topbar-identity:hover { background: var(--brand-tint); }
        .topbar-identity:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

        .topbar-role { display: flex; flex-direction: column; line-height: 1.25; }
        .topbar-role small { font-size: 11.5px; color: #94a3b8; }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
        }

        .topbar-user strong { font-size: 15px; font-weight: 500; }

        .content-body {
            padding: 28px 32px;
            flex: 1;
        }

        /* ── Cards ── */
        .card {
            background: #fff;
            border-radius: 10px;
            padding: 22px 24px;
            border: 0.5px solid #e2e8f0;
        }

        /* Only cards that are actually links react to the pointer -- a plain
           .card is a container, and a container that lifts under the cursor
           just makes the page feel loose. */
        a.card {
            display: block;
            color: inherit;
            text-decoration: none;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        a.card:hover {
            border-color: var(--brand-soft);
            box-shadow: 0 8px 20px -12px rgba(15, 23, 42, .45);
            transform: translateY(-2px);
        }

        /* ── KPI row ──
           One consistent band of figures across the top, above the tab switcher
           so it stays visible on both Sales and Inventory. Replaces the old
           single "Sales Today" hero and the slate pill row, which split the
           numbers across two tabs and gave every figure the same weight
           regardless of whether it needed attention.

           Each stock tile links to the matching Inventory filter — the counts
           are the reason you'd go there, so they may as well be the door. */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(178px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .kpi {
            position: relative;
            display: block;
            background: var(--surface);
            border: 0.5px solid #eef2f7;
            border-radius: 14px;
            padding: 16px 18px;
            text-decoration: none;
            color: inherit;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
        }

        /* No severity stripe: the reference design carries the colour in a
           filled circular icon instead, which reads at a glance without
           tinting the whole card edge. */

        a.kpi:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.09);
            border-color: #cbd5e1;
        }

        .kpi-head {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 12px;
        }

        /* Circular icon chip. The accent colour is passed per card as
           --kpi-accent; the disc is that colour at 14% and the glyph is the
           colour itself, so one variable drives both. */
        .kpi-head i {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            color: var(--kpi-accent, #94a3b8);
            background: color-mix(in srgb, var(--kpi-accent, #94a3b8) 14%, #fff);
        }

        /* color-mix is recent; fall back to a flat tint where it is missing so
           the chip never renders as a transparent hole. */
        @supports not (background: color-mix(in srgb, red 10%, white)) {
            .kpi-head i { background: #f1f5f9; }
        }

        .kpi-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #64748b;
        }

        .kpi-value {
            display: block;
            font-family: 'Outfit', sans-serif;
            font-size: 27px;
            font-weight: 700;
            line-height: 1.1;
            color: #1e293b;
            font-variant-numeric: tabular-nums;
            /* A long peso figure (no spaces to wrap on) silently ran past the
               card and was clipped by .kpi's own overflow:hidden -- the
               digits were just gone, with nothing on screen to say so.
               break-word lets it wrap onto a second line instead. */
            overflow-wrap: break-word;
        }

        .kpi-sub {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #94a3b8;
        }

        /* A zero count is good news for the alert tiles — mute it so attention
           goes to the ones that actually need action. */
        .kpi.is-clear { --kpi-accent: #cbd5e1; }
        .kpi.is-clear .kpi-value { color: #94a3b8; }

        /* ── Alerts ── */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 11px;
        }

        .alert i { font-size: 18px; margin-top: 1px; }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 0.5px solid #bbf7d0;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 0.5px solid #fecaca;
        }

        .alert ul { margin: 4px 0 0; padding-left: 16px; }

        /* ── Badges ── */
        .badge {
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            line-height: 1.4;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-admin { background: #dcfce7; color: #166534; }
        .badge-staff { background: #fef9c3; color: #854d0e; }

        /* Generic status colors, reused across the app for any pill/label */
        /* Five status colours, fixed by the legend the design supplies:
             Low Stock      yellow
             Expiring Soon  orange
             Expired        red
             Need to Return blue
             Returned       green
           Stock and expiry share one yellow -> orange -> red severity ramp;
           the supplier-return states sit outside it in blue/green, so "act on
           this return" is never mistaken for "this is about to go off". */
        .badge-success  { background: #dcfce7; color: #166534; }  /* OK / returned           */
        .badge-warning  { background: #ffedd5; color: #9a3412; }  /* expiring soon (orange)  */
        .badge-danger   { background: #fef9c3; color: #854d0e; }  /* low stock (yellow)      */
        .badge-critical { background: #fee2e2; color: #991b1b; }  /* expired (red)           */
        .badge-info     { background: #dbeafe; color: #1e40af; }  /* neutral / actionable      */
        .badge-purple   { background: #ede9fe; color: #5b21b6; }  /* distinct flagged state    */

        /* ── Expiry family ──
           A rose/red ramp, kept deliberately separate from the amber return
           family below. Expiry ("how long until this is unsellable") and the
           supplier-return window ("can we still send it back") are different
           thresholds — a batch is only "expiring soon" once it has ALREADY
           fallen out of the returnable window — so they must not share a
           colour or they read as the same warning. */
        .badge-expiry-expired  { background: #fee2e2; color: #991b1b; }  /* expired  — red    */
        .badge-expiry-critical { background: #ffe4e6; color: #9f1239; }  /* <= 7d    — red-ish */
        .badge-expiry-soon     { background: #ffedd5; color: #9a3412; }  /* <= 30d   — orange */
        .badge-expiry-watch    { background: #fef9c3; color: #854d0e; }  /* <= 90d   — yellow */

        /* The "soon" step is pink rather than orange on purpose: orange sits
           only ~12 degrees from the amber used by .badge-return-due, and at
           these pale tints the two were indistinguishable — which is exactly
           the expiry/return confusion this split exists to prevent. */

        /* ── Supplier-return family ──
           One hue (amber), three shades, so the states read as stages of the
           same process rather than three unrelated statuses. Deeper shade =
           more urgent. */
        .badge-return-due    { background: #dbeafe; color: #1e40af; }  /* need to return — blue  */
        /* Not in the supplied legend. Kept in the red family because it means
           the same thing Expired does — too late to act — but darker, so the
           two are still separable side by side. */
        .badge-return-late   { background: #fecdd3; color: #881337; }  /* fail to return         */
        .badge-return-done   { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* returned — green */
        .badge-teal     { background: #ccfbf1; color: #0f766e; }  /* non-pharma: default-expiry-based return flag */

        /* ── Table ── */
        table.remedi-table { width: 100%; border-collapse: collapse; background: #fff; }

        table.remedi-table th,
        table.remedi-table td {
            padding: 13px 16px;
            border-bottom: 0.5px solid #e5e7eb;
            text-align: left;
            font-size: 15px;
        }

        table.remedi-table th {
            background: #f8fafc;
            font-weight: 500;
            color: #64748b;
            font-size: 13px;
        }

        /* ── Buttons ── */
        /* ── Form pages ───────────────────────────────────────────────
           Add Product, Add User, Edit User. One vocabulary so the three read
           as the same kind of page: a titled header, a card, and each field
           introduced by a tinted icon chip.

           The chip is not decoration for its own sake -- these forms are two
           columns of similar-looking inputs, and the icon is what lets you find
           "Selling Price" without reading every label. */
        .form-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 26px 28px;
            box-shadow: 0 18px 40px -28px rgba(15, 23, 42, .45);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px 26px;
        }

        /* min-width:0 or a long label stretches the grid column instead of
           wrapping, and the two columns stop being equal. */
        .form-field { min-width: 0; }
        .form-field.is-wide { grid-column: 1 / -1; }

        /* Three-up, for the Add New Batch card. Steps down to two columns
           before the shared 760px rule takes it to one. */
        .form-grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }

        @media (max-width: 1100px) {
            .form-grid.cols-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        .form-field-head {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 9px;
        }

        /* NEUTRAL, deliberately. These chips were tinted six different ways
           (green name, purple SKU, blue category, amber money, red alerts),
           which made a form of six ordinary fields look like six different
           kinds of thing -- the colour was decoration carrying no rule. The
           icon now sits in the same tone as the label beside it, the way the
           sidebar's icons belong to their labels, and it is still what lets
           you find "Selling Price" without reading every label.

           Colour is kept where it MEANS something and nowhere else: the
           reports, the inventory status badges, and the alert legend the bell,
           the toasts and the notifications page share. If you want a chip to
           stand out here, that is a reason to ask what rule it is expressing. */
        .form-chip {
            flex: none;
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            font-size: 18px;
            background: #f1f5f9;
            color: var(--ink-soft);
        }

        /* The blocked cursor, not an icon: hovering a field the app genuinely
           will not let you change -- the batch number (auto-assigned), your
           own Role on Edit User (self-demotion is refused server-side) --
           shows the browser's own "not-allowed" pointer, the same signal a
           disabled button already gives for free. `input[readonly]` needs it
           stated explicitly; unlike `:disabled`, a readonly field keeps the
           ordinary text cursor by default even though typing does nothing. */
        input[readonly],
        select:disabled,
        input:disabled {
            cursor: not-allowed;
        }

        .form-field-head label {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--ink);
            margin: 0;
        }

        .form-field input[type="text"],
        .form-field input[type="email"],
        .form-field input[type="number"],
        .form-field input[type="password"],
        .form-field input[type="date"],
        .form-field select {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: var(--ink);
            background: #fff;
        }

        .form-field input:focus,
        .form-field select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-soft);
        }

        /* An empty date field standing in as a text input (see the
           date-placeholder script) must not look different from a real one --
           same box, same metrics, so nothing shifts when it flips on focus. */
        input.date-placeholder::placeholder {
            color: var(--ink-soft);
            opacity: 1;
        }

        .form-field-note {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 7px;
            font-size: 12px;
            color: var(--ink-soft);
        }

        /* A rule above the actions, so the buttons read as the end of the form
           rather than one more row of it. */
        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 26px;
            padding-top: 22px;
            border-top: 1px solid var(--line);
        }

        /* -- Manage Categories -----------------------------------------
           The "add" panel: a circled + beside the heading, so the card reads as
           an action rather than another table. */
        .cat-add {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .cat-add-mark {
            flex: none;
            width: 62px;
            height: 62px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--brand-tint);
            border: 1px solid var(--brand-soft);
            color: var(--brand-dark);
            font-size: 26px;
        }

        .cat-add-body { flex: 1 1 320px; min-width: 0; }
        .cat-add-body h4 { margin: 0 0 12px; font-size: 17px; }

        .cat-add-row { display: flex; gap: 10px; flex-wrap: wrap; }

        .cat-add-row input {
            flex: 1 1 240px;
            min-width: 0;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
        }

        .cat-add-row input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-soft);
        }

        /* Each category keeps its own glyph -- see Category::ICONS. Ten rows of
           identical text is a list you have to read; ten rows with a mark each
           is a list you can scan. */
        .cat-name { display: flex; align-items: center; gap: 12px; }

        .cat-icon {
            flex: none;
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--brand-tint);
            color: var(--brand-dark);
            font-size: 18px;
        }

        /* The name stays editable -- renaming a category is a real operation
           with a real guard behind it (CategoryController::update refuses the
           rule-driving ones). It is drawn as plain text until you focus or
           change it, so the table reads as a list rather than a form. */
        .cat-rename {
            flex: 1;
            min-width: 0;
            padding: 6px 9px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            font: inherit;
            font-weight: 500;
            color: var(--ink);
        }

        .cat-rename:hover { border-color: var(--line); }

        .cat-rename:focus {
            outline: none;
            background: #fff;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-soft);
        }

        .cat-count { font-weight: 600; color: var(--brand-darker); }

        .cat-actions { display: flex; gap: 8px; justify-content: flex-end; }

        /* Save appears only once the name has actually been edited: a Save
           button on every row invites clicks that change nothing. */
        .cat-save[hidden] { display: none; }

        /* A card's own header: tinted chip, then a green title. Used by the
           Edit Product sections, so each card announces what it is instead of
           relying on a bare <h4> to separate three stacked panels. */
        /* A card header with no icon. The chip that used to sit beside the
           title is gone, so the title has to carry the section on its own --
           it is set as a highlighted band rather than a bare line of text: a
           soft brand wash, an accent edge and small caps, which reads as the
           top of a card at a glance the way the icon did. Full width, so the
           band itself marks where one card's content begins. */
        .section-head {
            display: block;
            /* Negative margins cancel .form-card's own padding (26px 28px), so
               the band runs to the card's edges instead of floating inside it
               with a white margin on three sides. This is why a .section-head
               only ever belongs at the TOP of a card -- anywhere else the pull
               would drag it over the content above it. */
            margin: -26px -28px 22px;
        }

        .section-head h4 {
            margin: 0;
            padding: 14px 28px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--brand-darker);
            background: var(--brand-soft);
            /* 17px, not 18px: the card's radius measured from INSIDE its 1px
               border, or the fill shows a hairline of white in each corner. */
            border-radius: 17px 17px 0 0;
            border-bottom: 1px solid var(--line);
        }

        /* Fields across one row, labels above. Deliberately WITHOUT the
           per-field chips the Add pages use: the card header already carries a
           chip, and repeating them on six fields in a single row turns a form
           into a wall of icons. auto-fit means it reflows to fewer columns on
           a narrow screen with no media query. */
        .field-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            align-items: end;
        }

        .field-row label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--ink-soft);
            margin-bottom: 7px;
        }

        .field-row input,
        .field-row select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: var(--ink);
            background: #fff;
        }

        .field-row input:focus,
        .field-row select:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-soft);
        }

        /* Password fields carry a reveal toggle. Typing a password you cannot
           see into a CONFIRM field is where mismatches come from, and this form
           is filled in by an admin creating someone else's account -- there is
           no browser-saved value to fall back on. */
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 42px; }

        .pw-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #94a3b8;
            font-size: 16px;
            cursor: pointer;
        }

        .pw-toggle:hover { background: #f1f5f9; color: var(--ink); }
        .pw-toggle:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }

        /* Strength meter under a NEW-password field -- see initPasswordStrengthMeters()
           below. Same colour language as the inventory/expiry badges (critical red,
           warning orange, success green) so "weak" reads the same way "expired"
           does everywhere else. Hidden until the field actually has a value: an
           empty field isn't weak, it's just empty. */
        .pw-strength { margin-top: 8px; display: flex; align-items: center; gap: 8px; }
        .pw-strength-bar { flex: 1; height: 5px; border-radius: 3px; background: #e2e8f0; overflow: hidden; }
        .pw-strength-bar span { display: block; height: 100%; width: 0; border-radius: 3px; transition: width .2s ease, background .2s ease; }
        .pw-strength-label { font-size: 12.5px; font-weight: 600; white-space: nowrap; }

        .pw-strength.is-weak .pw-strength-bar span { width: 33%; background: #dc2626; }
        .pw-strength.is-weak .pw-strength-label { color: #991b1b; }
        .pw-strength.is-normal .pw-strength-bar span { width: 66%; background: #ea580c; }
        .pw-strength.is-normal .pw-strength-label { color: #9a3412; }
        .pw-strength.is-strong .pw-strength-bar span { width: 100%; background: #16a34a; }
        .pw-strength.is-strong .pw-strength-label { color: #166534; }

        /* Chip tints. Keyed to meaning where one exists -- money amber, alerts
           red -- so a field's colour is the same wherever it appears. */

        /* The form pages' action size, also used by the page-level buttons on
           the list pages so "Add Product" looks the same as the "Save Product"
           it leads to. Row buttons inside tables deliberately stay compact --
           at this size they would break the table's rhythm. */
        .btn-lg {
            padding: 11px 20px;
            font-size: 14.5px;
            border-radius: 11px;
            gap: 9px;
        }

        @media (max-width: 760px) {
            .form-grid { grid-template-columns: minmax(0, 1fr); }
            .form-card { padding: 20px 18px; }

            /* Track the card's narrower padding, or the band stops short of
               the edges on a phone. */
            .section-head { margin: -20px -18px 18px; }
            .section-head h4 { padding: 12px 18px; }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 8px;
            border: 1px solid transparent;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            font-family: inherit;
            line-height: 1.25;
            text-decoration: none;
            white-space: nowrap;
            /* Every button animates the same way; the "pop" below just
               changes how far it travels. */
            transition: background .15s ease, border-color .15s ease,
                        color .15s ease, transform .13s ease, box-shadow .15s ease;
        }

        .btn i { font-size: 17px; }

        .btn:disabled,
        .btn[disabled] {
            opacity: .55;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Action colours carry meaning at a glance in a table of rows:
           blue = go look at / change it, amber = reversible restriction,
           red = destructive, green = positive/confirm. */
        /* Solid mid-tones, not gradients and not the lightest shade of each
           hue. A gradient made every button look like a call to action; the
           base --brand (#10b981) on its own read too light against white for a
           button that is pressed all day. These sit one step down: saturated
           enough to be obviously clickable, dark enough to hold white text at
           small sizes, without going near-black.

           Declared AFTER .btn on purpose: .btn sets `background` and a variant
           above it would be overridden. Same trap .btn-danger-outline hit. */
        .btn-primary   { background: #059669; color: #fff; }
        .btn-info      { background: #3b82f6; color: #fff; }
        .btn-warning   { background: #e08c07; color: #fff; }
        .btn-danger    { background: #dc2626; color: #fff; }
        .btn-success   { background: #16a34a; color: #fff; }

        /* Secondary is now an outlined button rather than a solid grey slab.
           Grey-on-grey read as heavy as the primary action next to it, so
           "Cancel" competed with "Save". */
        .btn-secondary {
            background: #fff;
            color: #374151;
            border-color: #d1d5db;
        }

        /* Outlined rather than a solid red slab. Ten of those down a column
           reads as ten warnings; the destructive weight belongs in the confirm
           dialog, which every one of these already opens. */
        .btn-danger-outline {
            background: #fff;
            color: #b91c1c;
            border-color: #fecaca;
        }

        .btn-danger-outline:hover { background: #fef2f2; border-color: #fca5a5; }

        /* Hover goes one step deeper, still solid. */
        .btn-primary:hover   { background: #047857; }
        .btn-info:hover      { background: #2563eb; }
        .btn-warning:hover   { background: #b45309; }
        .btn-danger:hover    { background: #b91c1c; }
        .btn-success:hover   { background: #15803d; }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }

        /* ── The "pop" ──
           Action buttons lift and cast a tinted shadow on hover so it's
           obvious the cursor is on a live control. Colour-matched to each
           variant so the glow looks like the button, not a generic drop
           shadow. Suppressed under prefers-reduced-motion below. */
        .btn-primary:hover,
        .btn-danger:hover,
        .btn-success:hover,
        .btn-info:hover,
        .btn-warning:hover,
        .btn-secondary:hover {
            transform: translateY(-2px);
        }

        .btn-primary:hover   { box-shadow: 0 6px 16px -4px rgba(16, 185, 129, .55); }
        .btn-danger:hover    { box-shadow: 0 6px 16px -4px rgba(220, 38, 38, .5); }
        .btn-success:hover   { box-shadow: 0 6px 16px -4px rgba(22, 163, 74, .5); }
        .btn-info:hover      { box-shadow: 0 6px 16px -4px rgba(14, 165, 233, .5); }
        .btn-warning:hover   { box-shadow: 0 6px 16px -4px rgba(245, 158, 11, .5); }
        .btn-secondary:hover { box-shadow: 0 5px 14px -5px rgba(15, 23, 42, .35); }

        /* Pressing returns it to the surface, so the click feels physical. */
        .btn:active { transform: translateY(0); box-shadow: none; }

        /* Compact variant for toolbars/filter rows. */
        .btn-sm {
            padding: 7px 14px;
            font-size: 13.5px;
            border-radius: 7px;
        }

        .btn-sm i { font-size: 15px; }

        /* ── Report filter bar ──
           Report pages each hand-rolled their own inline-styled inputs and a
           one-off #185FA5 button. Shared classes here so all three look the
           same as each other and as the rest of the app. */
        .report-filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 1.5rem;
        }

        .report-field { display: flex; flex-direction: column; gap: 5px; }

        .report-field label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .report-select {
            height: 36px;
            font-family: inherit;
            font-size: 13.5px;
            padding: 0 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #1e293b;
            outline: none;
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .report-select:hover { border-color: #94a3b8; }

        .report-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, .18);
        }

        /* ── Page header: back button BESIDE the title ──
           Back sits on the same line as the heading it belongs to, on every
           page that has one. It previously occupied its own row above the
           title; the report pages already did it this way, so the app showed
           two different placements depending on which page you landed on.
           This is now the single pattern — put a .page-head on the page and
           the back button goes inside it, not above it.

           align-items:flex-start so the button stays level with the title line
           rather than floating to the middle of a two-line title + subtitle.
           The button's own line-height is shorter than the block beside it, so
           a small top nudge is what actually lines the two up optically. */
        .page-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .page-head > .btn-back { margin-top: 2px; }

        .page-head-text { min-width: 0; }

        .page-head-text h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--ink);
        }

        .page-head-text p {
            margin: 3px 0 0;
            font-size: 13px;
            color: var(--ink-soft);
        }

        /* When the title IS a record -- a product name, a person -- rather than
           a page label. "Manage Categories" is signage and 1.15rem is right for
           it; "CENVERT-16" is the thing you opened and needs to read as the
           subject of the page, not as a breadcrumb.

           A modifier rather than a change to .page-head-text h3, which ten
           pages share and most of which are signage. */
        .page-head-text.is-record h3 {
            font-size: 1.65rem;
            font-weight: 700;
            letter-spacing: -.015em;
            line-height: 1.2;
        }

        .page-head-text.is-record p {
            margin-top: 5px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--ink-soft);
        }

        @media (max-width: 620px) {
            .page-head-text.is-record h3 { font-size: 1.35rem; }
        }

        /* Anything pushed to the right of the header (filters, actions).
           flex-wrap: the only current user (reports/analytics.blade.php)
           went from 2 items to 4 when the Excel/PDF export links were
           added, and without wrap the last one ran off the right edge on
           mobile with no way to reach it -- same overflow class already
           fixed on the sales/inventory report button rows. Only one
           consumer of this shared class today, so safe to change here. */
        .page-head-actions { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

        /* Retained only so a page that still renders a bare .page-back row is
           not left flush against what follows it. New pages use .page-head. */
        .page-back { margin-bottom: 18px; }

        /* ── Back button ──
           One appearance everywhere. Pages previously mixed a wide grey
           "90 Back" slab with a 34px icon-only square, so the same action
           looked like two different controls depending on the page. */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px 8px 11px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s ease, border-color .15s ease,
                        color .15s ease, transform .13s ease, box-shadow .15s ease;
        }

        .btn-back i { font-size: 17px; }

        .btn-back:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 5px 14px -5px rgba(15, 23, 42, .35);
        }

        .btn-back:active { transform: translateY(0); box-shadow: none; }

        @media (prefers-reduced-motion: reduce) {
            .btn, .btn-back { transition: background .15s ease, color .15s ease; }
            .btn:hover, .btn-back:hover, a.card:hover { transform: none; }
        }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            list-style: none;
            padding: 0;
        }

        .pagination li a,
        .pagination li span {
            padding: 8px 14px;
            border: 0.5px solid #d1d5db;
            border-radius: 7px;
            color: #374151;
            text-decoration: none;
            font-size: 15px;
            display: block;
        }

        .pagination li a {
            transition: background .14s ease, border-color .14s ease,
                        color .14s ease, transform .14s ease, box-shadow .14s ease;
        }

        .pagination li.active span {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }

        /* Page numbers are a primary control on the POS grid -- the cashier
           pages through the catalogue with them. The old hover was a #f1f5f9
           background and nothing else: at 0.5px of grey border it was near
           invisible, so the numbers read as static text. Same indigo language
           as the product tiles they sit under. */
        .pagination li a:hover {
            background: var(--brand-tint);
            border-color: var(--brand);
            color: var(--brand-darker);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px -4px rgba(16, 185, 129, .5);
        }

        .pagination li a:active { transform: translateY(0); box-shadow: none; }

        /* The current page and the disabled arrows are not targets -- they are
           <span>, not <a>, so they never match the rule above. Spelled out so
           the cursor agrees. */
        .pagination li.active span { cursor: default; }
        .pagination li.disabled span { color: #cbd5e1; cursor: not-allowed; }

        @media (prefers-reduced-motion: reduce) {
            .pagination li a:hover { transform: none; }
        }

        /* ── Form inputs ── */
        input, select, textarea {
            font-family: inherit;
            font-size: 15px;
            padding: 9px 12px;
            border: 0.5px solid #d1d5db;
            border-radius: 7px;
            color: #1e293b;
            background: #fff;
            outline: none;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
        }

        /* ── Print: collapse the app shell so printed content flows
               naturally across as many pages as it needs ── */
        /* ── In-place list skeleton ──
           The page skeleton covers full navigations. List pages instead swap
           their rows over AJAX, which previously left the OLD results on
           screen until the new ones arrived — so a search looked like it had
           done nothing. This stands in for the rows while a fetch is in
           flight. */
        .list-skeleton { padding: 4px 0; }

        .list-skeleton .sk-line {
            height: 46px;
            margin-bottom: 8px;
            border-radius: 8px;
        }

        .list-skeleton.is-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .list-skeleton.is-grid .sk-line { height: 108px; margin: 0; }

        @media (max-width: 767px) {
            .list-skeleton.is-grid { grid-template-columns: 1fr 1fr; }
        }

        /* Search fields stretch to fill their row instead of sitting at the
           browser's default ~170px input width. */
        .suggest-wrap { flex: 1 1 320px; min-width: 0; }
        .suggest-wrap > input { width: 100%; }

        #search-input, input[name="search"] { min-width: 240px; }

        /* The typeahead dropdown was removed; search fields simply stretch to
           fill their row and drive the page's own table/grid. */
        .suggest-wrap { flex: 1 1 320px; min-width: 0; }
        .suggest-wrap > input { width: 100%; }

        #search-input, input[name="search"] { min-width: 240px; }


        /* Charts need an explicit height box: Chart.js with responsive:true
           sizes a doughnut/pie to its container's WIDTH, so an unconstrained
           one in a half-width card renders hundreds of pixels tall. Pair this
           with maintainAspectRatio:false on the chart. */
        .chart-box {
            position: relative;
            width: 100%;
            height: 260px;
        }

        /* Chart.js writes an explicit pixel width onto the canvas when it
           renders. If the container is later narrowed — a column re-proportioned,
           a tab shown after the chart was built while hidden — that inline width
           outlives the change until the next resize observation, and the canvas
           sticks out of its card (measured: a 447px canvas in a 430px box). The
           clamp costs nothing when the sizes agree and prevents the overhang
           when they briefly do not. */
        .chart-box > canvas { max-width: 100%; }

        /* A right-hand legend eats horizontal room, so the ring needs a
           taller box to stay a readable size. Below tablet the legend has
           nowhere to go, so Chart.js is told to put it back underneath. */
        .chart-box-legend-right { height: 280px; }

        @media (max-width: 767px) {
            .chart-box-legend-right { height: 320px; }
        }

        /* Wide content (tables especially) scrolls inside its own box rather
           than forcing the whole page sideways on a narrow screen. */
        /* ── Row table columns ──
           Status sits right after the product name so stock health is legible
           without scrolling, and Actions is pinned to the right edge so the
           buttons stay reachable while the middle of a wide table scrolls. */
        .remedi-table th.col-status,
        .remedi-table td.col-status { white-space: normal; min-width: 176px; width: 176px; vertical-align: middle; }

        /* SKUs are 13-digit barcodes read off a box and compared by eye, so
           they get tabular figures and a monospace-ish tracking: in the
           proportional body face the digits do not line up column to column,
           which is exactly when a transposed pair slips past. */
        .remedi-table td.col-sku {
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            color: var(--ink-soft);
            white-space: nowrap;
        }

        /* Several badges can stack in one Status cell (Low Stock + Expiring +
           Need to Return). The flex layout goes on an INNER wrapper, never on
           the <td> itself: `display:flex` on a table cell takes it out of the
           table's column model, so the row stops aligning with its header and
           the table visibly breaks. */
        .remedi-table td.col-status .status-stack {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
        }

        .remedi-table td.col-status .badge {
            margin: 0;
            line-height: 1.35;
            white-space: nowrap;
        }

        /* Status and Actions sit next to each other at the end of the row, so
           "what's wrong" and "what to do about it" read as one unit. */
        .remedi-table td.col-actions .actions-cell,
        .remedi-table td.col-actions form {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            margin: 0;
        }

        .remedi-table td.col-actions { white-space: nowrap; vertical-align: middle; }

        /* Fallback for tables that don't declare their own .actions-cell. */
        .remedi-table .actions-cell {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            white-space: nowrap;
        }

        .remedi-table .actions-cell form { margin: 0; display: inline-flex; }

        /* Row actions are SOFT: tinted fill, coloured border, coloured text --
           not the solid mid-tones the rest of the app uses.
           
           That standing rule (see REMEDI.md, "Buttons are solid mid-tones") is
           about the button you press to COMMIT something, where a solid fill is
           what says "this is the action". A table row is the other case: it
           carries two or three of them on every line, and at ten rows a page
           that is thirty saturated fills stacked into a column, which competes
           with the status badges beside it -- the thing the row is actually
           there to tell you.

           The colour still MEANS the same thing (blue = go somewhere, amber =
           reversible restriction, green = confirm, red = destructive); it moves
           into the border and the label instead of the fill, so the legend
           survives and the noise does not. Applies to every table that uses
           .actions-cell -- users, products and inventory -- so the four read as
           one pattern rather than one redesigned page beside three old ones. */
        .remedi-table .actions-cell .btn-info    { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
        .remedi-table .actions-cell .btn-warning { background: #fffbeb; border-color: #fde68a; color: #b45309; }
        .remedi-table .actions-cell .btn-success { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
        .remedi-table .actions-cell .btn-danger  { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

        .remedi-table .actions-cell .btn-info:hover    { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
        .remedi-table .actions-cell .btn-warning:hover { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
        .remedi-table .actions-cell .btn-success:hover { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
        .remedi-table .actions-cell .btn-danger:hover  { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }

        /* The lift-and-glow the solid buttons carry is wrong on these: a hover
           shadow under a pale control reads as the control coming loose. */
        .remedi-table .actions-cell .btn:hover { transform: none; box-shadow: none; }

        .remedi-table th.col-actions,
        .remedi-table td.col-actions {
            position: sticky;
            right: 0;
            z-index: 1;
            background: #fff;
            box-shadow: -6px 0 8px -8px rgba(15, 23, 42, .5);
        }

        .remedi-table th.col-actions { background: #f8fafc; }

        /* Row hover.
           This is what .remedi-table tbody tr:hover td.col-actions below was
           always written against -- without it, hovering a row tinted only the
           pinned Actions column and read as a rendering glitch rather than a
           hover state. Applies to every list in the app, since they all use
           .remedi-table.

           Painted on the CELLS, not the <tr>: a background set on a row in this
           border-collapse table does not render (verified -- a td takes the
           colour, the tr ignores it even with !important), so the obvious
           row-level rule looks correct and shows nothing.

           Background only, no transform: a row that lifts drags the whole
           table's baseline with it. */
        .remedi-table tbody td { transition: background-color .12s ease; }
        .remedi-table tbody tr:hover > td { background: #f8fafc; }

        /* Zebra/hover fills must repaint under the pinned cell too, or it
           shows the page background through it. */
        .remedi-table tbody tr:hover td.col-actions { background: #f8fafc; }

        /* A long list kept inside a fixed-height scroller, so the card stays
           a sensible size while every row remains reachable. */
        .list-scroll { max-height: 420px; overflow-y: auto; }

        .list-scroll .sticky-head th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f9fafb;
            /* A sticky th loses its own border when it detaches, so draw the
               separator with a shadow that travels with it. */
            box-shadow: inset 0 -1px 0 #e5e7eb;
        }

        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
        }

        /* Report cards wrap tables in a rounded box with overflow:hidden,
           which would clip a wide table rather than scroll it. The scroller
           has to sit inside that box and do the scrolling itself. */
        /* Only the app's own row tables need a minimum; report tables set
           their own widths and would otherwise gain a needless scrollbar. */
        .table-scroll > table.remedi-table { min-width: 560px; }

        /* ══════════════ TABLET ══════════════ */
        @media (max-width: 1024px) {
            .content-body { padding: 22px 20px; }
            /* Belt and braces for the whole app: no card may push the page
               wider than the phone it is on. Anything that genuinely needs
               more room (tables) carries its own .table-scroll. */
            .content-body .card { max-width: 100%; }
            .topbar { padding: 16px 20px; }

            /* Reclaim horizontal room: the sidebar narrows and drops its
               labels to icons only. */
            .sidebar, .sidebar > * { width: 216px; min-width: 216px; }
            .sidebar a, .nav-link-btn { font-size: 14px; padding: 10px 12px; }
            .sidebar .brand { padding: 22px 14px 14px; }

            .kpi-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
            .kpi-value { font-size: 22px; }
            .chart-box { height: 240px; }
            /* .chart-box-legend-right carries .chart-box too, and this rule's
               later source position wins the (equal-specificity) cascade over
               the unconditional 280px above -- shrinking the box back down to
               240px right in this 768-1024px range, the one width where the
               legend still sits at 'right' (JS drops it to 'bottom' below
               768px) but wasn't given the taller box that position needs.
               Restate the taller height so it wins here too. */
            .chart-box-legend-right { height: 280px; }
        }

        /* ══════════════ MOBILE ══════════════ */
        @media (max-width: 767px) {
            body { font-size: 15px; }

            /* Sidebar becomes an off-canvas drawer, toggled by the existing
               hamburger. It starts hidden so the content owns the screen. */
            .layout-wrapper { display: block; }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                z-index: 60;
                width: 264px;
                min-width: 264px;
                transform: translateX(0);
                transition: transform .24s ease;
                box-shadow: 0 0 40px rgba(15, 23, 42, .35);
            }

            .sidebar, .sidebar > * { width: 264px; min-width: 264px; }

            /* On mobile the toggle means "slide away", not "collapse to 0" —
               collapsing width would reflow the drawer's contents. */
            html[data-sidebar-hidden="true"] .sidebar {
                transform: translateX(-100%);
                width: 264px;
                min-width: 264px;
                opacity: 1;
                pointer-events: none;
            }

            .sidebar-scrim {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, .5);
                z-index: 55;
                opacity: 1;
                transition: opacity .24s ease;
            }

            html[data-sidebar-hidden="true"] .sidebar-scrim {
                opacity: 0;
                pointer-events: none;
            }

            .main-content { min-height: 100vh; }

            .topbar { padding: 12px 14px; gap: 10px; }
            .topbar h2 { font-size: 1.05rem; }
            /* Name and role badge are the first things to go — the avatar
               still identifies who's signed in. */
            .topbar-user strong, .topbar-user .badge { display: none; }

            .content-body { padding: 16px 14px; }

            /* Single column for every dashboard/report grid. */
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .kpi { padding: 12px 12px 12px 15px; }
            .kpi-value { font-size: 20px; }
            .kpi-sub { font-size: 11px; }

            .card { padding: 16px 14px; border-radius: 12px; }

            .chart-box { height: 220px; }

            /* Tables: let them scroll sideways inside the card instead of
               stretching the page. */
            table.remedi-table { min-width: 620px; }
            table.remedi-table th, table.remedi-table td { padding: 10px 12px; font-size: 14px; }

            .report-filters { gap: 10px; }
            .report-field { flex: 1 1 100%; }
            .report-select { width: 100%; }

            .btn { padding: 10px 16px; }
            /* Full-width primary actions are easier to hit with a thumb. */
            .report-filters .btn { flex: 1 1 auto; }

            .page-skeleton .sk-row { flex-direction: column; }

            /* This codebase builds filter rows with inline `display:flex` and
               no wrap, so on a phone the input + select + button ran past the
               edge with nothing to scroll them. Force wrapping and let the
               fields take the full width. Inline styles need !important. */
            .content-body form { flex-wrap: wrap !important; }

            .content-body form input[type="text"],
            .content-body form input[type="date"],
            .content-body form input[type="search"],
            .content-body form select {
                flex: 1 1 100%;
                min-width: 0;
                max-width: 100%;
            }

            .content-body .suggest-wrap { flex: 1 1 100%; min-width: 0; }

            /* Printed-report blocks are laid out for A4 with fixed columns;
               collapse them on screen so they don't hang off a phone. */
            #print-area > div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
            #print-area > div[style*="display:flex"] { flex-wrap: wrap !important; gap: 10px; }
            #print-area [style*="text-align:right"] { text-align: left !important; }

            /* Restore both for the actual printout. */
            /* Browsers drop background fills when printing unless told not to;
           the report summary tints are meaningful, so keep them. */
        @media print {
            /* A scroll box would clip a long table on paper — let it flow. */
            .table-scroll { overflow: visible !important; }

            .report-summary-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media print {
                #print-area > div[style*="grid-template-columns"] { grid-template-columns: repeat(3, 1fr) !important; }
                #print-area [style*="text-align:right"] { text-align: right !important; }
            }
        }

        /* Two up, not one. A single column stacked six tiles before any actual
           content and made the admin dashboard 4,073px tall on a 375px screen.
           Measured at 375px: tiles come out 169px wide, and nothing inside
           overflows -- checked with the longest value the app can print
           (₱1,229,088.65) forced into every tile. Page height 4,073 -> 3,675px. */
        @media (max-width: 420px) {
            .kpi-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        }

        /* Touch targets. "View All" was 64x21 -- fine for a mouse, under half
           the 44px a finger needs. Height only: these sit inside flex panel
           headers, so padding them out sideways would push the title around. */
        @media (max-width: 767px) {
            .view-all,
            .returns-more {
                display: inline-flex;
                align-items: center;
                min-height: 44px;
            }

            /* The dashboard's two section tabs came out at 41px -- close, but
               these are the primary control on the page. */
            .dash-tab { min-height: 44px; }
        }

        /* 320px — the narrowest phone still in use (SE-era). The 20px page
           padding plus 24px card padding leaves under 250px of usable width,
           which is where fixed-width chart boxes start pushing the page wider
           than the screen. Tighten both and let charts fill what is left. */
        @media (max-width: 360px) {
            .content-body { padding: 18px 12px; }
            .card { padding: 16px 14px; }
            .chart-box { width: 100% !important; max-width: 100% !important; }
        }

        @media print {
            .sidebar, .topbar { display: none !important; }
            .layout-wrapper, .main-content, .content-body {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: 0 !important;
                height: auto !important;
            }
        }
    </style>
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

            @if(auth()->user()->isAdmin())
                <hr>
                <div class="nav-section">Admin</div>
                <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">
                    <i class="ti ti-pill" aria-hidden="true"></i> Products
                </a>
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="ti ti-chart-bar" aria-hidden="true"></i> Reports
                </a>
                <a href="{{ route('forecast.index') }}" class="{{ request()->routeIs('forecast.*') ? 'active' : '' }}">
                    <i class="ti ti-trending-up" aria-hidden="true"></i> Demand Forecasting
                </a>
                <a href="{{ route('sales-forecast.index') }}" class="{{ request()->routeIs('sales-forecast.*') ? 'active' : '' }}">
                    <i class="ti ti-chart-line" aria-hidden="true"></i> Sales Forecasting
                </a>
                {{-- Between Sales Forecasting and the audit trail: the two
                     administrative tabs sit together at the foot of the Admin
                     group, after the four that are about stock and trade. --}}
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="ti ti-users" aria-hidden="true"></i> User management
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
        $toastKinds = ['low_stock', 'expiring', 'expired', 'need_to_return', \App\Services\AlertService::ACCOUNT_KIND];

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

<script>
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
        const KEY = 'remedi_alerts_read:' + @json(auth()->id() ?? 0);
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

        const POLL_MS = 30000;          // matches AlertService::TTL_SECONDS
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

            fetch(@json(route('alerts.index')), {
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
        window.remediRefreshAlerts = refresh;

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
        const confirmBtn = document.getElementById('confirmModalConfirm');
        const cancelBtn = document.getElementById('confirmModalCancel');
        let unlockScroll = null;

        let form = null;
        let lastFocus = null;
        let submitting = false;
        let defaultLabel = 'Confirm';

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

            modal.classList.add('is-open');
            unlockScroll = REMEDI.lockScroll();
            confirmBtn.focus({ preventScroll: true });
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
                (document.activeElement === confirmBtn ? cancelBtn : confirmBtn).focus({ preventScroll: true });
            }
        });

        confirmBtn.addEventListener('click', function () {
            if (!form || submitting) return;
            const target = form;
            const mode = target.dataset.onSuccess || 'reload';

            submitting = true;
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            confirmBtn.textContent = 'Working…';

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
        });

        /* Activate/deactivate flips one badge and one button label, so the row
           is patched in place — reloading the whole list to change one word is
           what made this feel heavy. */
        function applyToggle(target, state) {
            if (!state) { REMEDI.reloadKeepingPlace(); return; }

            const row = target.closest('tr');
            const btn = target.querySelector('button');

            if (btn) {
                btn.textContent = state.action;
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
                    window.location.href = res.url || @json(route('login'));
                })
                .catch(function () {
                    // Offline or blocked: fall back to a normal form post rather
                    // than stranding the user on a disabled dialog.
                    form.submit();
                });
        });
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

</script>
</body>
</html>