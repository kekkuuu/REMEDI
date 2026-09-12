{{-- resources/views/staff/_dashboard-body.blade.php --}}
{{--
    The dashboard's whole body, split out so it can be rendered two ways:
    inline by dashboard.blade.php for the no-JS path, and on its own by
    DashboardController's AJAX branch, which returns it as {html} for the
    shell to inject. Staff view: the cashier-scoped subset.

    Keep the <script> blocks at the BOTTOM of this file. The injector runs
    them in document order after the markup is in the DOM, so anything that
    queries an element it expects to exist must come after that element.
--}}
<style>

    /* Quick actions: one full-width bar directly under the KPI band, where
       the two most common staff jobs are reachable without scrolling.
       Previously a 1.2fr/0.8fr grid whose second column was empty once the
       duplicate "Today's Sales" hero was removed. */
    /* Quick Actions + Alerts, stacked full-width directly under the KPI row --
       the same band, in the same position, as the admin dashboard's
       .dash-actions-row. */
    .staff-actions-row {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 22px;
    }

    /* Inventory Overview ring on the left, the expiry/stock panels stacked
       beside it, matching the admin dashboard's .inv-top / .inv-side. Same
       1200px collapse, so both screens reflow at the same width. */
    /* minmax(0, …) on every track, never a bare fr. These cards hold a 250px
       ring plus a no-wrap legend, so as 0.85fr/1.15fr the right column's
       min-content won and the band ran 164px past the viewport at 1280px. */
    /* The WIDE column goes to Inventory by Category -- ring plus a ten-row
       legend -- not to the side stack, which is a bar and one chart. Reversed,
       the legend was handed 128px and the category names rendered 18px wide
       with their unit counts overflowing the row. Same fix, same reason, as the
       admin dashboard's .inv-top. */
    .staff-inv-top {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
        gap: 16px;
        align-items: start;
    }

    .staff-inv-side { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

    /* .staff-inv-pair is gone with the Stock Status card it existed to pair;
       the side column is a plain flex stack. */
    @media (max-width: 1200px) {
        .staff-inv-top { grid-template-columns: minmax(0, 1fr); }
    }

    .quick-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 14px;
        padding: 16px 20px;
    }

    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .quick-actions .btn { flex: 0 0 auto; }

    @media (max-width: 640px) {
        .quick-card { align-items: stretch; }
        .quick-actions { width: 100%; }
        .quick-actions .btn { flex: 1 1 auto; justify-content: center; }
    }

    .quick-card .staff-label {
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b;
    }

    .quick-card .btn { justify-content: center; }

    /* Panel titles, matching the admin dashboard exactly. .demand-head span.label
       was not styled here at all -- the Inventory band is shared markup, but the
       admin copy of this rule lives in that page's own <style>, so on staff the
       same titles rendered at the browser default (16px, regular weight) while
       every other title on the page was a bold uppercase label. Two dashboards,
       one band, two different-looking headings. */
    .panel-label,
    .demand-head span.label {
        font-size: 12px;
        font-weight: 700;
        color: var(--ink);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin: 0 0 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .demand-head span.label { margin: 0; }

    .panel-label i { color: var(--brand); font-size: 16px; }

    .staff-expiry-list { list-style: none; margin: 0; padding: 0; }

    /* Cap the height, not the row count: the neighbouring card is short, and an
       uncapped list left it sitting beside several hundred pixels of nothing. */
    .staff-scroll {
        max-height: 400px;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding-right: 6px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .staff-scroll::-webkit-scrollbar { width: 8px; }
    .staff-scroll::-webkit-scrollbar-track { background: transparent; }
    .staff-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    /* Alerts across the width so they do not build a tall column beside the
       short Expiry Overview card. */
    .staff-alerts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 10px;
    }

    .staff-alerts-grid .staff-alert { margin-bottom: 0; }

    .staff-expiry-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 9px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13.5px;
    }

    .staff-expiry-list li:last-child { border-bottom: none; }

    .staff-expiry-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .staff-expiry-name { font-weight: 600; color: #1e293b; }
    .staff-expiry-batch { font-size: 11.5px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Date on the info line with the batch number — see the note on the admin
       dashboard's .expiry-batch for why it is not a column of its own. */

    .staff-expiry-badge {
        flex-shrink: 0;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
        white-space: nowrap;
    }

    /* Expiry severity comes from the shared .badge-expiry-* scale in the
       layout, matching the admin dashboard exactly. */

    .staff-empty { color: #94a3b8; font-size: 13.5px; padding: 6px 0; }

    /* High/Low Demand side by side, mirroring the admin dashboard's
       .demand-grid, and collapsing at the same width it does. */
    /* minmax(0, …) + min-width:0, never a bare 1fr: an fr track's implicit
       minimum is min-content, so at a 768px tablet -- where the 216px sidebar
       leaves a 447px grid -- these two tracks still demanded 300px each and
       the Low Demand chart was clipped outside the card. Same fix and same
       reason as the admin dashboard's .demand-grid. */
    .staff-demand-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 20px;
    }

    .staff-demand-grid > * { min-width: 0; }

    /* 900px, not 500px -- the query keys off the viewport while the content
       column is 216px narrower than it. */
    @media (max-width: 900px) {
        .staff-demand-grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* Centred, not top-aligned -- see the same note on the admin dashboard's
       .category-chart-grid. The capped legend runs to 400px against a 230px
       ring, and top-aligning dumped all 170px of that as white under the
       doughnut. */
    /* The ring track shrinks rather than holding a hard 250px: paired with the
       230px floor on the legend it asked for 504px inside a 447px card at
       tablet width and pushed the legend off the edge. */
    /* minmax(0, 1fr) on the legend let it absorb every shortfall down to
       128px. Text does not degrade gracefully; a doughnut does. The ring now
       shrinks first and the legend keeps a floor it can hold a row in. */
    .staff-category-grid {
        display: grid;
        grid-template-columns: minmax(170px, 230px) minmax(230px, 1fr);
        gap: 24px;
        align-items: center;
    }

    @media (max-width: 900px) {
        .staff-category-grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* Same soft elevation as the admin dashboard panels. */
    .staff-actions-row .card,
    .staff-inv-top .card,
    .staff-inv-side .card {
        border-radius: 14px;
        border-color: #eef2f7;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04),
                    0 10px 22px -14px rgba(15, 23, 42, 0.16);
    }

    .staff-category-swatch {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 2px;
        margin-right: 7px;
        flex-shrink: 0;
    }

    /* ── Shared with the admin dashboard ──
       Duplicated rather than imported: each dashboard carries its own <style>
       block, and staff does not load admin/dashboard.blade.php. Keep the two
       in step when either changes. */
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

    /* Quick Actions on THIS screen is a single row -- label on the left, the
       four actions across. The shared .quick-actions rule (copied from the
       admin dashboard) is a 2-column grid and, declared later, was winning;
       scoping to .quick-card restores the row without touching admin. */
    .quick-card .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        flex: 1;
    }

    .quick-card .quick-action { flex: 1 1 168px; justify-content: center; }

    @media (max-width: 640px) {
        .quick-card .quick-action { flex: 1 1 100%; justify-content: flex-start; }
    }

    /* Expiry Overview bar, same treatment as the admin tab. */
    .staff-expiry-bar {
        display: flex;
        height: 14px;
        border-radius: 999px;
        overflow: hidden;
        background: #f1f5f9;
        margin-top: 4px;
    }

    .staff-expiry-bar span { display: block; height: 100%; }

    .staff-expiry-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 18px;
        margin-top: 14px;
        font-size: 12.5px;
        color: #475569;
    }

    .staff-expiry-legend span.item { display: inline-flex; align-items: center; gap: 6px; }
    .staff-expiry-legend .dot { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
    .staff-expiry-legend strong { color: #1e293b; }
    .staff-expiry-legend small { color: #94a3b8; }

    /* Alerts, matching the admin rows. */
    .staff-alert {
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
    }

    .staff-alert:last-child { margin-bottom: 0; }
    .staff-alert:hover { background: #f1f5f9; }
    .staff-alert i { font-size: 17px; margin-top: 1px; }
    .staff-alert span.txt { display: flex; flex-direction: column; gap: 2px; }
    .staff-alert strong { font-size: 13px; }
    .staff-alert small { font-size: 12px; color: #64748b; }

    .staff-alert.is-low { border-left-color: #eab308; }
    .staff-alert.is-low i { color: #ca8a04; }
    .staff-alert.is-expiring { border-left-color: #f97316; }
    .staff-alert.is-expiring i { color: #ea580c; }
    .staff-alert.is-expired { border-left-color: #dc2626; }
    .staff-alert.is-expired i { color: #dc2626; }

    .dash-footer {
        margin: 26px 0 4px;
        text-align: center;
        font-size: 12px;
        color: #94a3b8;
    }
</style>

{{-- Greeting bar, matching the admin dashboard so the two screens read as one
     product. Replaces the eyebrow/title/timestamp stack, which said the same
     thing twice as soon as the date pill arrived. --}}
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

{{-- Today's sales + quick action --}}
{{-- Headline figures, using the same .kpi primitive and palette as the admin
     dashboard so a colour means the same thing on either screen. The stock
     tiles link to the matching Inventory filter. --}}
@php
    $sLow = $lowStockProducts->count();
    $sExpiring = $expiringSoonBatches->count();
    $sExpired = $expiredBatches->count();
@endphp
{{-- Both tiles open the page behind their number, using only routes staff can
     reach: the Sales list (scoped by SaleController to this cashier's own
     checkouts, which is what the "by you" sub-line names) and Inventory, since
     /products is admin-only and a tile that 403s is worse than one that does
     nothing. --}}
<div class="kpi-grid">
    <a href="{{ route('sales.index', ['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]) }}"
       class="kpi" style="--kpi-accent:#10b981;">
        <div class="kpi-head">
            <i class="ti ti-cash" aria-hidden="true"></i>
            <span class="kpi-label">Today's Sales</span>
        </div>
        <span class="kpi-value">&#8369;{{ number_format($todaySales, 2) }}</span>
        {{-- Two sub-lines, terminal total then this user's share of it. Both
             carry a qualifier ("all cashiers" / "by you") because two bare
             counts stacked in one tile read as a contradiction rather than a
             total and a part of it. .kpi-sub is display:block, so they stack
             without any new CSS. --}}
        <span class="kpi-sub">{{ $todayTransactions }} {{ Str::plural('transaction', $todayTransactions) }} &middot; all cashiers</span>
        <span class="kpi-sub">{{ $myTodayTransactions }} by you &middot; &#8369;{{ number_format($myTodaySales, 2) }}</span>
    </a>

    <a href="{{ route('inventory.index') }}" class="kpi" style="--kpi-accent:#0d9488;">
        <div class="kpi-head">
            <i class="ti ti-packages" aria-hidden="true"></i>
            <span class="kpi-label">Products</span>
        </div>
        <span class="kpi-value">{{ number_format($totalProducts) }}</span>
        <span class="kpi-sub">in the catalog</span>
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}"
       class="kpi {{ $sLow === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#eab308;">
        <div class="kpi-head">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            <span class="kpi-label">Low Stock</span>
        </div>
        <span class="kpi-value">{{ number_format($sLow) }}</span>
        <span class="kpi-sub">at or below reorder level</span>
    </a>

    <a href="{{ \App\Models\ProductBatch::expiringSoonUrl() }}"
       class="kpi {{ $sExpiring === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#f97316;">
        <div class="kpi-head">
            <i class="ti ti-clock-exclamation" aria-hidden="true"></i>
            <span class="kpi-label">Expiring Soon</span>
        </div>
        <span class="kpi-value">{{ number_format($sExpiring) }}</span>
        <span class="kpi-sub">within 30 days</span>
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'expired']) }}"
       class="kpi {{ $sExpired === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#dc2626;">
        <div class="kpi-head">
            <i class="ti ti-alert-octagon" aria-hidden="true"></i>
            <span class="kpi-label">Expired</span>
        </div>
        <span class="kpi-value">{{ number_format($sExpired) }}</span>
        <span class="kpi-sub">still on the shelf</span>
    </a>
</div>

{{-- Panel order mirrors the admin dashboard, so the two screens read as one
     product: the "what do I do now" band (Quick Actions, then Alerts) sits
     directly under the KPI row, demand comes next as it does in the admin
     Sales tab, and the stock panels follow in the admin Inventory tab's
     arrangement -- Inventory Overview beside a stacked Expiry Overview +
     Lowest Stock, with Expiring Soon full-width beneath. --}}
@php
    // Same order as the admin dashboard's $categoryColors -- a category must
    // not change colour when you switch accounts.
    $staffCategoryColors = ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626', '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'];
    $staffCategoryProductTotal = $categoryBreakdown->sum('products');
    $sExpTotal = array_sum($expiryOverview);
    $sAlerts = collect([
        ['show' => $sLow > 0, 'cls' => 'is-low', 'icon' => 'ti-alert-triangle', 'kind' => 'low_stock',
         'title' => 'Low stock alert', 'body' => $sLow.' products are at or below reorder level',
         'href' => route('inventory.index', ['filter' => 'low_stock'])],
        ['show' => $sExpiring > 0, 'cls' => 'is-expiring', 'icon' => 'ti-clock-exclamation', 'kind' => 'expiring',
         'title' => 'Expiring soon', 'body' => $sExpiring.' batches will expire within 30 days',
         'href' => \App\Models\ProductBatch::expiringSoonUrl()],
        ['show' => $sExpired > 0, 'cls' => 'is-expired', 'icon' => 'ti-alert-octagon', 'kind' => 'expired',
         'title' => 'Expired stock', 'body' => $sExpired.' expired batches are still in stock',
         'href' => route('inventory.index', ['filter' => 'expired'])],
    ])->where('show', true)->values();
@endphp

{{-- Quick Actions and Alerts are the "what do I do now" band. Stacked
     full-width rows rather than two columns: Quick Actions is inherently one
     bar and Alerts inherently a list, so pairing them as columns left a void
     beside the shorter one. Same reasoning as admin/_dashboard-actions. --}}
<div class="staff-actions-row">
    <div class="card quick-card">
        <span class="staff-label">Quick Actions</span>
        {{-- Same tile treatment as the admin dashboard's Quick Actions. Four
             entries, so the 2-column grid fills evenly; all four are routes a
             staff account can actually reach (no Add Product / Reports). --}}
        <div class="quick-actions">
            <a href="{{ route('pos.index') }}" class="quick-action">
                <i class="ti ti-shopping-cart" aria-hidden="true"></i><span>New Sale</span>
            </a>
            <a href="{{ route('inventory.index') }}" class="quick-action">
                <i class="ti ti-box" aria-hidden="true"></i><span>View Inventory</span>
            </a>
            <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}" class="quick-action">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i><span>Low Stock</span>
            </a>
            <a href="{{ route('sales.index') }}" class="quick-action">
                <i class="ti ti-receipt" aria-hidden="true"></i><span>Sales History</span>
            </a>
        </div>
    </div>

    <div class="card">
        <p class="panel-label"><i class="ti ti-bell"></i> Alerts &amp; Notifications</p>
        <div class="staff-alerts-grid">
        @forelse($sAlerts as $a)
            <a href="{{ $a['href'] }}" class="staff-alert {{ $a['cls'] }}">
                <i class="ti {{ $a['icon'] }}" aria-hidden="true"></i>
                <span class="txt">
                    <strong>{{ $a['title'] }}</strong>
                    <small>{{ $a['body'] }}</small>
                </span>
            </a>
        @empty
            <p class="staff-empty">Nothing needs attention right now.</p>
        @endforelse
        </div>
    </div>
</div>

{{-- Demand, so staff can see what's moving without full reports access.
     Same High/Low pair the admin dashboard carries: $lowDemandProducts is
     already passed to both views by DashboardController, so the second panel
     costs no extra query. Slow movers matter on the floor too -- they are the
     stock that quietly ties up shelf space. --}}
@if ($topProducts->isNotEmpty() || $lowDemandProducts->isNotEmpty())
<div class="card" style="margin-top:16px;">
    @if ($demandFromHistory ?? false)
        <p style="margin:0 0 8px; font-size:12px; color:#94a3b8;">
            No POS sales recorded yet — showing historical purchase/receiving volume instead.
        </p>
    @endif
    <div class="staff-demand-grid">
        <div>
            <p class="panel-label"><i class="ti ti-trending-up"></i> High Demand Product</p>
            @if ($topProducts->isNotEmpty())
                <div class="chart-box" style="height:220px;"><canvas id="staffTopProductsChart"></canvas></div>
            @else
                <p class="staff-empty">No sales yet this period.</p>
            @endif
        </div>
        <div>
            <p class="panel-label"><i class="ti ti-trending-down"></i> Low Demand Product</p>
            @if ($lowDemandProducts->isNotEmpty())
                <div class="chart-box" style="height:220px;"><canvas id="staffLowDemandChart"></canvas></div>
            @else
                <p class="staff-empty">No sales yet this period.</p>
            @endif
        </div>
    </div>
</div>
@endif

{{-- Inventory Overview ring on the left, the expiry/stock panels stacked
     beside it -- the admin dashboard's .inv-top / .inv-side arrangement. --}}
<div class="staff-inv-top">
    <div class="card">
        <p class="panel-label"><i class="ti ti-chart-donut"></i> Inventory Overview</p>
        @if ($categoryBreakdown->isNotEmpty())
        <div class="staff-category-grid">
            <div class="chart-box" style="height:230px; width:230px; margin:0 auto;">
                <canvas id="staffCategoryChart"></canvas>
            </div>
            {{-- Scrolled, not truncated. Ten categories made this card ~690px
                 against a ~475px column beside it, and the leftover was a
                 visible void under Stock Status. Cap the height, keep every
                 row -- the same rule the Expiring Soon panel follows. --}}
            <ul class="staff-expiry-list staff-scroll">
                @foreach ($categoryBreakdown as $i => $cat)
                    <li>
                        {{-- Same destination as the admin dashboard's legend:
                             Inventory filtered to this category, which staff
                             can already reach from the sidebar. --}}
                        <a href="{{ route('inventory.index', ['category_id' => $cat['id']]) }}" class="expiry-row">
                            <div class="staff-expiry-info">
                                <span class="staff-expiry-name">
                                    <span class="staff-category-swatch" style="background:{{ $staffCategoryColors[$i % count($staffCategoryColors)] }};"></span>{{ $cat['name'] }}
                                </span>
                                <span class="staff-expiry-batch">{{ number_format($cat['stock']) }} units in stock</span>
                            </div>
                            @php $sShare = $staffCategoryProductTotal > 0 ? ($cat['products'] / $staffCategoryProductTotal) * 100 : 0; @endphp
                            <span class="staff-expiry-badge" style="background:#ecfdf5; color:#047857;">
                                {{ number_format($cat['products']) }} <small style="opacity:.75;">({{ number_format($sShare, 1) }}%)</small>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        @else
            <p class="staff-empty">No categorized stock yet.</p>
        @endif
    </div>

    <div class="staff-inv-side">
        <div class="card">
            <p class="panel-label"><i class="ti ti-calendar-stats"></i> Expiry Overview</p>
            @if($sExpTotal > 0)
                <div class="staff-expiry-bar">
                    @foreach ([
                        ['k' => 'expired', 'c' => '#dc2626'],
                        ['k' => 'soon',    'c' => '#f97316'],
                        ['k' => 'mid',     'c' => '#eab308'],
                        ['k' => 'good',    'c' => '#10b981'],
                    ] as $seg)
                        @php $pct = ($expiryOverview[$seg['k']] / $sExpTotal) * 100; @endphp
                        @if($pct > 0)
                            <span style="width: {{ $pct }}%; background: {{ $seg['c'] }};"></span>
                        @endif
                    @endforeach
                </div>
                <div class="staff-expiry-legend">
                    @foreach ([
                        ['k' => 'expired', 'l' => 'Expired',           'c' => '#dc2626'],
                        ['k' => 'soon',    'l' => 'Expiring (<= 30d)', 'c' => '#f97316'],
                        ['k' => 'mid',     'l' => 'Expiring (31-60d)', 'c' => '#eab308'],
                        ['k' => 'good',    'l' => 'Good (> 60d)',      'c' => '#10b981'],
                    ] as $seg)
                        <span class="item">
                            <span class="dot" style="background: {{ $seg['c'] }};"></span>
                            {{ $seg['l'] }}
                            <strong>{{ number_format($expiryOverview[$seg['k']]) }}</strong>
                            <small>({{ number_format(($expiryOverview[$seg['k']] / $sExpTotal) * 100, 1) }}%)</small>
                        </span>
                    @endforeach
                </div>
            @else
                <p class="staff-empty">No in-stock batches carry an expiry date.</p>
            @endif
        </div>

    {{-- Low Stock vs. Reorder Level has this column to itself now.

         It used to sit in a .staff-inv-pair beside a "Stock Status" card whose
         doughnut, legend, counts and percentages were the same $expiryOverview
         buckets as the Expiry Overview panel two cards above -- and whose ring
         never rendered: in a ~253px card with a 200px floor on the legend
         track, the chart track computed to 0px, so the card was an empty 250px
         box beside a duplicate legend. Removing it loses no figure and gives
         this chart the width its product labels need. --}}
    <div class="card">
        <p class="panel-label"><i class="ti ti-alert-triangle"></i> Low Stock vs. Reorder Level</p>
        @if ($lowestStockChart->isNotEmpty())
            <div class="chart-box" style="height:260px;"><canvas id="staffLowStockChart"></canvas></div>
        @else
            <p class="staff-empty">Nothing is currently below its reorder level.</p>
        @endif
    </div>
    </div>{{-- end .staff-inv-side --}}
</div>{{-- end .staff-inv-top --}}

{{-- Inventory band, identical in structure to the admin dashboard's: the two
     Expiring Soon lists side by side with the returns column beside them.
     Previously staff had a single stacked Expiring Soon card and no returns
     panels at all. The CSS now lives in layouts/app.blade.php so both pages
     render from one definition rather than two drifting copies. --}}
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
                                // The bell's deep link (AlertService::$itemHref)
                                // — search by SKU and jump to the row, so the
                                // product clicked is the one on screen. Same
                                // markup as the admin dashboard's list; keep
                                // the two in step.
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
                        <canvas id="staffReturnStatusChart"></canvas>
                    </div>
                    {{-- One Inventory filter per count, same as the admin
                         dashboard's legend. All three filters are on the shared
                         Inventory page, so nothing here 403s for staff. --}}
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
                        <canvas id="staffNonPharmaReturnChart"></canvas>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
@include('partials._chart-gradient')
<script>
    // ── Chart palette ──
    // The SAME object the admin dashboard defines. Duplicated rather than
    // imported because each dashboard carries its own <script> block and staff
    // never loads admin/dashboard.blade.php -- keep the two in step (see
    // "Fixes applied to the admin dashboard do NOT reach staff" in REMEDI.md).
    //
    // The HUES carry meaning and must stay that way: emerald = healthy/brand,
    // amber = worth a look, red = problem, blue/violet = neutral series. The
    // status hues are fixed by the badge legend, so a slice and a badge for the
    // same state are never different colours.
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
        // Doughnut slices: maximum separation between neighbours. Same order as
        // $staffCategoryColors above -- a category must not change colour when
        // you switch accounts.
        wheel: ['#10b981', '#3b82f6', '#eab308', '#f97316', '#dc2626',
                '#8b5cf6', '#14b8a6', '#0ea5e9', '#a855f7', '#fb7185'],
    };

    // Back-compat aliases, mirroring the admin dashboard.
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

    // Draws each bar's numeric value directly on the bar (in addition to the
    // hover tooltip) so exact stock / demand numbers are visible at a glance.
    // On a touch screen there is no hover at all, which is most of what the
    // floor uses -- so here the label is the only way to read the figure.
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

    @if ($lowestStockChart->isNotEmpty())
    const staffLowStockLabels = {!! json_encode($lowestStockChart->pluck('name')) !!};
    const staffLowStockCurrent = {!! json_encode($lowestStockChart->pluck('total_stock')) !!};
    const staffLowStockReorder = {!! json_encode($lowestStockChart->pluck('reorder_level')) !!};

    // Amber = current stock, grey = the reorder line it is judged against.
    // These rows are LOW, not expired -- solid red read as an alarm on every
    // one of them. The reorder bar stays flat: it is a reference backdrop, and
    // a gradient on it competes with the data bar in front.
    new Chart(document.getElementById('staffLowStockChart'), {
        type: 'bar',
        data: {
            labels: staffLowStockLabels,
            datasets: [
                { label: 'Current stock', data: staffLowStockCurrent, backgroundColor: (ctx) => barGradient(ctx, C.yellow), borderRadius: 4, maxBarThickness: 14 },
                { label: 'Reorder level', data: staffLowStockReorder, backgroundColor: C.slate, borderRadius: 4, maxBarThickness: 14 },
            ],
        },
        options: {
            indexAxis: 'y',
            // Room on the right for the value labels valueLabelPlugin draws
            // past each bar's end, so the longest bar's number isn't pushed
            // against the edge.
            layout: { padding: { right: 38 } },
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top', align: 'end', labels: { boxWidth: 10, font: { size: 11 }, color: '#64748b' } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x}` } },
            },
            scales: {
                x: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 11 } }, grid: { color: '#f1f5f9' } },
                y: { ticks: { color: '#334155', font: { size: 12 } }, grid: { display: false } },
            },
        },
        plugins: [valueLabelPlugin],
    });
    @endif

    // Caption in the ring's hole -- "TOTAL PRODUCTS 2,635" -- so a donut
    // answers its own headline without a second panel. Mirrors the admin
    // dashboard's centreText plugin.
    //
    // Declared OUTSIDE the category condition below: both rings on this page
    // use it, and the category ring is the conditional one. Leaving it inside
    // meant Stock Status threw a ReferenceError on an install with no
    // categorised stock. (Do not write the directive name in a comment here --
    // Blade compiles it wherever it appears, including inside JS comments.)
    const staffCentreText = {
        id: 'staffCentreText',
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

    @if ($categoryBreakdown->isNotEmpty())
    const staffCategoryLabels = {!! json_encode($categoryBreakdown->pluck('name')) !!};
    const staffCategoryData = {!! json_encode($categoryBreakdown->pluck('products')) !!};
    const staffCategoryUnits = {!! json_encode($categoryBreakdown->pluck('stock')) !!};
    const staffCategoryTotal = staffCategoryData.reduce((a, b) => a + b, 0);
    const staffCategoryColors = {!! json_encode($staffCategoryColors) !!};

    new Chart(document.getElementById('staffCategoryChart'), {
        type: 'doughnut',
        data: {
            labels: staffCategoryLabels,
            datasets: [{
                data: staffCategoryData,
                backgroundColor: staffCategoryLabels.map((_, i) => staffCategoryColors[i % staffCategoryColors.length]),
                borderColor: '#fff',
                borderWidth: 3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                staffCentreText: { label: 'Total products', value: staffCategoryTotal.toLocaleString() },
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const pct = staffCategoryTotal > 0 ? ((ctx.parsed / staffCategoryTotal) * 100).toFixed(1) : '0.0';
                            return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} products (${pct}%)`
                                + ` · ${Number(staffCategoryUnits[ctx.dataIndex]).toLocaleString()} units`;
                        },
                    },
                },
            },
        },
        plugins: [staffCentreText],
    });
    @endif


    // High Demand -- horizontal bars in the brand emerald, matching the admin
    // dashboard's highDemandChart. Horizontal because pharmacy product names
    // are long: as vertical bars they were truncated on the x-axis.
    @if ($topProducts->isNotEmpty())
    const staffTopLabels = {!! json_encode($topProducts->map(fn ($item) => $item->name ?? $item->product->name ?? 'Unknown')) !!};
    const staffTopData = {!! json_encode($topProducts->pluck('total_qty')) !!};

    new Chart(document.getElementById('staffTopProductsChart'), {
        type: 'bar',
        data: {
            labels: staffTopLabels,
            datasets: [{
                label: 'Units',
                data: staffTopData,
                backgroundColor: (ctx) => barGradient(ctx, C.blue),
                borderRadius: 4,
                maxBarThickness: 16,
            }],
        },
        options: {
            indexAxis: 'y',
            layout: { padding: { right: 38 } },
            responsive: true,
            maintainAspectRatio: false,
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
    @endif

    // Low Demand -- same data source, slowest movers. Amber is the deliberate
    // contrast against High Demand's emerald.
    @if ($lowDemandProducts->isNotEmpty())
    const staffLowDemandLabels = {!! json_encode($lowDemandProducts->map(fn ($item) => $item->name ?? $item->product->name ?? 'Unknown')) !!};
    const staffLowDemandData = {!! json_encode($lowDemandProducts->pluck('total_qty')) !!};

    // Headroom on the axis. Slow movers are frequently ALL the same tiny
    // number -- five products that sold 1 unit each -- and Chart.js scales the
    // axis to the data max, so every bar rendered pinned to 100% and the panel
    // looked like five best-sellers. Force room above the largest value so a
    // small number looks like a small number.
    const staffLowDemandMax = Math.max(2, Math.ceil(Math.max(...staffLowDemandData, 1) * 1.4));

    new Chart(document.getElementById('staffLowDemandChart'), {
        type: 'bar',
        data: {
            labels: staffLowDemandLabels,
            datasets: [{
                label: 'Units',
                data: staffLowDemandData,
                backgroundColor: (ctx) => barGradient(ctx, C.amber),
                borderRadius: 4,
                maxBarThickness: 16,
            }],
        },
        options: {
            indexAxis: 'y',
            layout: { padding: { right: 38 } },
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.x} units` } },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    suggestedMax: staffLowDemandMax,
                    ticks: { color: '#94a3b8', font: { size: 10 }, precision: 0 },
                    grid: { color: '#f1f5f9' },
                },
                y: { ticks: { color: '#334155', font: { size: 11 } }, grid: { display: false } },
            },
        },
        plugins: [valueLabelPlugin],
    });
    @endif

    /* ── Returns doughnuts ──
       Same shape and the same colours as the admin dashboard's pair, so a
       slice means the same thing on either screen. Guarded on the canvas
       existing: both cards render nothing when their totals are all zero. */
    if (document.getElementById('staffReturnStatusChart')) {
        new Chart(document.getElementById('staffReturnStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Successfully Returned', 'Need to Return', 'Fail to Return'],
                datasets: [{
                    data: [{{ $returnStats['returned'] }}, {{ $returnStats['need_to_return'] }}, {{ $returnStats['fail_to_return'] }}],
                    backgroundColor: ['#22c55e', '#3b82f6', '#9f1239'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,   // required: see the .chart-box note
                cutout: '62%',
                plugins: { legend: { display: false } },
            },
        });
    }

    if (document.getElementById('staffNonPharmaReturnChart')) {
        new Chart(document.getElementById('staffNonPharmaReturnChart'), {
            type: 'doughnut',
            data: {
                labels: ['Successfully Returned', 'Need to Return', 'Expired'],
                datasets: [{
                    data: [{{ $nonPharmaReturnStats['returned'] }}, {{ $nonPharmaReturnStats['need_to_return'] }}, {{ $nonPharmaReturnStats['expired'] }}],
                    backgroundColor: [C.emerald, '#60a5fa', '#fb7185'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { display: false } },
            },
        });
    }
</script>

<p class="dash-footer">&copy; {{ now()->year }} REMEDI Pharmacy System. All rights reserved.</p>

