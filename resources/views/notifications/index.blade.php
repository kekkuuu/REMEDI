@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<style>
    .notif-page { width: 100%; }

    /* ── One surface, not a stack of cards ──
       The list, its filter and its footer belong to the same object, so they
       share one frame. This is what the per-section cards were replaced with:
       a single panel rather than six boxes, and rows that run its full width. */
    .notif-shell {
        display: flex;
        flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 12px 28px -22px rgba(15, 23, 42, .5);
    }

    /* ── Filter strip ──
       Doubles as the summary: each tab carries its own total, so the separate
       chip row this replaced is gone — same information, one band. */
    .notif-tabs {
        display: flex;
        gap: 6px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
        background: linear-gradient(180deg, #fbfdfc, var(--surface));

        /* Seven tabs do not fit a phone, and wrapping them to three rows pushes
           the list below the fold. Scroll sideways instead. */
        overflow-x: auto;
        overscroll-behavior-x: contain;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .notif-tabs::-webkit-scrollbar { height: 7px; }
    .notif-tabs::-webkit-scrollbar-track { background: transparent; }
    .notif-tabs::-webkit-scrollbar-thumb { background: #dbe3ec; border-radius: 4px; }
    .notif-tabs:hover::-webkit-scrollbar-thumb { background: #cbd5e1; }

    .ntab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        flex: 0 0 auto;               /* never squeeze: they scroll instead */
        padding: 8px 14px;
        border: 1px solid transparent;
        border-radius: 999px;
        background: none;
        font: inherit;
        font-size: 13px;
        font-weight: 500;
        color: var(--ink-soft);
        white-space: nowrap;
        cursor: pointer;
        transition: background .14s ease, color .14s ease, border-color .14s ease;
    }

    .ntab:hover { background: #f1f5f9; color: var(--ink); }

    .ntab.is-active {
        background: var(--brand-tint);
        border-color: var(--brand-soft);
        color: var(--brand-darker);
        font-weight: 600;
    }

    .ntab .n {
        font-size: 11px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 999px;
        background: #f1f5f9;
        color: var(--ink-soft);
        font-variant-numeric: tabular-nums;
    }

    .ntab.is-active .n { background: #fff; color: var(--brand-darker); }

    /* A small severity dot rather than a full disc — the rows carry the discs,
       and repeating them in the tabs made the strip noisy. */
    .ntab .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
    .dot.is-low      { background: #eab308; }
    /* Rows only -- the tab dot stays amber, because the TAB is the low_stock
       kind as a whole and out-of-stock products are counted inside it. */
    .dot.is-out      { background: #334155; }
    .dot.is-expiring { background: #f97316; }
    .dot.is-expired  { background: #dc2626; }
    .dot.is-return   { background: #0ea5e9; }
    .dot.is-missed   { background: #8b5cf6; }
    .dot.is-system   { background: #6366f1; }
    .dot.is-update   { background: #22c55e; }

    /* ── The scrolling list ──
       The list scrolls inside the panel rather than the whole document: with
       46 rows the page ran past 4,000px, which put the filter you were using
       thousands of pixels above the rows it was filtering. Bounded by the
       viewport so the panel always ends on screen. */
    /* max-height only, no min-height: the panel sizes to whatever the ACTIVE
       tab holds. A 4-row System tab is a 4-row panel; only a tab that actually
       overflows the space gets a scrollbar. A floor here forced every short tab
       to render as a tall box of empty white. */
    .notif-scroll {
        max-height: calc(100vh - 300px);
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .notif-scroll::-webkit-scrollbar { width: 10px; }
    .notif-scroll::-webkit-scrollbar-track { background: transparent; }
    .notif-scroll::-webkit-scrollbar-thumb {
        background: #dbe3ec;
        border-radius: 6px;
        border: 3px solid var(--surface);   /* inset, so it floats off the edge */
    }

    .notif-scroll::-webkit-scrollbar-thumb:hover { background: #b6c2d1; }

    /* ── Context bar ──
       Replaces the per-kind sticky headings this list used to break into. The
       list is now one chronological feed, so there are no contiguous runs left
       to head; what the headings actually carried — the kind's true open total
       and its "view the rest in Inventory" link — moves here and follows the
       active tab instead.

       Outside the scroller rather than sticky inside it: there is only one, so
       it is always on screen anyway and does not need to float over rows. */
    .notif-context {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 11px 20px;
        background: rgba(248, 250, 252, .94);
        border-bottom: 1px solid var(--line);
        font-family: 'Outfit', sans-serif;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: .07em;
        text-transform: uppercase;
        color: var(--ink-soft);
    }

    .notif-context .spacer { margin-left: auto; }

    .notif-context a {
        font-size: 11.5px;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: none;
        color: var(--brand-darker);
        text-decoration: none;
        white-space: nowrap;
    }

    .notif-context a:hover { text-decoration: underline; }
    .notif-context a[hidden], .notif-context .dot[hidden] { display: none; }

    /* ── Rows ──
       Reuses .topbar-bell-row so severity discs and read state behave exactly
       as in the dropdown; the panel's pill radius, tight padding and left
       stripe are overridden for a full-width list. */
    .notif-row {
        position: relative;
        border-radius: 0 !important;
        border-left: 0 !important;
        padding: 14px 20px !important;
        align-items: center !important;
        gap: 14px !important;
        border-bottom: 1px solid #f1f5f9;
        transition: background .12s ease;
    }

    .notif-row:last-child { border-bottom: none; }
    .notif-row:hover { background: #f8fafc; }

    /* Severity re-enters on hover as a left edge — colour where the eye already
       is, without 46 permanent stripes down the list. */
    .notif-row::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 3px;
        background: transparent;
        transition: background .12s ease;
    }

    .notif-row.is-low:hover::before      { background: #eab308; }
    .notif-row.is-out:hover::before      { background: #334155; }
    .notif-row.is-expiring:hover::before { background: #f97316; }
    .notif-row.is-expired:hover::before  { background: #dc2626; }
    .notif-row.is-return:hover::before   { background: #0ea5e9; }
    .notif-row.is-missed:hover::before   { background: #8b5cf6; }
    .notif-row.is-system:hover::before   { background: #6366f1; }
    .notif-row.is-update:hover::before   { background: #22c55e; }

    .notif-row .bell-icon { width: 40px; height: 40px; font-size: 18px; border-radius: 12px; }
    .notif-row .bell-text strong { font-size: 14px; letter-spacing: -.005em; }
    .notif-row .bell-text small { font-size: 12.5px; line-height: 1.45; }

    .notif-row .go {
        margin-left: auto;
        flex-shrink: 0;
        font-size: 17px;
        color: #dbe3ec;
        transition: transform .14s ease, color .14s ease;
    }

    .notif-row:hover .go { color: var(--brand); transform: translateX(3px); }

    /* The dot is the unread cue this page was missing: the dropdown's 62% fade
       reads as "quiet" across a full-width list rather than as "handled".

       It needs no layout rule of its own, and must not get one. .bell-text is
       flex:1, so the dot and the chevron are already pinned right; hanging the
       row's auto margin on the dot instead would collapse the chevron back into
       the text the moment .is-read hid the dot. */

    /* ── Footer ── */
    .notif-foot {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 20px;
        border-top: 1px solid var(--line);
        background: #fbfdfc;
        font-size: 12px;
        color: var(--ink-soft);
    }

    .notif-foot a { margin-left: auto; font-weight: 600; color: var(--brand-darker); text-decoration: none; }
    .notif-foot a:hover { text-decoration: underline; }

    /* "Mark all as read" belongs with the footer's link rather than in the
       filter strip: it acts on the whole list, and the strip is for narrowing
       it. Two margin-left:autos in a row collapse to one gap, so this and
       "Open Inventory" end up side by side on the right. */
    .notif-markread {
        margin-left: auto;
        padding: 0;
        border: 0;
        background: none;
        font: inherit;
        font-size: 12px;
        font-weight: 600;
        color: var(--brand-darker);
        cursor: pointer;
    }

    .notif-markread:hover { text-decoration: underline; }
    .notif-markread[hidden] { display: none; }

    .notif-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 60px 20px;
        color: #94a3b8;
        font-size: 13.5px;
        text-align: center;
    }

    .notif-empty i { font-size: 34px; color: var(--brand-soft); }

    @media (max-width: 640px) {
        .notif-shell { border-radius: 14px; }
        .notif-tabs { padding: 12px; }
        .notif-row { padding: 12px 14px !important; }
        .notif-context { padding: 10px 14px; }
        .notif-row .bell-icon { width: 36px; height: 36px; font-size: 16px; }
        .notif-scroll { max-height: calc(100vh - 260px); }
    }
</style>

@php
    $alertItems = collect($items)->filter(fn ($i) => ($i['group'] ?? 'alerts') === 'alerts');
    $activityItems = collect($activity);

    $style = [
        'low_stock' => 'is-low',
        'expiring' => 'is-expiring',
        'expired' => 'is-expired',
        'need_to_return' => 'is-return',
        'fail_to_return' => 'is-missed',
    ];

    // ── One flat feed, newest first ──
    // Not grouped by kind any more. Every row carries a `section` so the tabs
    // can still narrow the list to one kind, but nothing is clustered: an
    // expired batch from this morning sits above a low-stock product from last
    // week regardless of which kind each belongs to.
    //
    // Ordered on the same `sort_at` clock AlertService stamps for the bell, so
    // this page and the dropdown can never disagree about what is latest.
    $rows = $alertItems
        ->map(fn ($i) => $i + ['section' => $i['kind']])
        ->concat($activityItems->map(fn ($i) => $i + ['section' => $i['group']]))
        ->sortByDesc(fn ($i) => strtotime($i['sort_at'] ?? $i['at'] ?? '@0'))
        ->values();

    $counts = $rows->countBy('section');

    // Filter strip. Each tab carries its kind's TRUE open total (not the capped
    // number of rows rendered below) plus the link that reaches the remainder —
    // which is what the per-section headings used to carry.
    $tabs = [];

    foreach ($totals as $total) {
        if (! $counts->has($total['kind'])) {
            continue;
        }
        $tabs[] = [
            'key' => $total['kind'],
            'label' => $total['title'],
            'cls' => $style[$total['kind']] ?? 'is-return',
            'total' => $total['count'],
            'href' => $total['href'],
            'linkText' => $total['count'] > $counts[$total['kind']]
                ? 'View all '.number_format($total['count'])
                : 'Open in Inventory',
        ];
    }

    foreach ([['system', 'System', 'is-system'], ['updates', 'Updates', 'is-update']] as [$key, $label, $cls]) {
        if (! $counts->has($key)) {
            continue;
        }
        $tabs[] = [
            'key' => $key,
            'label' => $label,
            'cls' => $cls,
            'total' => $counts[$key],
            'href' => auth()->user()?->isAdmin() ? route('audit.index') : null,
            'linkText' => 'Open Audit Trail',
        ];
    }

    $shownTotal = $rows->count();
    $openTotal = collect($totals)->sum('count');
@endphp

<div class="profile-head">
    <span class="profile-head-mark" aria-hidden="true"><i class="ti ti-bell"></i></span>
    <div>
        <h3>Notifications</h3>
        <p>Everything currently needing attention, and recent account activity.</p>
    </div>
</div>

<div class="notif-page">
  <div class="notif-shell">

    <div class="notif-tabs" role="tablist">
        <button type="button" class="ntab is-active" data-filter="all" role="tab" aria-selected="true">
            All <span class="n">{{ number_format($shownTotal) }}</span>
        </button>
        @foreach($tabs as $tab)
            <button type="button" class="ntab" data-filter="{{ $tab['key'] }}" role="tab" aria-selected="false"
                    data-label="{{ $tab['label'] }}"
                    data-dot="{{ $tab['cls'] }}"
                    data-href="{{ $tab['href'] }}"
                    data-linktext="{{ $tab['linkText'] }}">
                <span class="dot {{ $tab['cls'] }}" aria-hidden="true"></span>
                {{ $tab['label'] }}
                <span class="n">{{ number_format($tab['total']) }}</span>
            </button>
        @endforeach
    </div>

    {{-- Follows the active tab; see the .notif-context note in the styles. --}}
    <div class="notif-context" id="notifContext">
        <span class="dot" id="notifContextDot" aria-hidden="true" hidden></span>
        <span id="notifContextLabel">All notifications</span>
        <span class="spacer"></span>
        <a id="notifContextLink" href="{{ route('inventory.index') }}">Open Inventory</a>
    </div>

    <div class="notif-scroll" id="notifList">
        @forelse($rows as $item)
            <a href="{{ $item['href'] }}" class="topbar-bell-row notif-row {{ $item['cls'] }}"
               data-alert-id="{{ $item['id'] ?? '' }}"
               data-section="{{ $item['section'] }}"
               data-sort-at="{{ $item['sort_at'] ?? $item['at'] ?? '' }}">
                <span class="bell-icon" aria-hidden="true"><i class="ti {{ $item['icon'] }}"></i></span>
                <span class="bell-text">
                    <strong>{{ $item['title'] }}</strong>
                    <small>{{ $item['body'] }}</small>
                    @if(!empty($item['when']))
                        <em class="bell-time" data-when="{{ $item['when'] }}"
                            @if(!empty($item['at'])) data-at="{{ $item['at'] }}" @endif
                            @if(empty($item['at']) && !empty($item['sort_at'])) data-since="{{ $item['sort_at'] }}" @endif
                        >{{ $item['when'] }}</em>
                    @endif
                </span>
                <span class="unread-dot" aria-hidden="true"></span>
                <i class="ti ti-chevron-right go" aria-hidden="true"></i>
            </a>
        @empty
            <p class="notif-empty">
                <i class="ti ti-circle-check" aria-hidden="true"></i>
                Nothing needs attention right now.
            </p>
        @endforelse
    </div>

    @if($shownTotal)
        <div class="notif-foot">
            {{-- The list is capped per kind, so it must say so rather than
                 implying these 46 rows are everything. --}}
            <span id="notifShowing">Showing {{ number_format($shownTotal) }}</span>
            <span>of {{ number_format($openTotal) }} open alerts</span>
            {{-- Hidden until the script confirms something is actually unread;
                 without JS there is no read state to clear, so no button. --}}
            <button type="button" class="notif-markread" id="notifMarkRead" hidden>Mark all as read</button>
            <a href="{{ route('inventory.index') }}">Open Inventory</a>
        </div>
    @endif

  </div>
</div>

<script>
(function () {
    const list = document.getElementById('notifList');
    if (!list) return;

    const showing = document.getElementById('notifShowing');

    /* ── Fit the scroller to what is actually left on screen ──
       The CSS max-height is a calc() guess: it cannot know how tall the page
       heading and the filter strip are, so on a 900px window the panel ran
       50px past the fold and the document scrolled as well as the list. Measure
       instead, and keep the CSS value as the pre-JS fallback. */
    function fitScroller() {
        const top = list.getBoundingClientRect().top;
        const foot = document.querySelector('.notif-foot');
        const footH = foot ? foot.getBoundingClientRect().height : 0;
        const room = window.innerHeight - top - footH - 24;   // 24px breathing room

        // Below this the list is too short to be worth scrolling inside; let
        // the document take over instead.
        list.style.maxHeight = Math.max(260, Math.round(room)) + 'px';
    }

    fitScroller();
    window.addEventListener('resize', fitScroller);

    /* ── Filter ──
       Client-side on data-section: everything is already rendered, so a round
       trip per tab would add latency to data the page is holding.

       The list itself is one flat feed, so filtering is now only ever
       show/hide on the rows — there are no section headings left to keep in
       step with them. What the headings carried moves to the context bar,
       which each tab repopulates from its own data attributes. */
    const ctxDot = document.getElementById('notifContextDot');
    const ctxLabel = document.getElementById('notifContextLabel');
    const ctxLink = document.getElementById('notifContextLink');

    function setContext(tab) {
        if (!ctxLabel) return;

        const isAll = !tab || tab.dataset.filter === 'all';

        ctxLabel.textContent = isAll ? 'All notifications' : (tab.dataset.label || '');

        if (ctxDot) {
            ctxDot.className = 'dot ' + (isAll ? '' : (tab.dataset.dot || ''));
            ctxDot.hidden = isAll;
        }

        if (ctxLink) {
            const href = isAll ? @json(route('inventory.index')) : (tab.dataset.href || '');
            ctxLink.href = href;
            ctxLink.textContent = isAll ? 'Open Inventory' : (tab.dataset.linktext || '');
            // A staff account gets no audit link, so the tab carries no href
            // and the bar simply drops the link rather than rendering a dead one.
            ctxLink.hidden = !href;
        }
    }

    document.querySelectorAll('.ntab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            const filter = tab.dataset.filter;

            document.querySelectorAll('.ntab').forEach(function (t) {
                const on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            list.querySelectorAll('.notif-row[data-section]').forEach(function (el) {
                const match = filter === 'all' || el.dataset.section === filter;
                // display, not the hidden attribute: .topbar-bell-row sets
                // display:flex, which beats [hidden]'s UA display:none.
                el.style.display = match ? '' : 'none';
            });

            setContext(tab);

            if (showing) {
                const n = list.querySelectorAll('.notif-row:not([style*="display: none"])').length;
                showing.textContent = 'Showing ' + n.toLocaleString();
            }

            // Back to the top of the LIST, not the document — the filter itself
            // stays where it is.
            list.scrollTo({ top: 0, behavior: 'smooth' });

            // The active tab's content is a different height, so the panel has
            // to be re-measured or a short tab keeps the tall tab's box.
            fitScroller();
        });
    });

    /* The layout's bell script only stamps timestamps inside #bellList, so the
       page's rows need the same pass, in the same format. Keep these two in
       step: an audit row is an EVENT and gets "x ago"; an inventory alert is a
       standing condition and gets how long it has been open beside its onset,
       because "2 minutes ago" would claim it started then. */
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

    /* FLOOR, not round -- a shelf that emptied 110 seconds ago has been empty
       for one minute. Dropped past 30 days, where the onset date says it better
       than "· 412 days", and for an onset in the future, which is never real. */
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

    function stamp() {
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

    stamp();
    // Same tick as the bell: the smallest unit printed is a minute, so a 60s
    // interval could leave a row reading "just now" for nearly two.
    setInterval(stamp, 15000);

    /* ── Read / unread ──────────────────────────────────────────────────
       The same store the bell paints from (window.remediAlertReads, defined in
       layouts/app.blade.php), keyed on the same AlertService ids. This page
       rendered those ids from the start but never read the store, so a row you
       had already opened still looked new here while the dropdown showed it
       faded — two views of one fact, disagreeing.

       Read rows sink to the bottom of the whole list, not of a section: there
       are no sections any more. Within each read bucket the order is the same
       newest-first sort the server rendered, re-applied here so a row that has
       just been marked read lands in the right place among the other read rows
       rather than at the very end.

       Matches the bell's orderRows() exactly — same two keys, same order. */
    let reads = null;
    const markAll = document.getElementById('notifMarkRead');

    function sortKey(row) {
        const t = Date.parse(row.dataset.sortAt || '');
        return isNaN(t) ? -Infinity : t;
    }

    function sinkRead() {
        [...list.querySelectorAll('.notif-row')]
            .sort(function (a, b) {
                return (a.classList.contains('is-read') - b.classList.contains('is-read'))
                    || (sortKey(b) - sortKey(a));
            })
            .forEach(function (row) { list.appendChild(row); });
    }

    function applyRead(reorder) {
        const read = reads.get();
        let unread = 0;

        list.querySelectorAll('.notif-row').forEach(function (row) {
            const id = row.dataset.alertId;
            const isRead = id && read.has(id);
            row.classList.toggle('is-read', !!isRead);
            if (!isRead) unread++;
        });

        if (markAll) markAll.hidden = unread < 1;

        // reorder=false while the pointer is on a row: these rows are links,
        // and one that moves between mousedown and mouseup takes its own click
        // with it. The sink lands on the next load instead.
        if (reorder !== false) sinkRead();
    }

    function markRowRead(e) {
        const row = e.target.closest('.notif-row');
        if (row && row.dataset.alertId) reads.add(row.dataset.alertId);
    }

    /* This script tag sits in the content section, which the layout yields
       ABOVE its own script block — so the store does not exist yet at parse
       time. Waiting for DOMContentLoaded is what makes the lookup succeed;
       reading window.remediAlertReads here would silently find nothing and
       leave the page with no read state at all.

       (Blade compiles its directives inside script tags too, so this comment
       says "content section" rather than naming the directive — the layout
       died with "Undefined constant" the last time one appeared in a JS
       comment.) */
    function initReads() {
        reads = window.remediAlertReads;
        if (!reads) return;

        list.addEventListener('mousedown', markRowRead);
        // Enter on a focused row fires click, not mousedown.
        list.addEventListener('click', markRowRead);

        if (markAll) {
            markAll.addEventListener('click', function () {
                reads.add([...list.querySelectorAll('.notif-row[data-alert-id]')]
                    .map(function (row) { return row.dataset.alertId; }));
            });
        }

        // Fired by the store, so the bell's "Mark all as read" repaints these
        // rows too — both surfaces are on screen at once here.
        document.addEventListener('remedi:alerts-read', function () { applyRead(false); });

        applyRead();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReads);
    } else {
        initReads();
    }
})();
</script>
@endsection
