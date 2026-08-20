<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
            <th>Product ID</th>
            <th>Product Name</th>
            <th>SKU / Barcode</th>
            <th>Category</th>
            <th>Unit</th>
            <th>Selling Price</th>
            <th>Stock</th>
            <th>Nearest Expiry</th>
            <th class="col-status">Status</th>
            <th class="col-actions">Actions</th>
        </tr>
    </thead>
    <tbody>
    @forelse($products as $product)
        <tr>
            <td>{{ $product->id }}</td>
            <td>{{ $product->name }}</td>

            <td>
                <small>SKU: {{ $product->sku }}</small><br>
                <small>Barcode: {{ $product->barcode ?? '—' }}</small>
            </td>
            <td>{{ $product->category->name }}</td>
            <td>{{ $product->unit }}</td>
            <td>&#8369;{{ number_format($product->selling_price, 2) }}</td>
            <td>{{ $product->total_stock }}</td>
            <td>
                @if($product->nearest_expiry)
                    {{ \Carbon\Carbon::parse($product->nearest_expiry)->format('M d, Y') }}
                @else
                    —
                @endif
            </td>
            <td class="col-status">
                <div class="status-stack">
                @if($product->is_low_stock)
                    <span class="badge badge-danger">Low Stock</span>
                @else
                    <span class="badge badge-success">OK</span>
                @endif
                </div>
            </td>
            <td class="col-actions">
                <div class="actions-cell">
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-info action-btn">Edit</a>
                    <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('Delete this product and all its batches?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger action-btn">Delete</button>
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="10">No products found.</td></tr>
    @endforelse
    </tbody>
</table></div>
