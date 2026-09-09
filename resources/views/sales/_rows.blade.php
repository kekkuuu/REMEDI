<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
            <th style="width:52px; text-align:right;">#</th>
            <th>Transaction no.</th>
            <th>Date</th>
            <th>Time</th>
            @if(auth()->user()->isAdmin())
                <th>Cashier</th>
            @endif
            <th>Total amount</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse($sales as $sale)
            <tr>
                {{-- The list is newest-first, but the NUMBER counts the other way:
                     the latest transaction shows the highest number (the total
                     count in this filtered set) and the oldest shows 1, so the
                     number reads as "the Nth sale", not "the Nth row on screen".
                     firstItem() + loop->index is the row's 1-based position from
                     the top of the descending list, continuous across pages. --}}
                <td style="text-align:right; color:#94a3b8;">{{ $sales->total() - ($sales->firstItem() + $loop->index) + 1 }}</td>
                <td style="font-family: monospace; font-size: 14px; color: #4f46e5;">{{ $sale->transaction_no }}</td>
                <td>{{ $sale->created_at->format('M d, Y') }}</td>
                <td style="color: #64748b;">{{ $sale->created_at->format('h:i A') }}</td>
                @if(auth()->user()->isAdmin())
                    <td>{{ $sale->user->name }}</td>
                @endif
                <td style="font-weight: 500;">₱{{ number_format($sale->total_amount, 2) }}</td>
                <td style="text-align: right;">
                    <a href="{{ route('sales.show', $sale) }}" class="btn btn-info btn-sm">
                        <i class="ti ti-eye" aria-hidden="true"></i> View
                    </a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ auth()->user()->isAdmin() ? 7 : 6 }}"
                    style="text-align: center; color: #94a3b8; padding: 32px 0; font-size: 15px;">
                    No transactions found.
                </td>
            </tr>
        @endforelse
    </tbody>
</table></div>
