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

        .brand-text { display: flex; flex-direction: column; line-height: 1; }

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
        .sk-tabs { display: flex; gap: 10px; margin-bottom: 26px; }
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
            width: 330px;
            max-height: 420px;
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

        .topbar-bell-head strong { font-size: 13px; color: var(--ink); }
        .topbar-bell-head small { font-size: 11px; color: #94a3b8; }

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
        .topbar-bell-row i { font-size: 17px; line-height: 1.2; flex-shrink: 0; }
        .topbar-bell-row span { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .topbar-bell-row strong { font-size: 13px; font-weight: 600; color: var(--ink); }
        .topbar-bell-row small { font-size: 11.5px; color: #64748b; }

        /* Same legend hues the dashboards and the Inventory tabs use, so a row
           here is recognisably the same state you land on after clicking it. */
        .topbar-bell-row.is-low      { border-left-color: #eab308; }
        .topbar-bell-row.is-low i    { color: #a16207; }
        .topbar-bell-row.is-expiring { border-left-color: #f97316; }
        .topbar-bell-row.is-expiring i { color: #c2410c; }
        .topbar-bell-row.is-expired  { border-left-color: #dc2626; }
        .topbar-bell-row.is-expired i { color: #b91c1c; }
        .topbar-bell-row.is-return   { border-left-color: #3b82f6; }
        .topbar-bell-row.is-return i { color: #1d4ed8; }
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
        .btn-primary   { background: var(--brand); color: #fff; }
        .btn-info      { background: #0ea5e9; color: #fff; }
        .btn-warning   { background: #f59e0b; color: #fff; }
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

        .btn-primary:hover   { background: var(--brand-dark); }
        .btn-info:hover      { background: #0284c7; }
        .btn-warning:hover   { background: #d97706; }
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

        /* Back always occupies the same slot: its own row, top-left, before
           anything else on the page. Previously some pages sat it inline with
           the title and one buried it inside a card, so its position moved
           depending on where you'd navigated from. */
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
            gap: 6px;
            margin: 0;
        }

        .remedi-table td.col-actions { white-space: nowrap; vertical-align: middle; }

        /* Fallback for tables that don't declare their own .actions-cell. */
        .remedi-table .actions-cell {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .remedi-table .actions-cell form { margin: 0; display: inline-flex; }

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

        @media (max-width: 420px) {
            .kpi-grid { grid-template-columns: 1fr; }
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
                <img src="{{ asset('logo.png') }}" alt="" width="34" height="34">
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
                <button type="button" class="nav-link-btn {{ $inventoryActive ? 'active' : '' }}" id="inventoryNavToggle" aria-expanded="{{ $inventoryActive ? 'true' : 'false' }}">
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
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="ti ti-users" aria-hidden="true"></i> User management
                </a>
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="ti ti-chart-bar" aria-hidden="true"></i> Reports
                </a>
                <a href="{{ route('forecast.index') }}" class="{{ request()->routeIs('forecast.*') ? 'active' : '' }}">
                    <i class="ti ti-trending-up" aria-hidden="true"></i> Forecasting
                </a>
                <a href="{{ route('sales-forecast.index') }}" class="{{ request()->routeIs('sales-forecast.*') ? 'active' : '' }}">
                    <i class="ti ti-chart-line" aria-hidden="true"></i> Sales Forecasting
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
            <a href="{{ route('profile.edit') }}">
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
                            <strong>Alerts</strong>
                            <small id="bellStamp">just now</small>
                        </div>
                        {{-- Item-level rows: each names the product it is about,
                             the way a notification feed does. The kind-level
                             totals moved to the footer below. --}}
                        <div id="bellList">
                            @forelse(($topbarAlertItems ?? []) as $item)
                                <a href="{{ $item['href'] }}" class="topbar-bell-row {{ $item['cls'] }}">
                                    <i class="ti {{ $item['icon'] }}" aria-hidden="true"></i>
                                    <span>
                                        <strong>{{ $item['title'] }}</strong>
                                        <small>{{ $item['body'] }}</small>
                                    </span>
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
            @if(session('success'))
                <div class="alert alert-success">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">
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
            <div class="page-skeleton" aria-hidden="true">
                <div class="sk-greeting">
                    <div class="sk-greeting-text">
                        <div class="sk-block sk-h"></div>
                        <div class="sk-block sk-sub"></div>
                    </div>
                    <div class="sk-block sk-pill"></div>
                </div>

                <div class="sk-kpis">
                    @for ($i = 0; $i < 6; $i++)
                        <div class="sk-kpi">
                            <div class="sk-kpi-head">
                                <div class="sk-block sk-disc"></div>
                                <div class="sk-block sk-cap"></div>
                            </div>
                            <div class="sk-block sk-num"></div>
                            <div class="sk-block sk-note"></div>
                        </div>
                    @endfor
                </div>

                <div class="sk-tabs">
                    <div class="sk-block sk-pill-tab"></div>
                    <div class="sk-block sk-pill-tab"></div>
                </div>

                <div class="sk-block sk-sec-title"></div>
                <div class="sk-block sk-sec-sub"></div>

                <div class="sk-charts">
                    <div class="sk-chart">
                        <div class="sk-block sk-chart-title"></div>
                        <div class="sk-block sk-chart-body"></div>
                    </div>
                    <div class="sk-chart">
                        <div class="sk-block sk-chart-title"></div>
                        <div class="sk-block sk-chart-body"></div>
                    </div>
                </div>

                <div class="sk-lower">
                    <div class="sk-chart">
                        <div class="sk-block sk-chart-title"></div>
                        <div class="sk-block sk-chart-body"></div>
                    </div>
                    <div class="sk-chart">
                        <div class="sk-block sk-chart-title"></div>
                        <div class="sk-block sk-chart-body"></div>
                    </div>
                </div>
            </div>

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
            contentBody.classList.add('is-navigating');
            contentBody.scrollTop = 0;

            // Force a synchronous style+layout flush. Once a navigation is
            // under way the browser is free to skip repainting the outgoing
            // document, which would leave the skeleton applied but never
            // shown. Reading a layout property makes it commit now.
            void contentBody.offsetHeight;
        }

        function clearNavigating() {
            navigating = false;
            contentBody.classList.remove('is-navigating');
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
        let timer = null;
        let inFlight = null;
        let lastSignature = null;
        let lastFetched = Date.now();

        // ── open / close ──
        function setOpen(open) {
            panel.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { touchStamp(); refresh(); }
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
                btn.focus();
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
            const items = data.items || [];
            const alerts = data.alerts || [];

            // Signature guard: re-rendering identical rows on every poll would
            // tear down a row the pointer is already on. Keyed on the item
            // bodies, since those carry the day counts that actually move.
            const sig = items.map(i => i.kind + '|' + i.title + '|' + i.body).join('~')
                + '#' + alerts.map(a => a.kind + ':' + a.count).join('|');
            if (sig === lastSignature) return;
            lastSignature = sig;

            const n = data.count || 0;
            countEl.textContent = n > 9 ? '9+' : String(n);
            countEl.hidden = n < 1;
            btn.title = n + ' notification' + (n === 1 ? '' : 's');
            btn.setAttribute('aria-label', 'Alerts (' + n + ' notifications)');

            list.innerHTML = '';

            if (!items.length) {
                list.innerHTML = '<p class="topbar-bell-empty">Nothing needs attention right now.</p>';
            } else {
                items.forEach(function (it) {
                    const row = document.createElement('a');
                    row.className = 'topbar-bell-row ' + (it.cls || '');
                    row.href = it.href;

                    const icon = document.createElement('i');
                    icon.className = 'ti ' + (it.icon || 'ti-bell');
                    icon.setAttribute('aria-hidden', 'true');

                    // textContent, not innerHTML: these strings carry product
                    // names straight from the catalogue, and this panel renders
                    // on every authenticated page.
                    const title = document.createElement('strong');
                    title.textContent = it.title || '';
                    const body = document.createElement('small');
                    body.textContent = it.body || '';

                    const text = document.createElement('span');
                    text.appendChild(title);
                    text.appendChild(body);

                    row.appendChild(icon);
                    row.appendChild(text);
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

        if (!document.hidden) start();
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

        function open() {
            lastFocus = document.activeElement;
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            confirmBtn.focus();
        }

        function close() {
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            if (lastFocus && lastFocus.focus) lastFocus.focus();
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
                (document.activeElement === confirmBtn ? cancelBtn : confirmBtn).focus();
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
</script>
</body>
</html>
