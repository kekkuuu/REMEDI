{{--
    The receipt body itself. Rendered in two places:
      - server-side into pos/receipt.blade.php (standalone page)
      - server-side by PosController::checkout and returned as JSON, then
        injected into the post-checkout modal on pos/index.blade.php

    Expects: $sale, with items.product and user already eager-loaded.
    Styling lives in pos/_receipt-styles.blade.php; the host view includes it.
--}}
<div class="receipt">
    <div class="receipt-store">
        <div class="name">REMEDI</div>
        <div class="tagline">R.E.D. Drugmart</div>
    </div>

    <hr class="receipt-divider">

    <div class="receipt-meta">
        <div class="row"><span>Transaction No.</span><span>{{ $sale->transaction_no }}</span></div>
        <div class="row"><span>Date</span><span>{{ $sale->created_at->format('M d, Y h:i A') }}</span></div>
        <div class="row"><span>Cashier</span><span>{{ $sale->user->name }}</span></div>
    </div>

    <hr class="receipt-divider">

    <div class="receipt-items">
        @foreach($sale->items as $item)
            <div class="receipt-item">
                <div class="line1">
                    <span>{{ $item->product->name }}</span>
                    <span>&#8369;{{ number_format($item->subtotal, 2) }}</span>
                </div>
                <div class="line2">
                    <span>{{ $item->quantity }} &times; &#8369;{{ number_format($item->price, 2) }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <hr class="receipt-divider">

    <div class="receipt-totals">
        <div class="row">
            <span>Items</span>
            <span>{{ $sale->items->sum('quantity') }}</span>
        </div>
        <div class="grand">
            <span>Total</span>
            <span>&#8369;{{ number_format($sale->total_amount, 2) }}</span>
        </div>
        {{-- Historical only. The supervisor passcode that could bypass payment
             has been removed, so no new sale can be voided -- but sales taken
             before that still carry the flag and must keep reprinting honestly. --}}
        @if($sale->payment_voided)
            <div class="row" style="margin-top:6px; color:#dc2626; font-weight:600;">
                <span>Payment Check</span>
                <span>VOIDED (supervisor)</span>
            </div>
        @endif
        <div class="row" style="margin-top:6px;">
            <span>Amount Paid</span>
            <span>&#8369;{{ number_format($sale->amount_paid, 2) }}</span>
        </div>
        <div class="row">
            <span>Change</span>
            <span>&#8369;{{ number_format($sale->change_due, 2) }}</span>
        </div>
    </div>

    <div class="receipt-footer">
        <div class="thanks">Thank you for your purchase!</div>
        Please keep this receipt for any returns or exchanges.
    </div>
</div>
