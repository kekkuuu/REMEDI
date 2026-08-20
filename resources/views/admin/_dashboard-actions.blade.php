{{-- Quick Actions + Alerts.

     These stay OUTSIDE the tab switcher on purpose: neither is about sales or
     stock specifically, they are the "what do I do now" band, and hiding them
     behind whichever tab happens to be selected would just make them harder to
     reach. The sales-only panels moved into the Sales section instead. --}}
@php
    $alerts = collect([
        ['show' => $lowStockCount > 0, 'cls' => 'is-low', 'icon' => 'ti-alert-triangle',
         'title' => 'Low stock alert', 'body' => $lowStockCount.' products are at or below reorder level',
         'href' => route('inventory.index', ['filter' => 'low_stock'])],
        ['show' => $expiringCount > 0, 'cls' => 'is-expiring', 'icon' => 'ti-clock-exclamation',
         'title' => 'Expiring soon', 'body' => $expiringCount.' batches will expire within 30 days',
         'href' => route('inventory.index', ['filter' => 'expiring'])],
        ['show' => $expiredCount > 0, 'cls' => 'is-expired', 'icon' => 'ti-alert-octagon',
         'title' => 'Expired stock', 'body' => $expiredCount.' expired batches are still in stock',
         'href' => route('inventory.index', ['filter' => 'expired'])],
        ['show' => $needReturnCount > 0, 'cls' => 'is-return', 'icon' => 'ti-package-export',
         'title' => 'Return window open', 'body' => $needReturnCount.' batches can still go back to the supplier',
         'href' => route('inventory.index', ['filter' => 'need_to_return'])],
        ['show' => $returnStats['fail_to_return'] > 0, 'cls' => 'is-missed', 'icon' => 'ti-calendar-x',
         'title' => 'Return window missed', 'body' => $returnStats['fail_to_return'].' batches missed the return window',
         'href' => route('inventory.index', ['filter' => 'fail_to_return'])],
    ])->where('show', true)->values();
@endphp

<div class="dash-actions-row">
    <div class="card actions-bar">
        <span class="label">Quick Actions</span>
        <div class="quick-actions">
            <a href="{{ route('products.create') }}" class="quick-action">
                <i class="ti ti-plus" aria-hidden="true"></i><span>Add Product</span>
            </a>
            <a href="{{ route('pos.index') }}" class="quick-action">
                <i class="ti ti-shopping-cart" aria-hidden="true"></i><span>New Sale</span>
            </a>
            <a href="{{ route('inventory.index', ['filter' => 'low_stock']) }}" class="quick-action">
                <i class="ti ti-adjustments" aria-hidden="true"></i><span>Stock Adjustment</span>
            </a>
            <a href="{{ route('reports.index') }}" class="quick-action">
                <i class="ti ti-file-text" aria-hidden="true"></i><span>Generate Report</span>
            </a>
        </div>
    </div>

    {{-- Alerts are derived live from the same figures the KPI row shows.
         Deliberately no "10m ago" ages: nothing records when a condition first
         appeared, so a timestamp here would be invented. --}}
    <div class="card">
        <div class="demand-head">
            <span class="label">Alerts &amp; Notifications</span>
            <a href="{{ route('inventory.index') }}" class="view-all">View All</a>
        </div>

        <div class="alerts-grid">
        @forelse($alerts as $alert)
            <a href="{{ $alert['href'] }}" class="alert-row {{ $alert['cls'] }}">
                <i class="ti {{ $alert['icon'] }}" aria-hidden="true"></i>
                <span>
                    <strong>{{ $alert['title'] }}</strong>
                    <small>{{ $alert['body'] }}</small>
                </span>
            </a>
        @empty
            <p class="expiry-empty" style="color:#94a3b8;">Nothing needs attention right now.</p>
        @endforelse
        </div>
    </div>
</div>
