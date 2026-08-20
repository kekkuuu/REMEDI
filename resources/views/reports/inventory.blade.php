@extends('layouts.app')

@section('title', 'Inventory Report')

@section('content')

<style>
/* The printable report is a second, unpaginated copy of the whole dataset.
   Useful on paper, ruinous on screen — it made the page ~167 viewports tall.
   Screen uses the capped table above; this exists only for the printout. */
@media screen {
    #print-area { display: none; }
}

@media print {
    body * { visibility: hidden; }
    #print-area, #print-area * { visibility: visible; }
    #print-area {
        display: block;
        position: static;
        width: 100%;
        padding: 0;
        background: #fff;
    }
    #no-print { display: none !important; }

    /* Standard paper size + margins for the printed report */
    @page {
        size: A4;
        margin: 14mm 12mm;
    }

    /* Repeat the table header/footer on every printed page */
    table thead { display: table-header-group; }
    table tfoot { display: table-footer-group; }

    /* Never split a table row across two pages, but let long tables
       flow across as many pages as they need */
    table { page-break-inside: auto; }
    table tr { page-break-inside: avoid; break-inside: avoid; }

    /* Keep a section's title glued to the content that follows it */
    .print-section-title { page-break-after: avoid; break-after: avoid; }
}
</style>

{{-- ===================== SCREEN ONLY ===================== --}}
<div id="no-print">

    {{-- Page Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1.5rem;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="{{ route('reports.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back </a>
            
            <div>
                <div style="font-size:20px;font-weight:500;color:#111;">
                    <i class="ti ti-package" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Inventory Report
                </div>
                <div style="font-size:13px;color:#6b7280;margin-top:2px;">Current stock levels, values, and status for all products</div>
            </div>
        </div>
    </div>

    {{-- Filter Form --}}
    <form method="GET" action="{{ route('reports.inventory') }}"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem;">
        <div style="display:flex;flex-direction:column;gap:4px;">
            <label>Category</label>
            <select name="category_id" class="report-select">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ (string) $categoryId === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <label style="display:flex;align-items:center;gap:6px;height:34px;font-size:13px;color:#374151;cursor:pointer;">
            <input type="checkbox" name="low_stock" value="1" {{ $lowStockOnly ? 'checked' : '' }} style="width:16px;height:16px;">
            Low stock only
        </label>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="ti ti-filter" style="font-size:14px;"></i> Apply
            </button>
            @if($categoryId || $lowStockOnly)
                <a href="{{ route('reports.inventory') }}" class="btn btn-secondary btn-sm">
                    Clear
                </a>
            @endif
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="ti ti-printer" style="font-size:14px;"></i> Print
            </button>
        </div>
    </form>

    {{-- Shared .kpi stat cards (layouts/app.blade.php), matching the Dashboard. --}}
    <div class="kpi-grid">
        <div class="kpi" style="--kpi-accent:#6366f1;">
            <div class="kpi-head">
                <i class="ti ti-coin" aria-hidden="true"></i>
                <span class="kpi-label">Total Stock Value</span>
            </div>
            <span class="kpi-value">&#8369;{{ number_format($totalStockValue, 2) }}</span>
            <span class="kpi-sub">at current selling price</span>
        </div>

        <div class="kpi" style="--kpi-accent:#3b82f6;">
            <div class="kpi-head">
                <i class="ti ti-packages" aria-hidden="true"></i>
                <span class="kpi-label">Total Products</span>
            </div>
            <span class="kpi-value">{{ number_format($products->count()) }}</span>
            <span class="kpi-sub">in the catalog</span>
        </div>

        <div class="kpi {{ $lowStockCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#f59e0b;">
            <div class="kpi-head">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                <span class="kpi-label">Low Stock</span>
            </div>
            <span class="kpi-value">{{ number_format($lowStockCount) }}</span>
            <span class="kpi-sub">at or below reorder level</span>
        </div>

        <div class="kpi {{ $expiredCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#ef4444;">
            <div class="kpi-head">
                <i class="ti ti-alert-octagon" aria-hidden="true"></i>
                <span class="kpi-label">Expired Stock</span>
            </div>
            <span class="kpi-value">{{ number_format($expiredCount) }}</span>
            <span class="kpi-sub">still on the shelf</span>
        </div>
    </div>

    {{-- Charts --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:1.5rem;">
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;background:#fff;padding:16px;">
            <div style="font-size:14px;font-weight:500;color:#111;margin-bottom:12px;">
                <i class="ti ti-chart-pie" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#4f46e5;"></i>
                Stock Value by Category
            </div>
            @if($stockValueByCategory->isNotEmpty())
                <div class="chart-box chart-box-legend-right"><canvas id="categoryValueChart"></canvas></div>
            @else
                <p style="color:#9ca3af;font-size:13px;text-align:center;padding:24px 0;">No products yet.</p>
            @endif
        </div>
        <div style="border:0.5px solid #e5e7eb;border-radius:12px;background:#fff;padding:16px;">
            <div style="font-size:14px;font-weight:500;color:#111;margin-bottom:12px;">
                <i class="ti ti-chart-donut" style="font-size:14px;vertical-align:-1px;margin-right:6px;color:#dc2626;"></i>
                Stock Health
            </div>
            <div class="chart-box chart-box-legend-right"><canvas id="stockHealthChart"></canvas></div>
        </div>
    </div>

    {{-- Screen Table --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
        <span style="font-size:14px;font-weight:500;color:#111;">Stock Detail</span>

        {{-- Live filter. Deliberately NO suggestion dropdown (see "Search: live
             filtering, no dropdown" in REMEDI.md): matches appear in this
             table, not in a panel floating over it. Filtering is client-side
             against rows already rendered, so it is instant and does not
             re-run the report's multi-second aggregates. The print copy in
             #print-area is untouched — a printed report stays complete. --}}
        <input type="text" id="report-search"
               data-suggest-url="{{ route('suggest.products') }}"
               placeholder="Filter by product, category or status..."
               autocomplete="off"
               style="flex:1;min-width:220px;max-width:340px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">

        <span style="font-size:11.5px;color:#94a3b8;" id="report-count"
              data-total="{{ $products->count() }}">{{ number_format($products->count()) }} products &middot; scroll inside the table</span>
    </div>
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
        <div class="table-scroll list-scroll" style="max-height:560px;"><table style="width:100%;min-width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
            <thead class="sticky-head">
                <tr style="background:#f9fafb;">
                    <th style="width:4%;padding:9px 11px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">#</th>
                    <th style="padding:9px 11px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;width:31%;">Product</th>
                    <th style="width:15%;padding:9px 11px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Category</th>
                    <th style="width:11%;padding:9px 11px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Stock</th>
                    <th style="width:12%;padding:9px 11px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Unit Price</th>
                    <th style="width:14%;padding:9px 11px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Stock Value</th>
                    <th style="width:13%;padding:9px 11px;text-align:center;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $p)
                <tr data-search="{{ Str::lower($p->name.' '.($p->category->name ?? '').' '.$p->sku) }}"
                    style="border-bottom:0.5px solid #e5e7eb;"
                    onmouseover="this.style.background='#f9fafb'"
                    onmouseout="this.style.background=''">
                    <td style="padding:11px 14px;color:#9ca3af;font-size:12px;">{{ $loop->iteration }}</td>
                    <td style="padding:11px 14px;">
                        <div style="font-weight:500;color:#111;">{{ $p->name }}</div>
                        @if($p->expiredBatches && $p->expiredBatches->count())
                            <div style="font-size:11px;color:#dc2626;margin-top:2px;">
                                <i class="ti ti-alert-triangle" style="font-size:11px;"></i>
                                {{ $p->expiredBatches->count() }} expired batch{{ $p->expiredBatches->count() > 1 ? 'es' : '' }}
                            </div>
                        @endif
                    </td>
                    <td style="padding:11px 14px;color:#6b7280;">{{ $p->category->name ?? '—' }}</td>
                    <td style="padding:11px 14px;">
                        <span style="font-weight:500;color:{{ $p->is_low_stock ? '#dc2626' : '#111' }};">
                            {{ $p->total_stock }}
                        </span>
                        <span style="color:#9ca3af;font-size:12px;"> {{ $p->unit }}</span>
                    </td>
                    <td style="padding:11px 14px;text-align:right;color:#6b7280;">₱{{ number_format($p->selling_price, 2) }}</td>
                    <td style="padding:11px 14px;text-align:right;font-weight:500;color:#111;">₱{{ number_format($p->total_stock * $p->selling_price, 2) }}</td>
                    <td style="padding:11px 14px;text-align:center;">
                        @if($p->is_low_stock)
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:500;padding:3px 8px;border-radius:20px;background:#FCEBEB;color:#791F1F;">
                                <i class="ti ti-alert-circle" style="font-size:11px;"></i> Low Stock
                            </span>
                        @else
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:500;padding:3px 8px;border-radius:20px;background:#EAF3DE;color:#27500A;">
                                <i class="ti ti-circle-check" style="font-size:11px;"></i> OK
                            </span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="padding:48px;text-align:center;color:#9ca3af;font-size:14px;">
                        <i class="ti ti-package-off" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                        No products found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table></div>

        {{-- Table Footer --}}
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-top:0.5px solid #e5e7eb;font-size:12px;color:#6b7280;">
            <span>{{ $products->count() }} product{{ $products->count() !== 1 ? 's' : '' }} total</span>
            <span style="font-weight:500;color:#4f46e5;">Total value: ₱{{ number_format($totalStockValue, 2) }}</span>
        </div>
    </div>

</div>{{-- end #no-print --}}

<script>
    // Real-time filtering, no suggestion box. REMEDI.attachSuggest still wires
    // the field to /suggest/products (it debounces and fires `suggest:live`),
    // and the page decides what that means — here, narrowing its own table.
    (function () {
        var input = document.getElementById('report-search');
        var count = document.getElementById('report-count');
        if (!input || !count) return;

        var rows = Array.prototype.slice.call(
            document.querySelectorAll('#no-print tbody tr[data-search]')
        );
        var total = parseInt(count.dataset.total || rows.length, 10);

        function apply() {
            var q = input.value.trim().toLowerCase();
            var shown = 0;

            rows.forEach(function (tr) {
                var hit = q === '' || tr.dataset.search.indexOf(q) !== -1;
                tr.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });

            count.textContent = q === ''
                ? total.toLocaleString() + ' products · scroll inside the table'
                : shown.toLocaleString() + ' of ' + total.toLocaleString() + ' products match';
        }

        input.addEventListener('input', apply);
        input.addEventListener('suggest:live', apply);
        input.addEventListener('suggest:choose', function (e) {
            if (e.detail && e.detail.label) { input.value = e.detail.label; }
            apply();
        });
        // Enter would submit an enclosing form and reload the report.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); apply(); }
        });
    })();
</script>


{{-- ===================== PRINT AREA ===================== --}}
<div id="print-area">

    {{-- Print Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1.5px solid #111;">
        <div>
            <div style="font-size:22px;font-weight:700;color:#111;letter-spacing:-0.02em;"> REMEDI</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">Point of Sale System</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:18px;font-weight:600;color:#111;">Inventory Report</div>
            @if($categoryId || $lowStockOnly)
                <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                    Filtered by:
                    @if($categoryId){{ optional($categories->firstWhere('id', $categoryId))->name }}@endif
                    @if($categoryId && $lowStockOnly) &middot; @endif
                    @if($lowStockOnly)Low Stock Only @endif
                </div>
            @endif
            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Generated: {{ now()->format('M d, Y h:i A') }}</div>
        </div>
    </div>

    {{-- Print Summary --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;">
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #6366f1;background:#eef2ff;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Total Stock Value</div>
            <div style="font-size:18px;font-weight:600;color:#4f46e5;">₱{{ number_format($totalStockValue, 2) }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #3b82f6;background:#eff6ff;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Total Products</div>
            <div style="font-size:18px;font-weight:600;color:#185FA5;">{{ $products->count() }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #f59e0b;background:#fffbeb;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Low Stock</div>
            <div style="font-size:18px;font-weight:600;color:#dc2626;">{{ $lowStockCount }}</div>
        </div>
        <div class="report-summary-card" style="border:1px solid #e5e7eb;border-left:4px solid #ef4444;background:#fef2f2;border-radius:8px;padding:12px 14px;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;">Expired Stock</div>
            <div style="font-size:18px;font-weight:600;color:#854F0B;">{{ $expiredCount }}</div>
        </div>
    </div>

    {{-- Print Table --}}
    <div class="print-section-title" style="font-size:13px;font-weight:500;color:#111;margin-bottom:10px;">
        Product Inventory — {{ now()->format('M d, Y') }}
        @if($categoryId || $lowStockOnly)
            <span style="color:#6b7280;font-weight:400;">
                ({{ $categoryId ? optional($categories->firstWhere('id', $categoryId))->name : 'All Categories' }}@if($lowStockOnly), Low Stock Only @endif)
            </span>
        @endif
    </div>

    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">#</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Product</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Category</th>
                <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Stock</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Unit Price</th>
                <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Stock Value</th>
                <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.04em;border:1px solid #e5e7eb;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
            <tr style="{{ $loop->even ? 'background:#f9fafb;' : 'background:#fff;' }}">
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#9ca3af;font-size:11px;">{{ $loop->iteration }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;">
                    {{ $p->name }}
                    @if($p->expiredBatches && $p->expiredBatches->count())
                        <div style="font-size:10px;color:#dc2626;">{{ $p->expiredBatches->count() }} expired batch{{ $p->expiredBatches->count() > 1 ? 'es' : '' }}</div>
                    @endif
                </td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;">{{ $p->category->name ?? '—' }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:{{ $p->is_low_stock ? '#dc2626' : '#111' }};font-weight:500;">
                    {{ $p->total_stock }} {{ $p->unit }}
                </td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">₱{{ number_format($p->selling_price, 2) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;color:#111;font-weight:500;text-align:right;">₱{{ number_format($p->total_stock * $p->selling_price, 2) }}</td>
                <td style="padding:8px 12px;border:1px solid #e5e7eb;text-align:center;">
                    @if($p->is_low_stock)
                        <span style="font-size:11px;font-weight:600;color:#dc2626;">Low Stock</span>
                    @else
                        <span style="font-size:11px;font-weight:600;color:#16a34a;">OK</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" style="padding:24px;text-align:center;color:#9ca3af;border:1px solid #e5e7eb;">
                    No products found.
                </td>
            </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr style="background:#f3f4f6;">
                <td colspan="5" style="padding:9px 12px;font-weight:600;font-size:12px;color:#111;border:1px solid #e5e7eb;text-align:right;">Total Stock Value</td>
                <td style="padding:9px 12px;font-weight:600;font-size:13px;color:#4f46e5;border:1px solid #e5e7eb;text-align:right;">₱{{ number_format($totalStockValue, 2) }}</td>
                <td style="border:1px solid #e5e7eb;"></td>
            </tr>
        </tfoot>
    </table></div>

    {{-- Print Footer --}}
    <div style="margin-top:40px;padding-top:12px;border-top:0.5px solid #e5e7eb;display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;">
        <span>REMEDI Point of Sale System</span>
        <span>Printed by: {{ auth()->user()->name ?? 'Admin' }} &nbsp;|&nbsp; {{ now()->format('M d, Y h:i A') }}</span>
    </div>

</div>{{-- end #print-area --}}

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    // The doughnut legends sit to the right on desktop/tablet; a phone has no
    // horizontal room for that, so they drop back underneath.
    Chart.defaults.plugins.legend.position =
        window.matchMedia('(max-width: 767px)').matches ? 'bottom' : 'right';
</script>
<script>
    @if($stockValueByCategory->isNotEmpty())
    new Chart(document.getElementById('categoryValueChart'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($stockValueByCategory->pluck('category')) !!},
            datasets: [{
                data: {!! json_encode($stockValueByCategory->pluck('value')) !!},
                backgroundColor: ['#6366f1', '#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#0ea5e9', '#ec4899'],
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            layout: { padding: { top: 4, bottom: 4, left: 4, right: 8 } },
            plugins: {
                legend: { position: 'right', align: 'center', labels: { boxWidth: 11, boxHeight: 11, padding: 13, usePointStyle: true, pointStyle: 'circle', font: { size: 11.5 }, color: '#334155' } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ₱${ctx.parsed.toLocaleString(undefined, { minimumFractionDigits: 2 })}` } },
            },
        },
    });
    @endif

    new Chart(document.getElementById('stockHealthChart'), {
        type: 'doughnut',
        data: {
            labels: ['OK', 'Low Stock', 'Expired'],
            datasets: [{
                data: [{{ $products->count() - $lowStockCount }}, {{ $lowStockCount }}, {{ $expiredCount }}],
                backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            layout: { padding: { top: 4, bottom: 4, left: 4, right: 8 } },
            plugins: {
                legend: { position: 'right', align: 'center', labels: { boxWidth: 11, boxHeight: 11, padding: 13, usePointStyle: true, pointStyle: 'circle', font: { size: 11.5 }, color: '#334155' } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed}` } },
            },
        },
    });
</script>

@endsection
