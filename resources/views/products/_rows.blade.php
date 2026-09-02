<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
            <th style="width:52px; text-align:right;">#</th>
            <th>Product ID</th>
            <th>Product Name</th>
            <th>SKU</th>
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
            {{-- Row number, continuous across pages: firstItem() is the index of the first row on THIS page, so page 2 starts at 11 rather than restarting at 1. --}}
            <td style="text-align:right; color:#94a3b8;">{{ $products->firstItem() + $loop->index }}</td>
            <td>{{ $product->id }}</td>
            <td>{{ $product->name }}</td>

            {{-- Barcode number removed from the listing. The column still
                 carries the SKU, which is the identifier staff actually quote,
                 and `barcode` is still a real column that search matches on --
                 it is simply not printed. --}}
            <td>
                <small>SKU: {{ $product->sku }}</small>
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
                @if($product->is_running_out)
                    <span class="badge badge-danger">Low Stock</span>
                @else
                    <span class="badge badge-success">OK</span>
                @endif
                </div>
            </td>
            <td class="col-actions">
                <div class="actions-cell">
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-info action-btn"><i class="ti ti-pencil" aria-hidden="true"></i> Edit</a>
                    <form method="POST" action="{{ route('products.destroy', $product) }}"
                          class="js-confirm"
                          data-confirm-title="Delete this product?"
                          data-confirm-body="Deleting {{ $product->name }} ({{ $product->sku }}) also removes all of its batches and their stock. This cannot be undone."
                          data-confirm-label="Delete"
                          data-confirm-icon="ti-trash"
                          data-on-success="remove-row">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger action-btn"><i class="ti ti-trash" aria-hidden="true"></i> Delete</button>
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="11">No products found.</td></tr>
    @endforelse
    </tbody>
</table></div>
