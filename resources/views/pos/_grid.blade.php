<div class="pos-grid">
    @forelse($products as $product)
        @php
            // sellable_stock, NOT total_stock: this is the till, and checkout
            // will only draw on batches that are neither expired nor returned
            // (see ProductBatch::scopeSellable). Showing the shelf count here
            // meant the card offered "Stock: 10" on a product whose stock had
            // all expired, let the cashier add 10 to the cart, and only failed
            // at checkout with "Available: 0" -- with a customer waiting.
            //
            // Inventory and the reports still show total_stock, which is the
            // right number there: those units are physically present and need
            // pulling. The till just may not sell them.
            $sellable = $product->sellable_stock;

            $stockClass = $sellable <= 0
                ? 'badge-danger'
                : ($product->is_low_stock ? 'badge-warning' : 'badge-success');
        @endphp
        <div class="card pos-product {{ $sellable <= 0 ? 'is-out' : '' }}" onclick='addToCart({{ $product->id }}, @json($product->name), {{ $product->selling_price }}, {{ $sellable }})'>
            <strong>{{ $product->name }}</strong><br>
            <small>{{ $product->sku }}</small><br>
            <span style="color:#16a34a; font-weight:bold;">&#8369;{{ number_format($product->selling_price, 2) }}</span><br>
            <span
                id="product-stock-{{ $product->id }}"
                class="badge {{ $stockClass }}"
                data-true-stock="{{ $sellable }}"
                data-reorder-level="{{ $product->reorder_level }}"
            >Stock: {{ $sellable }}</span>
        </div>
    @empty
        <p>No products found.</p>
    @endforelse
</div>
