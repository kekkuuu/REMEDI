@extends('layouts.app')

@section('title', 'Transaction Details')

@section('content')
<div class="page-head">
    <a href="{{ route('sales.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>{{ $sale->transaction_no }}</h3>
        <p>{{ $sale->created_at->format('M d, Y h:i A') }} &middot; {{ $sale->user->name }}</p>
    </div>
    {{-- $sale is only ever reachable here for the viewer's own sale (staff)
         or any sale (admin) -- see Sale::isVisibleTo() in SaleController::
         show(). Staff get the SAME button, just gated on the manager
         passcode inside the confirm dialog (data-confirm-passcode) -- the
         endpoint enforces both the ownership check and the passcode itself,
         since a gated button is not a gated endpoint. --}}
    @if(! $sale->payment_voided)
        <div class="page-head-actions">
            {{-- The reason (Sale::VOID_REASONS) is chosen IN the confirm
                 dialog, not before it -- data-confirm-reasons swaps the
                 dialog's one generic Confirm button for one per reason; see
                 the confirmModalReasons handler in layouts/app.blade.php and
                 the identical pattern on User Management's Archive button.
                 data-confirm-passcode adds the manager-passcode field ABOVE
                 those buttons for a non-admin, and disables them until 6
                 digits are entered -- admin needs neither. --}}
            <form method="POST" action="{{ route('sales.void', $sale) }}"
                  class="js-confirm"
                  data-confirm-title="Void this transaction?"
                  data-confirm-body="Void {{ $sale->transaction_no }} (₱{{ number_format($sale->total_amount, 2) }})? Every item on it goes back on the shelf, and the sale stops counting toward revenue. This cannot be undone from here — restoring it would need a new sale. Choose why below."
                  data-confirm-reasons="{{ json_encode(\App\Models\Sale::VOID_REASONS) }}"
                  @unless(auth()->user()->isAdmin()) data-confirm-passcode="1" @endunless
                  data-confirm-icon="ti-receipt-off">
                @csrf
                @method('PATCH')
                <input type="hidden" name="reason" value="">
                @unless(auth()->user()->isAdmin())
                    <input type="hidden" name="passcode" value="">
                @endunless
                <button type="submit" class="btn btn-danger">
                    <i class="ti ti-receipt-off" aria-hidden="true"></i> Void Transaction
                </button>
            </form>
        </div>
    @endif
</div>

@if($sale->payment_voided)
    {{-- Same shape as the archived-account status badge, just full-width and
         hard to miss on a page whose whole job is describing this sale. --}}
    <div style="margin-bottom:16px;padding:14px 16px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;display:flex;align-items:flex-start;gap:10px;">
        <i class="ti ti-receipt-off" style="color:#b91c1c;font-size:20px;flex:none;margin-top:1px;" aria-hidden="true"></i>
        <div style="font-size:13.5px;color:#7f1d1d;">
            <strong>Voided</strong> — {{ \App\Models\Sale::VOID_REASONS[$sale->void_reason] ?? $sale->void_reason }}.
            Stock was returned to the shelf and this transaction no longer counts toward revenue.
            <div style="margin-top:2px;color:#991b1b;">
                {{ $sale->voidedBy->name ?? 'An admin' }} &middot; {{ $sale->voided_at?->format('M j, Y g:i A') }}
            </div>
        </div>
    </div>
@endif

<div class="card">
    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:16px; margin-bottom:20px;">
        <p style="margin:0;"><strong>Transaction No:</strong><br>{{ $sale->transaction_no }}</p>
        <p style="margin:0;"><strong>Date/Time:</strong><br>{{ $sale->created_at->format('M d, Y h:i A') }}</p>
        <p style="margin:0;"><strong>Cashier:</strong><br>{{ $sale->user->name }}</p>
        <p style="margin:0;"><strong>Payment Method:</strong><br>{{ \App\Models\Sale::PAYMENT_METHODS[$sale->payment_method] ?? ucfirst($sale->payment_method) }}</p>
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
    <h3 style="text-align:right; {{ $sale->payment_voided ? 'text-decoration:line-through; color:#94a3b8;' : '' }}">
        Total: ₱{{ number_format($sale->total_amount, 2) }}
    </h3>
</div>
@endsection
