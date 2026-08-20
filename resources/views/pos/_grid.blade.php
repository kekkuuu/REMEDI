<div class="pos-grid">
    @forelse($products as $product)
        @php
            $stockClass = $product->total_stock <= 0
                ? 'badge-danger'
                : ($product->is_low_stock ? 'badge-warning' : 'badge-success');
        @endphp
        <div class="card pos-product {{ $product->total_stock <= 0 ? 'is-out' : '' }}" onclick='addToCart({{ $product->id }}, @json($product->name), {{ $product->selling_price }}, {{ $product->total_stock }})'>
            <strong>{{ $product->name }}</strong><br>
            <small>{{ $product->sku }}</small><br>
            <span style="color:#16a34a; font-weight:bold;">&#8369;{{ number_format($product->selling_price, 2) }}</span><br>
            <span
                id="product-stock-{{ $product->id }}"
                class="badge {{ $stockClass }}"
                data-true-stock="{{ $product->total_stock }}"
                data-reorder-level="{{ $product->reorder_level }}"
            >Stock: {{ $product->total_stock }}</span>
        </div>
    @empty
        <p>No products found.</p>
    @endforelse
</div>
