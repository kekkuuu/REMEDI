@extends('layouts.app')

@section('title', 'Transaction Details')

@section('content')
<div class="page-back">
    <a href="{{ route('sales.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back to Sales History</a>
</div>

<div class="card">
    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:20px;">
        <p style="margin:0;"><strong>Transaction No:</strong><br>{{ $sale->transaction_no }}</p>
        <p style="margin:0;"><strong>Date/Time:</strong><br>{{ $sale->created_at->format('M d, Y h:i A') }}</p>
        <p style="margin:0;"><strong>Cashier:</strong><br>{{ $sale->user->name }}</p>
    </div>

    <div class="table-scroll"><table class="remedi-table">
        <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
        <tbody>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->product->name ?? 'Deleted Product' }}</td>
                <td>{{ $item->quantity }}</td>
                <td>₱{{ number_format($item->price, 2) }}</td>
                <td>₱{{ number_format($item->subtotal, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <h3 style="text-align:right;">Total: ₱{{ number_format($sale->total_amount, 2) }}</h3>
</div>
@endsection
