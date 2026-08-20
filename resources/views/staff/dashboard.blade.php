{{-- resources/views/staff/dashboard.blade.php --}}
@extends('layouts.app')

@section('title', 'Staff Dashboard')

@section('content')
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
    .staff-inv-top {
        display: grid;
        grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
        gap: 16px;
        align-items: start;
    }

    .staff-inv-side { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

    .staff-inv-pair {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
        gap: 16px;
        align-items: start;
    }

    @media (max-width: 1200px) {
        .staff-inv-top, .staff-inv-pair { grid-template-columns: minmax(0, 1fr); }
    }

    /* minmax() floor, not a bare 1fr -- see the same note on the admin
       dashboard's .stock-status-grid. In the narrow half of .staff-inv-pair an
       fr track crushes the labels until "Good (> 60d)" wraps mid-parenthesis. */
    /* The ring is allowed to shrink; the legend is not. .lbl is no-wrap (see
       below), so a 160px floor was too small for "Expiring (31-60d)" plus its
       count and percentage -- the row spilled past the card once the track
       itself could shrink. 200px is what the longest row measures. */
    .staff-stock-status-grid {
        display: grid;
        grid-template-columns: minmax(0, 250px) minmax(200px, 1fr);
        gap: 16px;
        align-items: center;
    }

    @media (max-width: 520px) {
        .staff-stock-status-grid { grid-template-columns: 1fr; }
    }

    /* 250px, not 190 -- see the admin copy: at 190 this card stepped ~70px
       short of Low Stock beside it. */
    /* width:100% + max-width so the ring letterboxes in a narrow column
       instead of forcing the card wider than the viewport. */
    .staff-stock-status-grid > .chart-box { height: 250px; width: 100%; max-width: 250px; }

    .staff-stock-status-legend {
        display: flex;
        flex-direction: column;
        gap: 9px;
        font-size: 12.5px;
        color: #475569;
    }

    .staff-stock-status-legend .item { display: flex; align-items: center; gap: 7px; }
    .staff-stock-status-legend .dot { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
    .staff-stock-status-legend .lbl { flex: 1; white-space: nowrap; }
    .staff-stock-status-legend strong { color: #1e293b; }
    .staff-stock-status-legend small { color: #94a3b8; }

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

    .panel-label {
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin: 0 0 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

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
    .staff-expiry-batch { font-size: 11.5px; color: #94a3b8; }

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
       .demand-grid. Collapses at the same 500px it does there. */
    .staff-demand-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    @media (max-width: 500px) {
        .staff-demand-grid { grid-template-columns: 1fr; }
    }

    /* Centred, not top-aligned -- see the same note on the admin dashboard's
       .category-chart-grid. The capped legend runs to 400px against a 230px
       ring, and top-aligning dumped all 170px of that as white under the
       doughnut. */
    .staff-category-grid {
        display: grid;
        grid-template-columns: 250px minmax(230px, 1fr);
        gap: 24px;
        align-items: center;
    }

    @media (max-width: 600px) {
        .staff-category-grid { grid-template-columns: 1fr; }
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
<div class="kpi-grid">
    <div class="kpi" style="--kpi-accent:#10b981;">
        <div class="kpi-head">
            <i class="ti ti-cash" aria-hidden="true"></i>
            <span class="kpi-label">Today's Sales</span>
        </div>
        <span class="kpi-value">&#8369;{{ number_format($todaySales, 2) }}</span>
        <span class="kpi-sub">{{ $todayTransactions }} {{ Str::plural('transaction', $todayTransactions) }}</span>
    </div>

    <div class="kpi" style="--kpi-accent:#0d9488;">
        <div class="kpi-head">
            <i class="ti ti-packages" aria-hidden="true"></i>
            <span class="kpi-label">Products</span>
        </div>
        <span class="kpi-value">{{ number_format($totalProducts) }}</span>
        <span class="kpi-sub">in the catalog</span>
    </div>

    <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}"
       class="kpi {{ $sLow === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#eab308;">
        <div class="kpi-head">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            <span class="kpi-label">Low Stock</span>
        </div>
        <span class="kpi-value">{{ number_format($sLow) }}</span>
        <span class="kpi-sub">at or below reorder level</span>
    </a>

    <a href="{{ route('inventory.index', ['filter' => 'expiring']) }}"
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
        ['show' => $sLow > 0, 'cls' => 'is-low', 'icon' => 'ti-alert-triangle',
         'title' => 'Low stock alert', 'body' => $sLow.' products are at or below reorder level',
         'href' => route('inventory.index', ['filter' => 'low_stock'])],
        ['show' => $sExpiring > 0, 'cls' => 'is-expiring', 'icon' => 'ti-clock-exclamation',
         'title' => 'Expiring soon', 'body' => $sExpiring.' batches will expire within 30 days',
         'href' => route('inventory.index', ['filter' => 'expiring'])],
        ['show' => $sExpired > 0, 'cls' => 'is-expired', 'icon' => 'ti-alert-octagon',
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

    {{-- Low stock beside Stock Status, the admin dashboard's .inv-pair. The
         pair exists to fill the column: Inventory Overview on the left is ten
         category rows tall, and Expiry Overview + one chart left a few hundred
         pixels of empty card beside it. --}}
    <div class="staff-inv-pair">
        <div class="card">
            <p class="panel-label"><i class="ti ti-alert-triangle"></i> Low Stock vs. Reorder Level</p>
            @if ($lowestStockChart->isNotEmpty())
                <div class="chart-box" style="height:260px;"><canvas id="staffLowStockChart"></canvas></div>
            @else
                <p class="staff-empty">Nothing is currently below its reorder level.</p>
            @endif
        </div>

        {{-- Stock Status: the same four buckets the Expiry Overview bar above
             shows, as a ring. The bar answers "what share of stock is fine";
             the ring sits beside the counts so a glance gives both. --}}
        <div class="card">
            <p class="panel-label"><i class="ti ti-chart-pie"></i> Stock Status</p>
            @if ($sExpTotal > 0)
                <div class="staff-stock-status-grid">
                    {{-- Sized in the stylesheet, not inline -- see the admin copy. --}}
                    <div class="chart-box">
                        <canvas id="staffStockStatusChart"></canvas>
                    </div>
                    <div class="staff-stock-status-legend">
                        @foreach ([
                            ['k' => 'good',    'l' => 'Good (> 60d)',      'c' => '#10b981'],
                            ['k' => 'mid',     'l' => 'Expiring (31-60d)', 'c' => '#eab308'],
                            ['k' => 'soon',    'l' => 'Expiring (<= 30d)', 'c' => '#f97316'],
                            ['k' => 'expired', 'l' => 'Expired',           'c' => '#dc2626'],
                        ] as $row)
                            <span class="item">
                                <span class="dot" style="background: {{ $row['c'] }};"></span>
                                <span class="lbl">{{ $row['l'] }}</span>
                                <strong>{{ number_format($expiryOverview[$row['k']]) }}</strong>
                                <small>({{ number_format(($expiryOverview[$row['k']] / $sExpTotal) * 100, 1) }}%)</small>
                            </span>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="staff-empty">No dated stock to summarise.</p>
            @endif
        </div>
    </div>
    </div>
</div>

{{-- Expiring soon --}}
<div class="card">
    @php
        $sExpByKind = $expiringSoonBatches->sortBy('expiry_date')
            ->groupBy(fn ($b) => ($b->product && $b->product->is_medicine) ? 'medicine' : 'other');
    @endphp
    <p class="panel-label"><i class="ti ti-clock-exclamation"></i> Expiring Soon
    <span style="font-weight:400; text-transform:none; font-size:11.5px; color:#94a3b8;">&mdash; by expiry date, within 30 days</span>
</p>
    <div class="staff-scroll">
    <p class="panel-label" style="margin-top:2px;">Medicine / Pharmaceutical</p>
    <ul class="staff-expiry-list">
        @forelse ($sExpByKind->get('medicine', collect()) as $batch)
            @php $daysLeft = $batch->days_to_expiry;
                    $sev = $batch->expiry_severity ?? 'watch'; @endphp
            <li>
                <div class="staff-expiry-info">
                    <span class="staff-expiry-name">{{ $batch->product->name ?? 'Unknown' }}</span>
                    <span class="staff-expiry-batch"><strong style="color:#475569;">Expires {{ $batch->expiry_date->format('M d, Y') }}</strong> &middot; Batch {{ $batch->batch_number }}</span>
                </div>
                <span class="staff-expiry-badge badge-expiry-{{ $sev }}">
                    {{ $batch->expiry_label }}
                </span>
            </li>
        @empty
            <li class="staff-empty">No medicines expiring within 30 days.</li>
        @endforelse
    </ul>

    <p class="panel-label" style="margin-top:16px;">Other Categories</p>
    <ul class="staff-expiry-list">
        @forelse ($sExpByKind->get('other', collect()) as $batch)
            @php
                $daysLeft = $batch->days_to_expiry;
                $sev = $batch->expiry_severity ?? 'watch'; @endphp
            <li>
                <div class="staff-expiry-info">
                    <span class="staff-expiry-name">{{ $batch->product->name ?? 'Unknown' }}</span>
                    <span class="staff-expiry-batch"><strong style="color:#475569;">Expires {{ $batch->expiry_date->format('M d, Y') }}</strong> &middot; Batch {{ $batch->batch_number }}</span>
                </div>
                <span class="staff-expiry-badge badge-expiry-{{ $sev }}">
                    {{ $batch->expiry_label }}
                </span>
            </li>
        @empty
            <li class="staff-empty">Nothing else expiring within 30 days.</li>
        @endforelse
    </ul>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
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

                        // Preferred spot is just past the bar's end. The
                        // longest bar reaches the right edge of the plot, so
                        // that would draw the number outside the canvas and
                        // clip it -- exactly the case that loses the biggest
                        // (most interesting) figure. Measure first, and tuck
                        // the label inside the bar when it won't fit.
                        if (bar.x + 4 + width <= chartArea.right) {
                            ctx.fillStyle = '#334155';
                            ctx.textAlign = 'left';
                            ctx.fillText(text, bar.x + 4, bar.y);
                        } else {
                            ctx.fillStyle = '#fff';
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

    // Stock Status -- the four expiry buckets as a ring, beside the counts.
    // Same hues and same centre caption as the admin dashboard's version.
    if (document.getElementById('staffStockStatusChart')) {
        const staffStock = @json($expiryOverview);
        const staffStockData = [staffStock.good, staffStock.mid, staffStock.soon, staffStock.expired];
        const staffStockTotal = staffStockData.reduce((a, b) => a + b, 0);

        new Chart(document.getElementById('staffStockStatusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Good (> 60d)', 'Expiring (31-60d)', 'Expiring (<= 30d)', 'Expired'],
                datasets: [{
                    data: staffStockData,
                    backgroundColor: [C.emerald, C.yellow, C.orange, C.rose],
                    borderColor: '#fff',
                    borderWidth: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    staffCentreText: { label: 'Batches', value: staffStockTotal.toLocaleString() },
                    legend: { display: false },   // the list beside it is the legend
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const pct = staffStockTotal > 0 ? ((ctx.parsed / staffStockTotal) * 100).toFixed(1) : '0.0';
                                return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${pct}%)`;
                            },
                        },
                    },
                },
            },
            plugins: [staffCentreText],
        });
    }

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
</script>

<p class="dash-footer">&copy; {{ now()->year }} REMEDI Pharmacy System. All rights reserved.</p>

@endsection
