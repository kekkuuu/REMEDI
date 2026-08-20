<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
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
                <td colspan="{{ auth()->user()->isAdmin() ? 6 : 5 }}"
                    style="text-align: center; color: #94a3b8; padding: 32px 0; font-size: 15px;">
                    No transactions found.
                </td>
            </tr>
        @endforelse
    </tbody>
</table></div>
