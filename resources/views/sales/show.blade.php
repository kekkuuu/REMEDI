@extends('layouts.app')

@section('title', 'Transaction Details')

@section('content')
<div class="page-head">
    <a href="{{ route('sales.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>{{ $sale->transaction_no }}</h3>
        <p>{{ $sale->created_at->format('M d, Y h:i A') }} &middot; {{ $sale->user->name }}</p>
    </div>
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
        {{-- Grouped per product, same as the receipt: FEFO records one
             sale_item per batch drawn from, and this table has no batch column,
             so a split line just showed the same product twice with nothing to
             explain why. Add a Batch column here if that detail is ever wanted;
             until then the rows would be indistinguishable. --}}
        @foreach($sale->items->groupBy(fn ($i) => $i->product_id.'|'.$i->price) as $group)
            <tr>
                <td>{{ $group->first()->product->name ?? 'Deleted Product' }}</td>
                <td>{{ $group->sum('quantity') }}</td>
                <td>₱{{ number_format($group->first()->price, 2) }}</td>
                <td>₱{{ number_format($group->sum('subtotal'), 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <h3 style="text-align:right;">Total: ₱{{ number_format($sale->total_amount, 2) }}</h3>
</div>
@endsection
