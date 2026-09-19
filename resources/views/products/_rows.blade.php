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
            {{-- An archived product's batches are archived with it, so its stock
                 and expiry are not meaningful here -- the list is a place to find
                 it and restore it, not to read its shelf. --}}
            <td>{{ $archived ? '—' : $product->total_stock }}</td>
            <td>
                @if(! $archived && $product->nearest_expiry)
                    {{ \Carbon\Carbon::parse($product->nearest_expiry)->format('M d, Y') }}
                @else
                    —
                @endif
            </td>
            <td class="col-status">
                <div class="status-stack">
                @if($archived)
                    <span class="badge badge-warning">Archived</span>
                @elseif($product->is_running_out)
                    <span class="badge badge-danger">Low Stock</span>
                @else
                    <span class="badge badge-success">OK</span>
                @endif
                </div>
            </td>
            <td class="col-actions">
                <div class="actions-cell">
                @if($archived)
                    <form method="POST" action="{{ route('products.restore', $product) }}"
                          class="js-confirm"
                          data-confirm-title="Restore this product?"
                          data-confirm-body="{{ $product->name }} ({{ $product->sku }}) goes back on the till and the inventory, together with the batches that were archived with it."
                          data-confirm-label="Restore"
                          data-confirm-icon="ti-archive-off"
                          data-confirm-tone="neutral"
                          data-on-success="remove-row">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-success action-btn"><i class="ti ti-archive-off" aria-hidden="true"></i> Restore</button>
                    </form>
                @else
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-info action-btn"><i class="ti ti-pencil" aria-hidden="true"></i> Edit</a>
                    {{-- Archive, not delete: the product and its batches leave the
                         till, the inventory and the alerts, but nothing is removed,
                         so sales history that names it keeps working. Reversible
                         from the Archived list, hence the neutral tone. --}}
                    <form method="POST" action="{{ route('products.destroy', $product) }}"
                          class="js-confirm"
                          data-confirm-title="Archive this product?"
                          data-confirm-body="{{ $product->name }} ({{ $product->sku }}) and its batches will be taken off the till, the inventory and the alerts. Its sales history is kept, and you can restore it any time from the Archived list."
                          data-confirm-label="Archive"
                          data-confirm-icon="ti-archive"
                          data-confirm-tone="neutral"
                          data-on-success="remove-row">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-warning action-btn"><i class="ti ti-archive" aria-hidden="true"></i> Archive</button>
                    </form>
                @endif
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="11">{{ $archived ? 'No archived products.' : 'No products found.' }}</td></tr>
    @endforelse
    </tbody>
</table></div>
