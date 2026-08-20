{{-- Sales-only panels.

     These live INSIDE the Sales section, not below both tabs. They used to sit
     outside the switcher, so selecting "Inventory" still left a table of POS
     receipts and a revenue ring on screen — the one thing the Inventory tab is
     supposed to be free of. --}}
<div class="dash-lower">

    <div class="dash-lower-main">
        {{-- Recent POS checkouts.

             Note which columns are here: this app's `sales` table has no
             customer and no payment-method column. Rather than print a
             plausible-looking "Cash" against every row, the panel shows only
             what is actually recorded — every sale really is a walk-in, since
             the register never asks who is buying. Adding either column means a
             migration plus capturing the value at checkout. --}}
        <div class="card">
            <div class="demand-head">
                <span class="label">Recent Sales Transactions</span>
                <a href="{{ route('sales.index') }}" class="view-all">View All</a>
            </div>

            {{-- Capped and scrolled, with a sticky header, so this panel is the
                 same height as Sales Summary beside it instead of running
                 ~300px past it. The cap is why the controller can afford to
                 send more rows than fit: see $recentSales in
                 DashboardController::index. --}}
            @if($recentSales->isNotEmpty())
                <div class="table-scroll list-scroll"><table class="remedi-table">
                    <thead class="sticky-head">
                        <tr>
                            <th>Invoice No.</th>
                            <th>Customer</th>
                            <th>Date &amp; Time</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentSales as $sale)
                            <tr>
                                <td><a href="{{ route('sales.show', $sale) }}">{{ $sale->transaction_no }}</a></td>
                                <td style="color:#64748b;">Walk-in Customer</td>
                                <td style="color:#64748b;">{{ $sale->created_at->format('M j, Y') }} &middot; {{ $sale->created_at->format('g:i A') }}</td>
                                <td>{{ $sale->items_count }}</td>
                                <td style="font-weight:600;">&#8369;{{ number_format($sale->total_amount, 2) }}</td>
                                <td>
                                    {{-- payment_voided is history: sales taken before the
                                         supervisor-void feature was removed still carry true,
                                         so a reprint must not call them normally paid. --}}
                                    <span class="badge {{ $sale->payment_voided ? 'badge-critical' : 'badge-success' }}">
                                        {{ $sale->payment_voided ? 'Voided' : 'Completed' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @else
                <p class="expiry-empty" style="color:#94a3b8;">No sales have been rung up on this terminal yet.</p>
            @endif
        </div>
    </div>

    <div class="dash-lower-side">
        {{-- Sales Summary: quarterly revenue from sales_history. Labelled "sales
             records", not "transactions" — each row is one product sold on one
             date, not a basket, so "transactions" would overstate it. --}}
        <div class="card">
            <div class="demand-head">
                <span class="label">Sales Summary</span>
                <span style="font-size:11.5px;color:#94a3b8;">{{ $quarterSummary['year'] ?? '—' }}</span>
            </div>

            @if(($quarterSummary['total'] ?? 0) > 0)
                <div class="summary-split">
                    <div class="summary-figures">
                        <span class="summary-label">Total Sales</span>
                        <span class="summary-value">&#8369;{{ number_format($quarterSummary['total'] / 1000000, 2) }}M</span>
                        <span class="summary-label" style="margin-top:14px;">Sales Records</span>
                        <span class="summary-value is-small">{{ number_format($quarterSummary['records']) }}</span>
                    </div>
                    {{-- Height lives in the stylesheet (.dash-lower-side
                         .chart-box), not inline: an inline height wins over any
                         rule, so this panel could not be sized to match Recent
                         Sales beside it while the number sat here. --}}
                    <div class="chart-box">
                        <canvas id="quarterChart"></canvas>
                    </div>
                </div>
            @else
                <p class="expiry-empty" style="color:#94a3b8;">No sales history for the current year.</p>
            @endif
        </div>
    </div>
</div>
