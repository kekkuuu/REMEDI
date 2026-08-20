@extends('layouts.app')

@section('title', 'Receipt')

@section('content')
{{--
    Standalone receipt page. Reached by permalink (e.g. reprinting an older
    sale from Sales history), or as the no-JavaScript fallback for checkout —
    with JS enabled, PosController::checkout returns JSON instead and the POS
    page shows this same receipt in a modal without navigating away.
--}}
@include('pos._receipt-styles')

<style>
    .checkout-toast {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%) translateY(-16px);
        background: #16a34a;
        color: #fff;
        padding: 14px 22px;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        font-size: 14.5px;
        z-index: 2000;
        opacity: 0;
        transition: opacity .25s ease, transform .25s ease;
    }

    .checkout-toast.show {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    .checkout-toast .icon {
        font-size: 18px;
        line-height: 1;
    }

    /* The layout already renders a generic success banner above @yield('content');
       the floating toast above replaces it on this page so we don't show both. */
    .content-body > .alert-success {
        display: none;
    }

    .receipt-wrap {
        display: flex;
        justify-content: center;
    }
</style>

<div class="receipt-wrap">
    @if(session('success'))
        <div class="checkout-toast" id="checkout-toast">
            <span class="icon">OK</span>
            <span>{{ session('success') }}</span>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const toast = document.getElementById('checkout-toast');
                requestAnimationFrame(() => toast.classList.add('show'));
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            });
        </script>
    @endif

    <div>
        @include('pos._receipt', ['sale' => $sale])

        <div class="receipt-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
            <a href="{{ route('pos.index') }}" class="btn btn-primary">New Transaction</a>
        </div>
    </div>
</div>
@endsection
