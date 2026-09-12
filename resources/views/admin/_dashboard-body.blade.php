{{-- resources/views/admin/_dashboard-body.blade.php --}}
{{--
    The dashboard's whole body, split out so it can be rendered two ways:
    inline by dashboard.blade.php for the no-JS path, and on its own by
    DashboardController's AJAX branch, which returns it as {html} for the
    shell to inject. Admin view: KPI tiles, alerts, and the Chart.js panels.

    Keep the <script> blocks at the BOTTOM of this file. The injector runs
    them in document order after the markup is in the DOM, so anything that
    queries an element it expects to exist must come after that element.
--}}
<style>
    .dash-eyebrow { font-size: 13px; color: #64748b; margin-bottom: 4px; }
    .dash-title {
        font-family: 'Outfit', sans-serif;
        font-size: 26px;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 4px;
    }
    .dash-timestamp { font-size: 13px; color: #94a3b8; margin-bottom: 22px; }

    /* Stat-card styles (.kpi*) live in layouts/app.blade.php — they're a
       shared primitive now, used by the dashboard AND the report pages. */


    /* (The old .pill-row / .hero-stat styles lived here. Both were replaced
       by .kpi-grid above and their markup is gone, so the rules went too.) */

    /* ── Greeting bar ── */
    .dash-greeting {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .dash-greeting h3 {
        margin: 0;
        font-family: 'Outfit', sans-serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ink);
    }

    .dash-greeting p { margin: 4px 0 0; font-size: 13.5px; color: var(--ink-soft); }

    .dash-datepill {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 9px 15px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fff;
        font-size: 13px;
        color: #475569;
        white-space: nowrap;
    }

    .dash-datepill i { color: var(--brand); font-size: 17px; }

    /* ── KPI delta row ── */
    .kpi-delta {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 9px;
        font-size: 12px;
        font-weight: 700;
    }

    .kpi-delta.is-up   { color: #059669; }
    .kpi-delta.is-down { color: #dc2626; }
    /* No baseline to compare against — stated rather than left blank. */
    .kpi-delta.is-flat { color: #94a3b8; }
    .kpi-delta i { font-size: 14px; }
    .kpi-delta-note { font-weight: 500; color: #94a3b8; margin-left: 2px; }

    /* Charts sit side by side like the reference, each in a height box.
       Stacked full-width they dominated the page and pushed everything else
       below the fold. */
    /* stretch, not start: Total Sales per Month carries a year filter in its
       header and Seasonal Trends does not, so the two cards came out 336px and
       297px and their plot areas sat at different heights -- two charts side by
       side that do not share a baseline read as misaligned rather than as a
       pair. Stretching makes the cards equal and the chart boxes below flex to
       fill, so both plots start and end on the same line. */
    .dash-charts {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 16px;
        align-items: stretch;
    }

    .dash-charts > .card { display: flex; flex-direction: column; }

    /* Aligning the two graphs means aligning the PLOTS, not just the cards.
       The two headers differ (one carries the year filter, the other is a bare
       label) and only the left card has a footnote under its chart, so letting
       the boxes flex to fill produced 220px and 259px plots starting 13px
       apart. Equal header floor + equal fixed plot height instead: both plots
       now start and end on the same line, and the card the footnote does not
       fill simply carries the slack at its foot. */
    .dash-charts .panel-head,
    .dash-charts .demand-head {
        min-height: 30px;
        align-items: center;
        /* Equal gap below the header too -- the two classes ship different
           bottom margins, which left the plots 4px out of line. */
        margin: 0 0 14px;
    }

    .dash-charts .chart-box { flex: none; height: 240px; }

    @media (max-width: 1000px) {
        .dash-charts { grid-template-columns: minmax(0, 1fr); }
    }

    /* ── Lower strip ── */
    /* minmax(0, …) and min-width:0 on the items, not a bare 1fr. A grid item's
       default min-width is auto, so the Recent Sales card could not shrink
       below its table's min-content: on a 375px screen the card ran to 604px,
       229px of it off-screen and unreachable, while .table-scroll sat at 560px
       thinking it had the room and so never scrolled. */
    .dash-lower {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
        gap: 16px;
        align-items: start;
        margin-top: 16px;
    }

    .dash-lower-main, .dash-lower-side { min-width: 0; }

    @media (max-width: 1100px) {
        .dash-lower { grid-template-columns: minmax(0, 1fr); }
    }

    .dash-lower-side { display: flex; flex-direction: column; gap: 16px; }

    /* Recent Sales is a table and Sales Summary is a stat card, so the pair sat
       at 540px against 241px -- ~300px of background beside the summary. Rather
       than truncate the table or stretch the card into dead space, the two meet
       in the middle: the summary's ring grows, and the table scrolls inside a
       capped box while showing MORE rows than it did unscrolled. */
    .dash-lower-main .list-scroll { max-height: 288px; }

    .dash-lower-side .chart-box { height: 290px; }

    /* Stacked, not side by side. Quick Actions is inherently short and Alerts
       inherently tall, so a two-column row always left one side empty. */
    .dash-actions-row {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 22px;
    }

    /* Quick Actions as one horizontal bar: label left, the four actions across,
       matching the staff dashboard. */
    .actions-bar {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }

    .actions-bar .label { flex-shrink: 0; }

    .dash-actions-row .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        flex: 1;
    }

    .dash-actions-row .quick-action { flex: 1 1 168px; justify-content: center; }

    /* Alerts across the width, so five of them do not build a tall column. */
    .alerts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 10px;
    }

    .alerts-grid .alert-row { margin-bottom: 0; }

    @media (max-width: 640px) {
        .dash-actions-row .quick-action { flex: 1 1 100%; justify-content: flex-start; }
    }

    /* ── Quick actions ── */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .quick-action {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 12px 13px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #f8fafc;
        color: #334155;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: background .15s ease, border-color .15s ease, transform .13s ease;
    }

    .quick-action i {
        width: 30px;
        height: 30px;
        flex-shrink: 0;
        border-radius: 8px;
        background: #fff;
        border: 1px solid var(--line);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: var(--brand);
    }

    .quick-action:hover {
        background: var(--brand-tint);
        border-color: var(--brand-soft);
        transform: translateY(-2px);
    }

    /* ── Alert rows ──
       The left rule carries the status colour, using the same legend as the
       badges: yellow low stock, orange expiring, red expired, blue return. */
    .alert-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 11px 12px;
        border-radius: 10px;
        border-left: 3px solid #cbd5e1;
        background: #f8fafc;
        margin-bottom: 8px;
        text-decoration: none;
        color: inherit;
        transition: background .15s ease;
    }

    .alert-row:last-child { margin-bottom: 0; }
    .alert-row:hover { background: #f1f5f9; }
    .alert-row i { font-size: 17px; margin-top: 1px; }
    .alert-row span { display: flex; flex-direction: column; gap: 2px; }
    .alert-row strong { font-size: 13px; font-weight: 600; }
    .alert-row small { font-size: 12px; color: var(--ink-soft); }

    .alert-row.is-low      { border-left-color: #eab308; }
    .alert-row.is-low i    { color: #ca8a04; }
    .alert-row.is-expiring { border-left-color: #f97316; }
    .alert-row.is-expiring i { color: #ea580c; }
    .alert-row.is-expired  { border-left-color: #dc2626; }
    .alert-row.is-expired i  { color: #dc2626; }
    .alert-row.is-return   { border-left-color: #3b82f6; }
    .alert-row.is-return i   { color: #2563eb; }
    .alert-row.is-missed   { border-left-color: #9f1239; }
    .alert-row.is-missed i   { color: #9f1239; }

    /* ── Sales summary ── */
    .summary-split {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 14px;
        align-items: center;
    }

    @media (max-width: 480px) {
        .summary-split { grid-template-columns: 1fr; }
    }

    .summary-figures { display: flex; flex-direction: column; }

    .summary-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .summary-value {
        font-family: 'Outfit', sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }

    .summary-value.is-small { font-size: 19px; }

    .dash-footer {
        margin: 26px 0 4px;
        text-align: center;
        font-size: 12px;
        color: #94a3b8;
    }

    /* Two-column body matching the mock-up: stacked cards on the left, one tall chart on the right */
    .dash-body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }

    @media (max-width: 900px) {
        .dash-body { grid-template-columns: 1fr; }
    }

    .dash-body > .card {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    /* ── Shared surface treatment ──
       Cards were a flat 0.5px outline with no elevation, which left every
       panel sitting at the same visual depth as the page. A soft, wide,
       low-opacity shadow lifts them off the slate background without the
       heavy drop-shadow look. */
    .dash-section .card {
        border-radius: 14px;
        border-color: #eef2f7;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04),
                    0 10px 22px -14px rgba(15, 23, 42, 0.16);
    }


    /* Panel titles: bold, and in the ink colour rather than the muted slate
       they used to sit in. At #64748b and 11.5px they read as captions, and on
       a page of fourteen cards the eye had nothing to anchor on -- you had to
       hunt for which chart you were looking at. Still uppercase and still
       small, so they label the card without competing with the figures in it;
       the weight and the contrast do the work instead of the size. */
    .panel-title,
    .demand-head span.label {
        font-size: 12px;
        font-weight: 700;
        color: var(--ink);
        margin: 0 0 14px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .demand-head span.label { margin: 0; }

    /* Card header with a control on the right (e.g. the year filter). */
    .panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .panel-head .panel-title { margin: 0; }

    .year-select {
        font-family: inherit;
        font-size: 12.5px;
        font-weight: 600;
        color: #1e293b;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 7px;
        padding: 5px 10px;
        cursor: pointer;
    }

    .year-select:focus {
        outline: 2px solid #93b4d8;
        outline-offset: 1px;
    }

    /* minmax(0, …) and min-width:0 on the items, not a bare 1fr — the same
       rule .dash-lower above documents. An fr track's implicit minimum is
       min-content and a grid item's default min-width is auto, so these two
       tracks refused to resolve below 300px each: measured at a 768px tablet,
       where the 216px sidebar leaves a 447px grid, they asked for 620px and
       the Low Demand panel was clipped 173px outside its card. */
    .demand-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 20px;
    }

    .demand-grid > * { min-width: 0; }

    /* 900px, not 500px. The media query keys off the VIEWPORT, but these
       charts sit in a content column the sidebar has already taken 216px out
       of — so a 768px tablet was still being told to fit two demand charts
       into 447px. Stacking them there also just reads better on a tablet. */
    @media (max-width: 900px) {
        .demand-grid { grid-template-columns: minmax(0, 1fr); }
    }

    .demand-list li {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13.5px;
        color: #334155;
        padding: 6px 0;
    }

    .demand-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    /* Section heading above each dashboard tab's content. */
    .dash-section-head {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 18px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e8edf3;
    }

    .dash-section-title {
        font-family: 'Outfit', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }

    .dash-section-sub {
        font-size: 12.5px;
        color: #94a3b8;
        margin: 2px 0 0;
    }

    /* Inventory tab: overview ring on the left, the expiry/stock panels
       stacked beside it, matching the reference layout. */
    /* The WIDE column goes to Inventory by Category, not to the side stack.
       That card carries a 230px ring AND a ten-row legend whose longest line is
       "Medicine / Pharmaceutical · 409,578 units in stock · 1,392 (44.6%)";
       the side stack is a bar and a chart, both of which read fine narrow. With
       the proportions the other way round the legend was handed 135px, and the
       category names rendered at 15px wide — one letter per line, with the unit
       counts overflowing the row. Measured, not guessed: the legend needs ~300px
       and now gets ~318px at 1440. */
    .inv-top {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
        gap: 16px;
        align-items: start;
    }

    .inv-side { display: flex; flex-direction: column; gap: 16px; }

    /* Bottom band of the Inventory tab: the two Expiring Soon lists with the
       two returns panels stacked in a narrower third column beside them. Was
       two separate 2-up rows, which pushed returns below the fold and left the
       expiring lists sharing a row with nothing to balance them. */
    /* minmax(0, …) on every track, not a bare 1fr. An fr track's implicit
       minimum is min-content, and these cards carry long batch numbers and a
       no-wrap header meta line -- left as 1fr the tracks resolved to
       430/536/536 instead of the ratio asked for. */

    /* .inv-pair is gone with the Stock Status card; the side column is a plain
       flex stack now. */
    @media (max-width: 1200px) {
        .inv-top { grid-template-columns: minmax(0, 1fr); }
    }

    /* ── Expiry Overview bar ── */
    .expiry-bar {
        display: flex;
        height: 14px;
        border-radius: 999px;
        overflow: hidden;
        background: #f1f5f9;
        margin-top: 4px;
    }

    .expiry-bar-seg { display: block; height: 100%; }

    .expiry-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 20px;
        margin-top: 14px;
        font-size: 12.5px;
        color: #475569;
    }

    .expiry-legend-item { display: inline-flex; align-items: center; gap: 6px; }

    .expiry-legend-item .dot {
        width: 9px;
        height: 9px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .expiry-legend-item strong { color: #1e293b; }
    .expiry-legend-item small { color: #94a3b8; }

    /* Inventory by Category panel */
    /* Centred, not top-aligned. Ten categories make the legend ~460px against a
       230px ring, and top-aligning left the whole difference as one block of
       white under the doughnut. Centring splits it above and below, where it
       reads as padding. */
    /* The ring gives way before the legend does. A hard 250px first track plus
       minmax(0, 1fr) meant the ring always took its 250px and the legend
       absorbed every shortfall, down to 135px — text does not degrade
       gracefully, a doughnut does. Now the ring is allowed to shrink to 170px
       and the legend has a floor it can hold its longest row in. */
    .category-chart-grid {
        display: grid;
        grid-template-columns: minmax(170px, 230px) minmax(230px, 1fr);
        gap: 24px;
        align-items: center;
    }

    /* Capped so this card matches the .inv-side column next to it instead of
       running ~70px past it. Cap the height, not the row count -- every
       category is still here, the list just scrolls. */
    .category-chart-grid > ul {
        max-height: 460px;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding-right: 6px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .category-chart-grid > ul::-webkit-scrollbar { width: 8px; }
    .category-chart-grid > ul::-webkit-scrollbar-track { background: transparent; }
    .category-chart-grid > ul::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    /* 900px, not 600px. The query keys off the viewport, but this card lives
       ~385px inside it once the sidebar, the page gutters and the card padding
       are taken out — so at a 768px tablet the two floors (170 ring + 230
       legend + 24 gap = 424) asked for more than the 383px the card had, and
       the legend hung out over the card edge. Ring over legend below that, the
       same width the staff dashboard's copy collapses at. */
    @media (max-width: 900px) {
        .category-chart-grid { grid-template-columns: minmax(0, 1fr); }
    }

    .category-swatch {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 2px;
        margin-right: 7px;
        flex-shrink: 0;
    }

    /* Sales / Inventory tab switcher */
    .dash-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 26px;
    }

    .dash-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: inherit;
        font-size: 14px;
        font-weight: 700;
        color: #64748b;
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 999px;
        padding: 10px 22px;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .dash-tab:hover { border-color: #cbd5e1; color: #334155; background: #f8fafc; }

    /* The active tab gets a soft coloured glow rather than just a fill, so
       the selected section is obvious without the button turning into a
       heavy dark slab. */
    .dash-tab.active.sales {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        box-shadow: 0 4px 12px -4px rgba(16, 185, 129, 0.6);
    }

    .dash-tab.active.inventory {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        box-shadow: 0 4px 12px -4px rgba(16, 185, 129, 0.6);
    }

    .dash-section { display: none; }
    .dash-section.is-active { display: block; }
</style>

{{-- The old eyebrow/title/timestamp block lived here. It duplicated the
     greeting bar below (two headings, two clocks), so the greeting bar is now
     the single page header. --}}

{{-- Headline figures, above the tabs so they're visible from either section.
     The four stock tiles link straight to the Inventory filter that lists
     exactly what they're counting. --}}
@php
    $expiredCount = $expiredBatches->count();
    $expiringCount = $expiringSoonBatches->count();
    $lowStockCount = $lowStockProducts->count();
    // is_returnable, not the medicine-only tally. This drives the KPI card and
    // the Alerts panel row, both labelled generically ("batches can still go
    // back to the supplier") and both linking to the Inventory need_to_return
    // filter — so they have to count what that filter and the bell count.
    // $returnStats keeps the narrow figure for the Medicine Returns card, which
    // is explicitly per-category.
    //
    // Use PHP comment syntax here. This is inside an @php block, so the body is
    // raw PHP and a Blade comment would be a parse error, not a comment.
    $needReturnCount = $returnableCount;
@endphp
{{-- Greeting bar. The date pill is rendered server-side and then kept live by
     a small clock script below, so it never sits at the page-load time. --}}
<div class="dash-greeting">
    <div>
        <h3>{{ $greeting }}, {{ Str::before(auth()->user()->name, ' ') }}!</h3>
        <p>Here's what's happening with your store today.</p>
    </div>
    <div class="dash-datepill">
        <i class="ti ti-calendar" aria-hidden="true"></i>
        <span id="dashClock">{{ now()->format('D, M j, Y') }} &middot; {{ now()->format('g:i A') }}</span>
    </div>
</div>

{{-- The money tiles open the Sales list scoped to the period they report,
     using SaleController's own start_date/end_date filters — so the list they
     land on adds up to the number that was clicked.

     Deliberately NOT the sales report: both figures come from `sales` (live POS
     checkouts), while /reports/sales reads `sales_history`. Sending "Sales
     Today" there would answer a POS number with a different table's total. --}}
<div class="kpi-grid">
    <a href="{{ route('sales.index', ['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]) }}"
       class="kpi" style="--kpi-accent:#10b981;">
        <div class="kpi-head">
            <i class="ti ti-cash" aria-hidden="true"></i>
            <span class="kpi-label">Sales Today</span>
        </div>
        <span class="kpi-value">&#8369;{{ number_format($todaySales, 2) }}</span>
        {{-- Two sub-lines, terminal total then this user's share of it. Both
             carry a qualifier ("all cashiers" / "by you") because two bare
             counts stacked in one tile read as a contradiction rather than a
             total and a part of it. .kpi-sub is display:block, so they stack
             without any new CSS. --}}
        <span class="kpi-sub">{{ $todayTransactions }} {{ Str::plural('transaction', $todayTransactions) }} &middot; all cashiers</span>
        <span class="kpi-sub">{{ $myTodayTransactions }} by you &middot; &#8369;{{ number_format($myTodaySales, 2) }}</span>
        <x-kpi-delta :change="$salesTodayDelta" against="vs yesterday" empty-label="No sales" />
    </a>

    {{-- Same seven days DashboardController sums (today-6 through today). --}}
    <a href="{{ route('sales.index', ['start_date' => today()->subDays(6)->toDateString(), 'end_date' => today()->toDateString()]) }}"
       class="kpi" style="--kpi-accent:#0d9488;">
        <div class="kpi-head">
            <i class="ti ti-calendar-stats" aria-hidden="true"></i>
            <span class="kpi-label">Last 7 Days</span>
        </div>
        <span class="kpi-value">&#8369;{{ number_format($last7Sales, 2) }}</span>
        <span class="kpi-sub">{{ number_format($totalProducts) }} products in catalog</span>
        <x-kpi-delta :change="$last7Delta" against="vs previous 7 days" empty-label="No sales" />
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}"
       class="kpi {{ $lowStockCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#eab308;">
        <div class="kpi-head">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            <span class="kpi-label">Low Stock</span>
        </div>
        <span class="kpi-value">{{ number_format($lowStockCount) }}</span>
        <span class="kpi-sub">at or below reorder level</span>
    </a>

    <a href="{{ \App\Models\ProductBatch::expiringSoonUrl() }}"
       class="kpi {{ $expiringCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#f97316;">
        <div class="kpi-head">
            <i class="ti ti-clock-exclamation" aria-hidden="true"></i>
            <span class="kpi-label">Expiring Soon</span>
        </div>
        <span class="kpi-value">{{ number_format($expiringCount) }}</span>
        <span class="kpi-sub">batches within 30 days</span>
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'expired']) }}"
       class="kpi {{ $expiredCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#dc2626;">
        <div class="kpi-head">
            <i class="ti ti-alert-octagon" aria-hidden="true"></i>
            <span class="kpi-label">Expired</span>
        </div>
        <span class="kpi-value">{{ number_format($expiredCount) }}</span>
        <span class="kpi-sub">batches still in stock</span>
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}"
       class="kpi {{ $needReturnCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#3b82f6;">
        <div class="kpi-head">
            <i class="ti ti-package-export" aria-hidden="true"></i>
            <span class="kpi-label">Need to Return</span>
        </div>
        <span class="kpi-value">{{ number_format($needReturnCount) }}</span>
        <span class="kpi-sub">{{ $returnStats['fail_to_return'] }} already missed window</span>
    </a>
</div>

{{-- Quick Actions + Alerts sit ABOVE the switcher, directly under the KPI row.
     Neither belongs to Sales or Inventory -- they are the "what do I do now"
     band and stay visible on either tab -- so the switcher that scopes only the
     section below it comes after them, not before. Full-width rows rather than
     two columns: a short card beside a tall one left a large visible void. --}}
@include('admin._dashboard-actions')

{{-- Tab switcher: shows either the Sales section or the Inventory section.
     Everything it governs is below it. --}}
<div class="dash-tabs">
    <button type="button" class="dash-tab sales active" data-target="sales" onclick="showDashSection('sales')">Sales</button>
    <button type="button" class="dash-tab inventory" data-target="inventory" onclick="showDashSection('inventory')">Inventory</button>
</div>

{{-- ===================== SALES ===================== --}}
<div class="dash-section sales is-active" id="dashSection-sales">
    <div class="dash-section-head">
        <div>
            <p class="dash-section-title">Sales</p>
            <p class="dash-section-sub">Revenue and product demand</p>
        </div>
    </div>

    {{-- Year filter is applied client-side: every month is already in the
         page, so narrowing the view costs no query and no reload. --}}
    @php
        // Seasonal Trends: one series per year, Jan..Dec, newest first. Built
        // from $monthlySales (already loaded for the chart beside it), so this
        // costs nothing extra. Months a year has no data for stay null, which
        // spanGaps renders as a line that simply stops rather than diving to
        // zero -- the current year is only part-finished.
        $seasonalByYear = collect($monthlySales)
            ->groupBy(fn ($row) => substr($row['ym'], 0, 4))
            ->map(function ($rows, $year) {
                $months = array_fill(1, 12, null);

                foreach ($rows as $row) {
                    $months[(int) substr($row['ym'], 5, 2)] = round($row['total'], 2);
                }

                return ['year' => $year, 'months' => array_values($months)];
            })
            ->sortKeysDesc()
            ->take(3)          // three years is what stays readable on one card
            ->values();

        $salesYears = collect($monthlySales)
            ->pluck('ym')
            ->map(fn ($ym) => substr($ym, 0, 4))
            ->unique()
            ->sortDesc()
            ->values();
    @endphp
    <div class="dash-charts">
    <div class="card">
        <div class="panel-head">
            <p class="panel-title">Total Sales per Month</p>
            @if ($salesYears->count() > 1)
                {{-- Defaults to the most recent year, not "all". With 48 months
                     of history the all-years view is 48 bars crammed edge to
                     edge; a single year reads as twelve months at a glance,
                     which is what this card is for. --}}
                <select id="monthlyYearFilter" class="year-select" aria-label="Filter sales by year">
                    <option value="all">All years</option>
                    @foreach ($salesYears as $year)
                        <option value="{{ $year }}" @selected($loop->first)>{{ $year }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="chart-box"><canvas id="monthlySalesChart"></canvas></div>
        <p id="monthlyYearSummary" style="margin:10px 0 0; font-size:12.5px; color:#94a3b8;"></p>
    </div>

    {{-- Seasonal Trends — average sales per calendar month across all
         history (see SalesHistory::seasonalTrends()), so recurring high/low
         seasons show up at a glance. Cached hourly on the controller
         side; full detail (and the "peak/slowest month" callouts) live
         on the Analytics report. --}}
    <div class="card">
        <div class="demand-head">
            <span class="label">Seasonal Trends</span>
            <a href="{{ route('reports.analytics') }}" class="view-all">View All</a>
        </div>
        @if ($seasonalTrends->isNotEmpty())
            <div class="chart-box"><canvas id="seasonalTrendsChart"></canvas></div>
        @else
            <p style="color:#94a3b8; font-size:13px;">Not enough sales history yet to show seasonal patterns.</p>
        @endif
    </div>
    </div>{{-- end .dash-charts --}}

    <div class="card" style="margin-top:16px;">
        @if ($demandFromHistory ?? false)
            <p style="margin:0 0 8px; font-size:12px; color:#94a3b8;">
                No POS sales recorded yet — showing historical purchase/receiving volume instead.
            </p>
        @endif
        <div class="demand-grid">
            <div>
                <div class="demand-head">
                    <span class="label">High Demand Product</span>
                    {{-- Analytics, not the reports hub: this panel charts the
                         same top sellers ReportController::analytics() ranks
                         ($topProducts), so "View All" should open that list
                         rather than a menu of three report types. --}}
                    <a href="{{ route('reports.analytics') }}" class="view-all">View All</a>
                </div>
                @if ($topProducts->isNotEmpty())
                    <div class="chart-box" style="height:220px;"><canvas id="highDemandChart"></canvas></div>
                @else
                    <p style="color:#94a3b8; font-size:13px;">No sales yet this period.</p>
                @endif
            </div>
            <div>
                <div class="demand-head">
                    <span class="label">Low Demand Product</span>
                    {{-- Same page: analytics carries $slowMoving, which is
                         exactly what this chart is a preview of. --}}
                    <a href="{{ route('reports.analytics') }}" class="view-all">View All</a>
                </div>
                @if ($lowDemandProducts->isNotEmpty())
                    <div class="chart-box" style="height:220px;"><canvas id="lowDemandChart"></canvas></div>
                @else
                    <p style="color:#94a3b8; font-size:13px;">No sales yet this period.</p>
                @endif
            </div>
        </div>
    </div>

    @include('admin._dashboard-sales-lower')
</div>

{{-- ===================== INVENTORY ===================== --}}
<div class="dash-section inventory" id="dashSection-inventory">
    <div class="dash-section-head">
        <div>
            <p class="dash-section-title">Inventory</p>
            <p class="dash-section-sub">Stock levels, expiry and returns</p>
        </div>
    </div>

    {{-- Categorized inventory breakdown: current stock split out by
         product category (see DashboardController::index — derived from
         the same active-batches collection used elsewhere on this page,
         so it costs no extra queries). Doughnut share on the left, a
         sortable-by-stock legend with per-category product counts on the
         right, so the categories with the most stock naturally read top
         to bottom. --}}
    @php
        $categoryColors = ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626', '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'];
        $categoryProductTotal = $categoryBreakdown->sum('products');
    @endphp
    <div class="inv-top">
    <div class="card">
        <div class="demand-head">
            <span class="label">Inventory Overview</span>
            <a href="{{ route('reports.inventory') }}" class="view-all">View All</a>
        </div>
        @if ($categoryBreakdown->isNotEmpty())
            <div class="category-chart-grid">
                {{-- Total sits in the ring's hole, drawn by the centreText
                     plugin below -- a doughnut with a caption in the middle
                     answers "how big is the catalogue" without a second panel. --}}
                <div class="chart-box" style="height:230px; width:230px; margin:0 auto;">
                    <canvas id="categoryBreakdownChart"></canvas>
                </div>
                <ul class="expiry-list" style="margin:0;">
                    @foreach ($categoryBreakdown as $i => $cat)
                        <li>
                            {{-- The row's own category in Inventory: the same
                                 category_id filter the sidebar's sub-links use,
                                 so "Analgesics — 1,240 units" opens those
                                 products instead of the whole catalogue. --}}
                            <a href="{{ route('inventory.index', ['category_id' => $cat['id']]) }}" class="expiry-row">
                                <div class="expiry-info">
                                    <span class="expiry-name">
                                        <span class="category-swatch" style="background:{{ $categoryColors[$i % count($categoryColors)] }};"></span>{{ $cat['name'] }}
                                    </span>
                                    <span class="expiry-batch">{{ number_format($cat['stock']) }} units in stock</span>
                                </div>
                                @php $catShare = $categoryProductTotal > 0 ? ($cat['products'] / $categoryProductTotal) * 100 : 0; @endphp
                                <span class="expiry-badge" style="background:#ecfdf5; color:#047857;">
                                    {{ number_format($cat['products']) }} <small style="opacity:.75;">({{ number_format($catShare, 1) }}%)</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="expiry-empty" style="color:#94a3b8;">No categorized stock yet.</p>
        @endif
    </div>

    <div class="inv-side">

    {{-- Expiry Overview: the same batches the KPI row counts, split across the
         three horizons the app already works in. A stacked bar rather than a
         fourth doughnut -- proportions of one whole read better on a bar, and
         the page already has two rings. --}}
    <div class="card" style="margin-top:16px;">
        <div class="demand-head">
            <span class="label">Expiry Overview</span>
            <a href="{{ \App\Models\ProductBatch::expiringSoonUrl() }}" class="view-all">View All</a>
        </div>

        @php $expTotal = array_sum($expiryOverview); @endphp

        @if($expTotal > 0)
            <div class="expiry-bar">
                @foreach ([
                    ['key' => 'expired', 'label' => 'Expired',            'colour' => '#dc2626'],
                    ['key' => 'soon',    'label' => 'Expiring (<= 30d)',  'colour' => '#f97316'],
                    ['key' => 'mid',     'label' => 'Expiring (31-60d)',  'colour' => '#eab308'],
                    ['key' => 'good',    'label' => 'Good (> 60d)',       'colour' => '#10b981'],
                ] as $seg)
                    @php $pct = $expTotal > 0 ? ($expiryOverview[$seg['key']] / $expTotal) * 100 : 0; @endphp
                    @if($pct > 0)
                        <span class="expiry-bar-seg"
                              style="width: {{ $pct }}%; background: {{ $seg['colour'] }};"
                              title="{{ $seg['label'] }}: {{ number_format($expiryOverview[$seg['key']]) }} batches"></span>
                    @endif
                @endforeach
            </div>

            <div class="expiry-legend">
                @foreach ([
                    ['key' => 'expired', 'label' => 'Expired',           'colour' => '#dc2626'],
                    ['key' => 'soon',    'label' => 'Expiring (<= 30d)', 'colour' => '#f97316'],
                    ['key' => 'mid',     'label' => 'Expiring (31-60d)', 'colour' => '#eab308'],
                    ['key' => 'good',    'label' => 'Good (> 60d)',      'colour' => '#10b981'],
                ] as $seg)
                    <span class="expiry-legend-item">
                        <span class="dot" style="background: {{ $seg['colour'] }};"></span>
                        {{ $seg['label'] }}
                        <strong>{{ number_format($expiryOverview[$seg['key']]) }}</strong>
                        <small>({{ number_format(($expiryOverview[$seg['key']] / $expTotal) * 100, 1) }}%)</small>
                    </span>
                @endforeach
            </div>

            <p style="margin:12px 0 0; font-size:12px; color:#94a3b8;">
                {{ number_format($expTotal) }} in-stock batches carry an expiry date.
            </p>
        @else
            <p class="expiry-empty" style="color:#94a3b8;">No in-stock batches carry an expiry date.</p>
        @endif
    </div>

    {{-- Lowest Stock vs. Reorder Level now has this column to itself.

         It used to share an .inv-pair row with a "Stock Status" card: a
         doughnut of the same four buckets, with the same counts and the same
         percentages, as the Expiry Overview panel directly above it -- the same
         numbers rendered twice, 200px apart. Worse, its ring never rendered at
         all: the card was ~266px wide, its legend track had a 200px floor, and
         the ring track therefore computed to 0px. Every screen was showing an
         empty 250px box beside a duplicate legend.

         Removing it costs no information (Expiry Overview carries the counts,
         the shares AND a link) and buys this chart the full column width, which
         a bar chart of product names actually uses. --}}
    <div class="card">
        <div class="panel-head">
            <p class="panel-title">Lowest Stock vs. Reorder Level</p>
            {{-- Same rule and the same count as the Low Stock KPI above
                 (is_running_out) -- the chart itself is capped to the 10
                 most critical items so it stays readable, this is where the
                 rest of $lowStockCount live, stock alongside reorder level. --}}
            <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}" class="view-all">View All</a>
        </div>
        <div class="chart-box" style="height:250px;"><canvas id="lowestStockChart"></canvas></div>
    </div>
    </div>{{-- end .inv-side --}}
    </div>{{-- end .inv-top --}}

    {{-- Expiring Soon, split by category and ordered by expiry date.
         Medicine/Pharmaceutical is governed by the supplier-return window and
         is regulated stock; everything else is judged on expiry alone. Mixing
         them in one list hid which of the two rules applied to a given row. --}}
    @php
        $expiringByKind = $expiringSoonBatches
            ->sortBy('expiry_date')
            ->groupBy(fn ($b) => ($b->product && $b->product->is_medicine) ? 'medicine' : 'other');
        $expiringMedicine = $expiringByKind->get('medicine', collect());
        $expiringOther = $expiringByKind->get('other', collect());
    @endphp

    <div class="inv-bottom" style="margin-top:16px;">
        @foreach ([
            ['title' => 'Expiring Soon &middot; Medicine / Pharmaceutical', 'rows' => $expiringMedicine,
             'note' => 'supplier-return window applies', 'empty' => 'No medicines expiring within 30 days.'],
            ['title' => 'Expiring Soon &middot; Other Categories', 'rows' => $expiringOther,
             'note' => 'judged on expiry date only', 'empty' => 'Nothing else expiring within 30 days.'],
        ] as $group)
            <div class="card">
                <div class="demand-head">
                    <span class="label">{!! $group['title'] !!}</span>
                    <a href="{{ \App\Models\ProductBatch::expiringSoonUrl() }}" class="view-all">View All</a>
                </div>
                {{-- Below the title, not inside the header — see .panel-note in
                     layouts/app.blade.php for why. --}}
                <p class="panel-note">
                    {{ number_format($group['rows']->count()) }} {{ Str::plural('batch', $group['rows']->count()) }} &middot; {{ $group['note'] }}
                </p>
                @if ($group['rows']->isNotEmpty())
                    <div class="expiry-scroll">
                    <ul class="expiry-list">
                        @foreach ($group['rows'] as $batch)
                            @php
                                $daysLeft = $batch->days_to_expiry;
                                $sev = $batch->expiry_severity ?? 'watch';
                            @endphp
                            @php
                                // The same deep link the notification bell
                                // builds (AlertService::$itemHref): search by
                                // SKU and jump to the row, rather than
                                // filter=expiring, which can stop matching as
                                // stock moves and would then open a list
                                // without the product just clicked. Falls back
                                // to the card's own filter when the batch has
                                // no product to point at.
                                $rowHref = $batch->product
                                    ? route('inventory.index', ['search' => $batch->product->sku]).'#product-row-'.$batch->product->id
                                    : \App\Models\ProductBatch::expiringSoonUrl();
                            @endphp
                            <li>
                                <a href="{{ $rowHref }}" class="expiry-row">
                                    <div class="expiry-info">
                                        <span class="expiry-name">{{ $batch->product->name ?? 'Unknown' }}</span>
                                        {{-- Date and batch share the sub-line. As its own no-wrap
                                             column the date claimed 127px of a 302px row and left
                                             the product name 40px; the badge beside it already
                                             carries the urgency, so the exact date is reference,
                                             not headline. Date first because .expiry-batch
                                             ellipsises, and a truncated batch number is a smaller
                                             loss than a missing date. --}}
                                        <span class="expiry-batch">{{ $batch->expiry_date->format('M d, Y') }} &middot; Batch: {{ $batch->batch_number }}</span>
                                    </div>
                                    <span class="expiry-badge badge-expiry-{{ $sev }}">
                                        {{ $batch->expiry_label }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    </div>
                @else
                    <p class="expiry-empty" style="color:#94a3b8;">{{ $group['empty'] }}</p>
                @endif
            </div>
        @endforeach

    {{-- Medicine Returns + Other Product Returns, stacked in the third column
         beside the expiring lists rather than in a row of their own below
         them. Same kind of visualization (donut + legend + list) and meant to
         be compared, but they are short panels: on their own row they left a
         band of empty card, and they sat below the fold. Kept as two SEPARATE
         charts/datasets (see DashboardController::index) so pharma's 90-120
         day supplier window is never conflated with everyone else's plain
         default-expiry rule. --}}
    <div class="inv-returns-col">
        <div class="card">
            <div class="demand-head">
                <span class="label">Medicine Returns</span>
                <a href="{{ route('inventory.index', ['filter' => 'fail_to_return']) }}" class="view-all">View All</a>
            </div>
            @if (array_sum($returnStats))
                {{-- Swatches use the same colors as the status badges
                     elsewhere on this page (blue/green/deep red), so this
                     legend actually matches what "Need to Return" and
                     "Fail to Return" look like everywhere else. --}}
                <div class="returns-grid">
                    <div class="chart-box" style="height:150px; width:150px;">
                        <canvas id="returnStatusChart"></canvas>
                    </div>
                    {{-- Each count is exactly one Inventory filter, so the
                         legend entry is the way into it — the card's "View All"
                         can only lead to one of the three. --}}
                    <div class="returns-legend">
                        <a href="{{ route('inventory.index', ['filter' => 'returned']) }}" class="item">
                            <span class="dot" style="background:#22c55e;"></span>
                            <span class="lbl">Successfully Returned</span>
                            <span class="expiry-badge badge-return-done">{{ $returnStats['returned'] }}</span>
                        </a>
                        <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}" class="item">
                            <span class="dot" style="background:#3b82f6;"></span>
                            <span class="lbl">Need to Return</span>
                            <span class="expiry-badge badge-return-due">{{ $returnStats['need_to_return'] }}</span>
                        </a>
                        <a href="{{ route('inventory.index', ['filter' => 'fail_to_return']) }}" class="item">
                            <span class="dot" style="background:#9f1239;"></span>
                            <span class="lbl">Fail to Return</span>
                            <span class="expiry-badge badge-return-late">{{ $returnStats['fail_to_return'] }}</span>
                        </a>
                    </div>
                </div>

                {{-- Summary panel, not a list. This card sits in a narrow
                     column beside two full expiring lists, so the per-batch
                     rows that used to be here now live one click away behind
                     "View All", which opens Inventory already filtered to
                     exactly these batches. The counts stay on the legend
                     above, so nothing is hidden -- only the batch identities
                     move, and they move somewhere that can actually show
                     them. --}}
                @if ($needToReturnBatches->isNotEmpty())
                    <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}" class="returns-more">
                        {{ $needToReturnBatches->count() }} {{ Str::plural('batch', $needToReturnBatches->count()) }} inside the return window
                    </a>
                @endif
            @else
                <p class="expiry-empty" style="color:#94a3b8;">No medicine batches are due for return review yet.</p>
            @endif
        </div>

        {{-- Separate visualization for everything that ISN'T Medicine/Pharmaceutical.
             No 90-120 day supplier window applies here — this is purely the
             default-expiry rule from Product::getNeedsReturnAttribute() (NOT
             yet expired, and within $product->non_pharma_return_window_days
             of expiring — 30 days for Baby Care / Vitamins & Supplements, 10
             for everything else). Once actually expired it moves to the
             Expired legend item below instead, via getFailedReturnAttribute()
             -- same "Need to Return" vs "Fail to Return" split medicine has,
             just without a days-overdue figure since non-pharma has no
             supplier window to have missed. Kept as its own card/chart,
             positioned next to Medicine Returns for direct comparison. --}}
        <div class="card">
            <div class="demand-head">
                <span class="label">Other Product Returns</span>
                <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}" class="view-all">View All</a>
            </div>
            @if (array_sum($nonPharmaReturnStats))
                <div class="returns-grid">
                    <div class="chart-box" style="height:150px; width:150px;">
                        <canvas id="nonPharmaReturnChart"></canvas>
                    </div>
                    <div class="returns-legend">
                        {{-- Successfully Returned belongs here, not only on the
                             Medicine card: that card filters to is_medicine, so
                             a returned Baby Care batch was counted nowhere on
                             the dashboard while the bell and the Inventory
                             "Returned" filter both showed it. --}}
                        <a href="{{ route('inventory.index', ['filter' => 'returned']) }}" class="item">
                            <span class="dot" style="background:#22c55e;"></span>
                            <span class="lbl">Successfully Returned</span>
                            <span class="expiry-badge badge-return-done">{{ $nonPharmaReturnStats['returned'] }}</span>
                        </a>
                        <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}" class="item">
                            <span class="dot" style="background:#3b82f6;"></span>
                            <span class="lbl">Need to Return</span>
                            <span class="expiry-badge badge-return-due">{{ $nonPharmaReturnStats['need_to_return'] }}</span>
                        </a>
                        <a href="{{ route('inventory.index', ['filter' => 'expired']) }}" class="item">
                            <span class="dot" style="background:#dc2626;"></span>
                            <span class="lbl">Expired</span>
                            <span class="expiry-badge badge-expiry-expired">{{ $nonPharmaReturnStats['expired'] }}</span>
                        </a>
                    </div>
                </div>

                {{-- Summary panel, same reasoning as Medicine Returns above. --}}
                @if ($nonPharmaNeedToReturnBatches->isNotEmpty())
                    <a href="{{ route('inventory.index', ['filter' => 'need_to_return']) }}" class="returns-more">
                        {{ $nonPharmaNeedToReturnBatches->count() }} {{ Str::plural('batch', $nonPharmaNeedToReturnBatches->count()) }} to pull from the shelf
                    </a>
                @endif
            @else
                <p class="expiry-empty" style="color:#94a3b8;">No non-pharma products are due for return review yet.</p>
            @endif
        </div>
    </div>{{-- end .inv-returns-col --}}
    </div>{{-- end .inv-bottom --}}
</div>


<p class="dash-footer">&copy; {{ now()->year }} REMEDI Pharmacy System. All rights reserved.</p>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
    // ── Chart palette ──
    // Defined once here so every chart draws from the same set instead of
    // scattered hex literals. Saturated/vivid rather than muted, but the
    // HUES still carry meaning and must stay that way: green = healthy,
    // amber = worth a look, red = problem, blue/violet = neutral series.
    // ── Palette ──
    // Six hues spaced evenly round the wheel at a matched saturation and
    // lightness, so they complement rather than compete. Cool tones carry the
    // money/neutral figures; a warm amber->orange->rose ramp carries severity.
    //
    // Previously amber (#f59e0b) and yellow (#eab308) sat ~10 degrees apart
    // and read as the same colour on adjacent cards.
    // Emerald now leads: it is the brand colour, so the primary series matches
    // the rest of the chrome. The status hues below are fixed by the badge
    // legend (yellow low stock, orange expiring, red expired, blue need to
    // return, green returned) so a slice and a badge for the same state are
    // never different colours.
    const C = {
        indigo:   '#10b981',   // primary series (brand)
        indigoDp: '#047857',   // its emphasis shade
        sky:      '#3b82f6',   // need to return / neutral cool
        emerald:  '#22c55e',   // returned / good
        yellow:   '#eab308',   // severity: low stock
        orange:   '#f97316',   // severity: expiring soon
        rose:     '#dc2626',   // severity: expired
        violet:   '#8b5cf6',
        slate:    '#e2e8f0',
        // Doughnut slices: maximum separation between neighbours.
        wheel: ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626',
                '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'],
    };

    // Back-compat aliases for existing references.
    C.cyan = C.sky;
    C.amber = C.yellow;
    C.blue = C.indigo;
    C.blueDeep = C.indigoDp;
    C.green = C.emerald;
    C.red = C.rose;

    // Vertical gradient for bars: full colour at the top fading toward the
    // baseline, so bars have some depth instead of reading as flat blocks.
    // Chart.js calls this per-render; chartArea is undefined on the very
    // first pass, hence the solid-colour fallback.
    function barGradient(ctx, color) {
        const area = ctx.chart.chartArea;
        if (!area) return color;
        const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        g.addColorStop(0, color);
        g.addColorStop(1, color + '55'); // 8-digit hex → ~33% alpha at the base
        return g;
    }

    // Monthly sales — a single continuous series over time, so one
    // consistent color reads more accurately than a different hue per
    // bar (which visually implies unrelated categories).
    // Full series, every month. The year filter slices this in the browser
    // rather than re-querying, so switching years is instant.
    const monthlyRows = {!! json_encode(collect($monthlySales)->values()) !!};
    const monthlyLabels = monthlyRows.map(r => r.label);
    const monthlyData = monthlyRows.map(r => r.total);

    // Draws each bar's numeric value directly on the bar (in addition to
    // the hover tooltip) so exact current-stock / demand numbers are
    // visible at a glance instead of requiring a hover.
    /* Is this bar pale enough that white text on it would disappear?
       Relative luminance of a #rgb / #rrggbb fill. Anything that is not a
       plain colour string -- a CanvasGradient, which _chart-gradient builds
       for every bar dataset -- is treated as dark, which every gradient in
       these charts is. */
    function barIsLight(fill) {
        if (typeof fill !== 'string') return false;
        const hex = fill.trim().replace('#', '');
        if (!/^([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) return false;
        const full = hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        return (0.2126 * r + 0.7152 * g + 0.0722 * b) > 150;
    }

    const valueLabelPlugin = {
        id: 'valueLabelPlugin',
        afterDatasetsDraw(chart) {
            const { ctx, chartArea } = chart;
            const horizontal = chart.options.indexAxis === 'y';

            chart.data.datasets.forEach((dataset, dsIndex) => {
                const meta = chart.getDatasetMeta(dsIndex);
                if (meta.hidden) return;

                meta.data.forEach((bar, index) => {
                    const raw = dataset.data[index];
                    if (raw === null || raw === undefined) return;

                    // Thousands separators: bare "94420" is hard to read at
                    // 10px next to a bar.
                    const text = Number(raw).toLocaleString();

                    ctx.save();
                    ctx.font = '10px sans-serif';
                    ctx.textBaseline = 'middle';

                    if (horizontal) {
                        const width = ctx.measureText(text).width;
                        // Preferred spot is just past the bar's end.
                        //
                        // Measured against the CANVAS, not chartArea.right.
                        // chartArea.right is the plot edge, but
                        // layout.padding.right reserves canvas beyond it
                        // precisely so these labels have somewhere to go --
                        // measuring against the plot edge threw that room away
                        // and sent the longest bar's number into the branch
                        // below.
                        if (bar.x + 4 + width <= chart.width - 2) {
                            ctx.fillStyle = '#334155';
                            ctx.textAlign = 'left';
                            ctx.fillText(text, bar.x + 4, bar.y);
                        } else {
                            // Inside the bar, and the colour has to come FROM
                            // the bar. This was hardcoded white, which is
                            // invisible on the pale slate (#e2e8f0) the
                            // "Reorder level" dataset uses -- so the longest
                            // bar on Lowest Stock vs. Reorder Level, the one
                            // most worth reading, was drawn in white on
                            // near-white and simply could not be seen.
                            ctx.fillStyle = barIsLight(bar.options && bar.options.backgroundColor)
                                ? '#334155'
                                : '#fff';
                            ctx.textAlign = 'right';
                            ctx.fillText(text, bar.x - 5, bar.y);
                        }
                    } else {
                        ctx.fillStyle = '#334155';
                        ctx.textAlign = 'center';
                        ctx.fillText(text, bar.x, bar.y - 6);
                    }

                    ctx.restore();
                });
            });
        },
    };

    const monthlySalesChart = new Chart(document.getElementById('monthlySalesChart'), {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Total sales',
                data: monthlyData,
                backgroundColor: (ctx) => barGradient(ctx, ctx.dataIndex === ctx.chart.data.labels.length - 1 ? C.blueDeep : C.blue),
                borderRadius: 4,
                maxBarThickness: 46,
                order: 1,   // drawn first, so the trend line sits on top
            },
                // Trend line over the bars. Same series, drawn as a line so
                // the shape of the movement reads across the whole range --
                // bar heights are easy to compare pairwise and hard to read
                // as a direction. `order` puts the line in front of the
                // bars; Chart.js draws higher `order` first.
                {
                    type: 'line',
                    label: 'Trend',
                    data: monthlyData,
                    // C.indigoDp is the emphasis shade of the bar colour
                    // (C.blue === C.indigo === the brand emerald), so the line
                    // reads as the same series stated more firmly rather than
                    // a second, unrelated one. It was #1d4ed8 -- a blue line
                    // over emerald bars, which looked like two datasets.
                    borderColor: C.indigoDp,
                    backgroundColor: C.indigoDp,
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: C.indigoDp,
                    fill: false,
                    order: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,   // .chart-box supplies the height
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString() } },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#94a3b8', font: { size: 11 }, callback: (v) => '₱' + v.toLocaleString() },
                    grid: { color: '#f1f5f9' },
                },
                x: {
                    ticks: { color: '#334155', font: { size: 12, weight: '600' } },
                    grid: { display: false },
                },
            },
        },
    });

    // ── Year filter for the monthly sales chart ──
    (function () {
        const select = document.getElementById('monthlyYearFilter');
        const summary = document.getElementById('monthlyYearSummary');
        const peso = (n) => '₱' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function applyYear(year) {
            const rows = year === 'all'
                ? monthlyRows
                : monthlyRows.filter(r => r.ym.slice(0, 4) === year);

            // With a single year selected the "2025" half of every label is
            // redundant, so show just the month.
            const labels = rows.map(r => year === 'all' ? r.label : r.label.split(' ')[0]);
            const data = rows.map(r => r.total);

            monthlySalesChart.data.labels = labels;
            // EVERY dataset, not just the bars. The trend line is a second
            // dataset over the same series, so updating only datasets[0] left
            // it holding the previous year's numbers -- the line visibly did
            // not touch the bar tops (Apr's point sat at ~P1.88M above a
            // ~P1.58M bar). Loop, so adding another dataset cannot reintroduce
            // this.
            monthlySalesChart.data.datasets.forEach(ds => { ds.data = data; });
            // backgroundColor is deliberately NOT reassigned here. It's a
            // callback that derives the gradient (and the darker "most recent
            // bar" cue) from the live label count, so it follows the filter on
            // its own. Overwriting it with a plain array silently replaced the
            // gradient with flat colour on first render.
            monthlySalesChart.update();

            if (summary) {
                const total = data.reduce((sum, v) => sum + Number(v), 0);
                const scope = year === 'all' ? 'all years' : year;
                summary.textContent = rows.length
                    ? `${rows.length} month${rows.length === 1 ? '' : 's'} · ${scope} · total ${peso(total)}`
                    : `No sales recorded for ${scope}.`;
            }
        }

        applyYear(select ? select.value : 'all');
        if (select) select.addEventListener('change', () => applyYear(select.value));
    })();

    // Seasonal Trends — average sales per calendar month, all-time.
    // Seasonal Trends — one LINE per year over Jan..Dec, so the same month is
    // read across years and a recurring season shows as the curves rising
    // together. It was a single bar series of all-time averages, which
    // flattened exactly the year-on-year comparison this panel is for.
    if (document.getElementById('seasonalTrendsChart')) {
        const seasonalSeries = @json($seasonalByYear);

        // Newest year solid and darkest; older years step lighter and dashed,
        // so the current year reads first.
        const seasonalTones = ['#047857', '#10b981', '#6ee7b7', '#a7f3d0'];

        const datasets = seasonalSeries.map((series, i) => ({
            label: series.year,
            data: series.months,
            borderColor: seasonalTones[i] || '#cbd5e1',
            backgroundColor: 'transparent',
            borderWidth: i === 0 ? 2.5 : 2,
            borderDash: i === 0 ? [] : [5, 4],
            pointRadius: 2.5,
            pointHoverRadius: 4,
            pointBackgroundColor: seasonalTones[i] || '#cbd5e1',
            tension: 0.35,          // gentle curve, matching the reference
            spanGaps: true,         // a part-finished year stops, not drops to 0
        }));

        new Chart(document.getElementById('seasonalTrendsChart'), {
            type: 'line',
            data: {
                labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,   // .chart-box supplies the height
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'start',
                        labels: { boxWidth: 22, boxHeight: 2, font: { size: 11 }, padding: 12, usePointStyle: false },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.dataset.label + ': ₱' + Number(ctx.parsed.y).toLocaleString(undefined, { maximumFractionDigits: 0 }),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8', font: { size: 10 }, callback: (v) => '₱' + v.toLocaleString() },
                        grid: { color: '#f1f5f9' },
                    },
                    x: {
                        ticks: { color: '#334155', font: { size: 10, weight: '600' } },
                        grid: { display: false },
                    },
                },
            },
        });
    }

    // Inventory by Category — doughnut share of total in-stock quantity
    // per product category. Colors match the swatches in the legend list
    // rendered server-side above (see $categoryColors in the Blade).
    // Caption drawn in a doughnut's hole. Registered per-chart (not globally)
    // so only the rings that ask for it get one.
    const centreText = {
        id: 'centreText',
        afterDraw(chart, args, opts) {
            if (!opts || !opts.value) return;
            const { ctx, chartArea } = chart;
            if (!chartArea) return;

            const x = (chartArea.left + chartArea.right) / 2;
            const y = (chartArea.top + chartArea.bottom) / 2;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.font = '600 10px Inter, system-ui, sans-serif';
            ctx.fillStyle = '#94a3b8';
            ctx.fillText(String(opts.label || '').toUpperCase(), x, y - 13);

            ctx.font = '700 21px Outfit, system-ui, sans-serif';
            ctx.fillStyle = '#1e293b';
            ctx.fillText(opts.value, x, y + 8);

            ctx.restore();
        },
    };

    if (document.getElementById('categoryBreakdownChart')) {
        const categoryLabels = {!! json_encode($categoryBreakdown->pluck('name')) !!};
        const categoryData = {!! json_encode($categoryBreakdown->pluck('products')) !!};
        const categoryUnits = {!! json_encode($categoryBreakdown->pluck('stock')) !!};
        const categoryTotal = categoryData.reduce((a, b) => a + b, 0);
        const categoryColors = {!! json_encode($categoryColors) !!};

        new Chart(document.getElementById('categoryBreakdownChart'), {
            type: 'doughnut',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryData,
                    backgroundColor: categoryLabels.map((_, i) => categoryColors[i % categoryColors.length]),
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    centreText: { label: 'Total products', value: categoryTotal.toLocaleString() },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const pct = categoryTotal > 0 ? ((ctx.parsed / categoryTotal) * 100).toFixed(1) : '0.0';
                                return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} products (${pct}%)`
                                    + ` \u00b7 ${Number(categoryUnits[ctx.dataIndex]).toLocaleString()} units`;
                            },
                        },
                    },
                },
            },
            plugins: [centreText],
        });
    }


    // Lowest stock, horizontal grouped bars: current stock vs. each
    // product's own reorder line, so you can see exactly how far under
    // the threshold each item is. Capped to the 10 most critical items.
    const lowStockLabels = {!! json_encode($lowestStockChart->pluck('name')) !!};
    const lowStockData = {!! json_encode($lowestStockChart->pluck('total_stock')) !!};
    const reorderLevelData = {!! json_encode($lowestStockChart->pluck('reorder_level')) !!};

    // Amber = current stock, grey = the reorder line it is judged against.
    new Chart(document.getElementById('lowestStockChart'), {
        type: 'bar',
        data: {
            labels: lowStockLabels,
            datasets: [
                {
                    label: 'Current stock',
                    data: lowStockData,
                    backgroundColor: (ctx) => barGradient(ctx, C.yellow),
                    borderRadius: 4,
                    maxBarThickness: 14,
                },
                {
                    label: 'Reorder level',
                    data: reorderLevelData,
                    backgroundColor: C.slate,
                    borderRadius: 4,
                    maxBarThickness: 14,
                },
            ],
        },
        options: {
            indexAxis: 'y',
            // Reserve room on the right for the value labels the
            // valueLabelPlugin draws past each bar's end, so the
            // longest bar's number isn't pushed against the edge.
            layout: { padding: { right: 38 } },
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { boxWidth: 10, font: { size: 11 }, color: '#64748b' },
                },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x}` } },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { color: '#94a3b8', font: { size: 11 } },
                    grid: { color: '#f1f5f9' },
                },
                y: {
                    ticks: { color: '#334155', font: { size: 12 } },
                    grid: { display: false },
                },
            },
        },
        plugins: [valueLabelPlugin],
    });

    // High Demand Product chart — top movers (real sales, or historical
    // receiving volume as a fallback — see demandFromHistory above).
    if (document.getElementById('highDemandChart')) {
        const highDemandLabels = {!! json_encode($topProducts->map(fn ($item) => $item->name ?? $item->product->name ?? 'Unknown')) !!};
        const highDemandData = {!! json_encode($topProducts->pluck('total_qty')) !!};

        new Chart(document.getElementById('highDemandChart'), {
            type: 'bar',
            data: {
                labels: highDemandLabels,
                datasets: [{
                    label: 'Units',
                    data: highDemandData,
                    backgroundColor: (ctx) => barGradient(ctx, C.blue),
                    borderRadius: 4,
                    maxBarThickness: 16,
                }],
            },
            options: {
                indexAxis: 'y',
                // Reserve room on the right for the value labels the
                // valueLabelPlugin draws past each bar's end, so the
                // longest bar's number isn't pushed against the edge.
                layout: { padding: { right: 38 } },
                responsive: true,
                maintainAspectRatio: false,   // .chart-box supplies the height
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.x} units` } },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: '#f1f5f9' } },
                    y: { ticks: { color: '#334155', font: { size: 11 } }, grid: { display: false } },
                },
            },
            plugins: [valueLabelPlugin],
        });
    }

    // Low Demand Product chart — same data source, slowest movers.
    if (document.getElementById('lowDemandChart')) {
        const lowDemandLabels = {!! json_encode($lowDemandProducts->map(fn ($item) => $item->name ?? $item->product->name ?? 'Unknown')) !!};
        const lowDemandData = {!! json_encode($lowDemandProducts->pluck('total_qty')) !!};

        // Headroom on the axis. Slow movers are frequently ALL the same tiny
        // number -- five products that sold 1 unit each -- and Chart.js scales
        // the axis to the data max, so every bar rendered pinned to 100% and
        // the panel looked like five best-sellers. Force room above the
        // largest value so a small number looks like a small number.
        const lowDemandMax = Math.max(2, Math.ceil(Math.max(...lowDemandData, 1) * 1.4));

        new Chart(document.getElementById('lowDemandChart'), {
            type: 'bar',
            data: {
                labels: lowDemandLabels,
                datasets: [{
                    label: 'Units',
                    data: lowDemandData,
                    backgroundColor: (ctx) => barGradient(ctx, C.amber),
                    borderRadius: 4,
                    maxBarThickness: 16,
                }],
            },
            options: {
                indexAxis: 'y',
                // Reserve room on the right for the value labels the
                // valueLabelPlugin draws past each bar's end, so the
                // longest bar's number isn't pushed against the edge.
                layout: { padding: { right: 38 } },
                responsive: true,
                maintainAspectRatio: false,   // .chart-box supplies the height
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.x} units` } },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        suggestedMax: lowDemandMax,
                        ticks: { color: '#94a3b8', font: { size: 10 }, precision: 0 },
                        grid: { color: '#f1f5f9' },
                    },
                    y: { ticks: { color: '#334155', font: { size: 11 } }, grid: { display: false } },
                },
            },
            plugins: [valueLabelPlugin],
        });
    }

    // Medicine Returns — Successfully Returned vs. pending (Need to Return)
    // vs. missed (Fail to Return). See ProductBatch::getReturnStatusAttribute().
    if (document.getElementById('returnStatusChart')) {
        const returnLabels = ['Successfully Returned', 'Need to Return', 'Fail to Return'];
        const returnData = [{{ $returnStats['returned'] }}, {{ $returnStats['need_to_return'] }}, {{ $returnStats['fail_to_return'] }}];
        // Successfully Returned / Need to Return / Fail to Return
        // Returned / Need to Return / Fail to Return, per the status legend but
        // at readable tints -- the previous maroon swamped the whole ring.
        const returnColors = ['#34d399', '#60a5fa', '#fb7185'];

        new Chart(document.getElementById('returnStatusChart'), {
            type: 'doughnut',
            data: {
                labels: returnLabels,
                datasets: [{
                    data: returnData,
                    backgroundColor: returnColors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed}` } },
                },
            },
        });
    }

    // Other Product Returns (non-pharma) — a SEPARATE chart from Medicine
    // Returns above, since this category has no 90-120 day supplier window
    // and is judged purely on default expiry date. See
    // Product::getNeedsReturnAttribute() / Product::getNonPharmaReturnWindowDaysAttribute().
    if (document.getElementById('nonPharmaReturnChart')) {
        // Successfully Returned leads, matching the Medicine ring's order and
        // the legend beside it. Without it a returned non-pharma batch was
        // charted nowhere -- see DashboardController's $nonPharmaReturnStats.
        const npLabels = ['Successfully Returned', 'Need to Return', 'Expired'];
        const npData = [
            {{ $nonPharmaReturnStats['returned'] }},
            {{ $nonPharmaReturnStats['need_to_return'] }},
            {{ $nonPharmaReturnStats['expired'] }},
        ];
        const npColors = ['#22c55e', '#60a5fa', '#fb7185'];

        new Chart(document.getElementById('nonPharmaReturnChart'), {
            type: 'doughnut',
            data: {
                labels: npLabels,
                datasets: [{
                    data: npData,
                    backgroundColor: npColors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed}` } },
                },
            },
        });
    }

    // Sales / Inventory tab switcher
    // ── Sales Summary ring: revenue by quarter ──
    @if(($quarterSummary['total'] ?? 0) > 0)
    (function () {
        const el = document.getElementById('quarterChart');
        if (!el) return;

        const data = @json($quarterSummary['quarters']);
        const total = data.reduce((a, b) => a + b, 0);

        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                datasets: [{
                    data: data,
                    backgroundColor: ['#059669', '#10b981', '#34d399', '#a7f3d0'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,   // .chart-box supplies the height
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 9,
                            boxHeight: 9,
                            font: { size: 11 },
                            padding: 8,
                            // Quarter + share: four unlabelled swatches told you
                            // nothing about how the year actually split.
                            generateLabels: (chart) => chart.data.labels.map((label, i) => {
                                const v = chart.data.datasets[0].data[i];
                                const pct = total > 0 ? ((v / total) * 100).toFixed(0) : 0;
                                return {
                                    text: label + '  ' + pct + '%',
                                    fillStyle: chart.data.datasets[0].backgroundColor[i],
                                    strokeStyle: chart.data.datasets[0].backgroundColor[i],
                                    lineWidth: 0,
                                    index: i,
                                };
                            }),
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: (c) => {
                                const pct = total > 0 ? ((c.parsed / total) * 100).toFixed(1) : '0.0';
                                return ' ' + c.label + ': ₱' + (c.parsed / 1000000).toFixed(2) + 'M (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    })();
    @endif

    // ── Live clock in the greeting bar ──
    // Rendered server-side first so it is correct without JS; this only keeps
    // it from drifting on a page left open at the till.
    (function () {
        const el = document.getElementById('dashClock');
        if (!el) return;

        setInterval(function () {
            const now = new Date();
            const date = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            const time = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            el.textContent = date + ' · ' + time;
        }, 30000);
    })();

    function showDashSection(target) {
        // Switching tabs swaps one section for another of a completely
        // different height. When the incoming one is shorter the page can no
        // longer scroll as far, the browser clamps the offset, and you are
        // thrown back toward the top of the page -- having just clicked a
        // control that is itself part way down it. Put the offset back
        // afterwards; the browser still clamps, but only as far as it must.
        const restoreScroll = REMEDI.holdScroll();

        document.querySelectorAll('.dash-section').forEach((el) => {
            el.classList.toggle('is-active', el.classList.contains(target));
        });
        document.querySelectorAll('.dash-tab').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.target === target);
        });

        restoreScroll();

        // Charts inside a hidden tab render at 0 size; force them to
        // recalculate once their container becomes visible again.
        requestAnimationFrame(() => {
            window.dispatchEvent(new Event('resize'));
            // Chart.js resizing can change the section's height again, so the
            // clamp can bite a second time on the same click.
            restoreScroll();
        });
    }
</script>
