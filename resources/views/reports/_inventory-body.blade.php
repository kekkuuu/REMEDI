{{-- The Inventory Report itself -- everything below the filter bar.

     One partial behind both shapes of the page (ReportController::inventory):
     the full page includes it, and a filter change fetches it alone as
     {html, state} and swaps it in, so the two cannot drift. No <script> that
     must run -- innerHTML never executes one -- only the chart data as JSON,
     which renderInventoryCharts() in reports/inventory.blade.php reads after
     every swap. --}}
<div class="no-print">

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

        {{-- Low Stock and Expired Stock have their accents swapped relative to
             where they started: low stock now carries the red, expired the
             amber. --}}
        <div class="kpi {{ $lowStockCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#ef4444;">
            <div class="kpi-head">
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                <span class="kpi-label">Low Stock</span>
            </div>
            <span class="kpi-value">{{ number_format($lowStockCount) }}</span>
            <span class="kpi-sub">at or below reorder level</span>
        </div>

        <div class="kpi {{ $expiredCount === 0 ? 'is-clear' : '' }}" style="--kpi-accent:#f59e0b;">
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
               placeholder="Filter by product or category..."
               autocomplete="off"
               style="flex:1;min-width:220px;max-width:340px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">

        <span style="font-size:11.5px;color:#94a3b8;" id="report-count"
              data-total="{{ $products->count() }}">{{ number_format($products->count()) }} products &middot; scroll inside the table</span>
    </div>
    <div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
        <div class="table-scroll list-scroll" style="max-height:560px;"><table class="inv-rep">
            <thead class="sticky-head">
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Stock Value</th>
                    <th class="mid">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $p)
                    @php
                        // Ordered so the badge says the same thing the Low Stock
                        // filter decided.
                        //
                        // Out of stock first, in the same graphite the bell and the
                        // toasts use: at zero a product is also "low", and "Low
                        // Stock" on an empty shelf understates it.
                        //
                        // Then stock that exists but cannot be sold -- every unit
                        // expired. That row is excluded from the Low Stock filter
                        // (see ReportController), so calling it "Low Stock" here
                        // would contradict the list it is missing from.
                        //
                        // Resolved once per row into a class + icon + label rather
                        // than four branches of markup, because every byte here is
                        // paid for 2,638 times.
                        $badge = $p->total_stock <= 0
                            ? ['is-out', 'ti-alert-circle', 'Out of Stock']
                            : ($p->sellable_stock <= 0
                                ? ['is-expired', 'ti-alert-octagon', 'Expired Stock']
                                : ($p->total_stock <= $p->reorder_level
                                    ? ['is-low', 'ti-alert-circle', 'Low Stock']
                                    : ['is-ok', 'ti-circle-check', 'OK']));

                        $stockCls = $p->total_stock <= 0
                            ? 'stock is-out'
                            : ($p->total_stock <= $p->reorder_level ? 'stock is-low' : 'stock');

                        $expired = $p->expiredBatches ? $p->expiredBatches->count() : 0;
                    @endphp
                <tr data-search="{{ Str::lower($p->name.' '.($p->category->name ?? '').' '.$p->sku) }}">
                    <td class="idx">{{ $loop->iteration }}</td>
                    <td><div class="strong">{{ $p->name }}</div>@if($expired)<div class="expired-note"><i class="ti ti-alert-triangle"></i> {{ $expired }} expired batch{{ $expired > 1 ? 'es' : '' }}</div>@endif</td>
                    <td class="muted">{{ $p->category->name ?? '—' }}</td>
                    <td><span class="{{ $stockCls }}">{{ $p->total_stock }}</span><span class="unit"> {{ $p->unit }}</span></td>
                    <td class="num muted">₱{{ number_format($p->selling_price, 2) }}</td>
                    <td class="num strong">₱{{ number_format($p->total_stock * $p->selling_price, 2) }}</td>
                    <td class="mid"><span class="inv-badge {{ $badge[0] }}"><i class="ti {{ $badge[1] }}"></i> {{ $badge[2] }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="empty">
                        <i class="ti ti-package-off"></i>
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


</div>{{-- end .no-print --}}


{{-- ===================== PRINT AREA ===================== --}}
<div id="print-area">

    {{-- Print Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding-bottom:16px;border-bottom:1.5px solid #111;">
        <div>
            <div style="font-size:22px;font-weight:700;color:#111;letter-spacing:-0.02em;"> REMEDI</div>
            <div style="font-size:12px;color:#6b7280;margin-top:2px;">Point of Sale System</div>
        </div>
        <div style="text-align:right;">
            @php
                // The filters actually in force, built once for both print
                // headings. Assembled in PHP rather than as a run of inline
                // @if/@endif pairs, because Blade will not compile a directive
                // that sits immediately after another one's @endif: its regex
                // requires a non-word character before the @, and "f" is a word
                // character. So `@endif@if(...)` leaves the second @if as
                // literal text while its @endif compiles anyway -- an
                // unbalanced endif that only fails when the page is RENDERED.
                // `php artisan view:cache` writes the broken PHP without
                // executing it, so it reports success; only a request finds it.
                $activeFilters = array_values(array_filter([
                    $categoryId ? optional($categories->firstWhere('id', $categoryId))->name : null,
                    $lowStockOnly ? 'Low Stock Only' : null,
                    $expiredOnly ? 'Expired Only' : null,
                    $okOnly ? 'OK Only' : null,
                ]));
            @endphp
            <div style="font-size:18px;font-weight:600;color:#111;">Inventory Report</div>
            @if($activeFilters)
                <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                    Filtered by: {{ implode(' · ', $activeFilters) }}
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
        @if($activeFilters)
            <span style="color:#6b7280;font-weight:400;">
                ({{ implode(', ', $categoryId ? $activeFilters : array_merge(['All Categories'], $activeFilters)) }})
            </span>
        @endif
    </div>

    <div class="table-scroll"><table class="inv-print">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Category</th>
                <th>Stock</th>
                <th class="num">Unit Price</th>
                <th class="num">Stock Value</th>
                <th class="mid">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                @php $expired = $p->expiredBatches ? $p->expiredBatches->count() : 0; @endphp
            <tr>
                <td class="idx">{{ $loop->iteration }}</td>
                <td class="strong">{{ $p->name }}
                    @if($expired)<div class="expired-note">{{ $expired }} expired batch{{ $expired > 1 ? 'es' : '' }}</div>@endif</td>
                <td class="muted">{{ $p->category->name ?? '—' }}</td>
                <td class="strong {{ $p->is_running_out ? 'stock is-low' : '' }}">{{ $p->total_stock }} {{ $p->unit }}</td>
                <td class="num muted">₱{{ number_format($p->selling_price, 2) }}</td>
                <td class="num strong">₱{{ number_format($p->total_stock * $p->selling_price, 2) }}</td>
                <td class="mid"><span class="{{ $p->is_running_out ? 'st-low' : 'st-ok' }}">{{ $p->is_running_out ? 'Low Stock' : 'OK' }}</span></td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="empty">No products found.</td>
            </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="label">Total Stock Value</td>
                <td class="total">₱{{ number_format($totalStockValue, 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table></div>

    {{-- Print Footer --}}
    <div style="margin-top:40px;padding-top:12px;border-top:0.5px solid #e5e7eb;display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;">
        <span>REMEDI Point of Sale System</span>
        <span>Printed by: {{ auth()->user()->name ?? 'Admin' }} &nbsp;|&nbsp; {{ now()->format('M d, Y h:i A') }}</span>
    </div>

</div>{{-- end #print-area --}}

{{-- Chart data for renderInventoryCharts(). OK is the controller's one
     definition ($okCount), not "everything minus low stock", which counted
     expired products as OK too. --}}
@php
    $inventoryChartData = [
        'category' => $stockValueByCategory->isNotEmpty() ? [
            'labels' => $stockValueByCategory->pluck('category'),
            'values' => $stockValueByCategory->pluck('value'),
        ] : null,
        'health' => [$okCount, $lowStockCount, $expiredCount],
    ];
@endphp
<script type="application/json" id="inventoryChartData">{!! json_encode($inventoryChartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
