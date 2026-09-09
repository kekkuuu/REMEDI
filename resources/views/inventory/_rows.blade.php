<div class="table-scroll"><table class="remedi-table">
    <thead>
        <tr>
            <th style="width:52px; text-align:right;">#</th>
            <th>Product ID</th>
            <th>SKU</th>
            <th>Product Name</th>
            <th>Category</th>
            <th>Selling Price</th>
            <th>Total Stock</th>
            <th>Reorder Level</th>
            <th>Nearest Expiry</th>
            <th class="col-status">Status</th>
            @if(auth()->user()->isAdmin())
                <th class="col-actions">Actions</th>
            @endif
        </tr>
    </thead>
    <tbody>
    @forelse($products as $product)
        <tr id="product-row-{{ $product->id }}">
            {{-- Row number, continuous across pages: firstItem() is the index of the first row on THIS page, so page 2 starts at 11 rather than restarting at 1. --}}
            <td style="text-align:right; color:#94a3b8;">{{ $products->firstItem() + $loop->index }}</td>
            <td>{{ $product->id }}</td>
            {{-- SKU is its own column, not a parenthetical after the name: it is
                 the barcode staff read off the box and the key every forecast
                 joins on, so it needs to be scannable down a column rather than
                 hunted for at the end of a wrapped product name. --}}
            <td class="col-sku">{{ $product->sku }}</td>
            <td>{{ $product->name }}</td>

            <td>{{ $product->category->name }}</td>
            <td>&#8369;{{ number_format($product->selling_price, 2) }}</td>
            <td>{{ $product->total_stock }} {{ $product->unit }}</td>
            {{-- The chart on the dashboard compares stock to this number, so
                 the list its "View All" opens has to actually show it -- a
                 "Low Stock" badge alone told you a product was under its
                 line, never by how much or where the line was. --}}
            <td>{{ $product->reorder_level }} {{ $product->unit }}</td>
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
                @endif
                @foreach($product->batches as $batch)
                    @if($batch->quantity > 0 && $batch->is_expired)
                        <span class="badge badge-critical">Expired Batch</span>
                    @elseif($batch->quantity > 0 && $batch->is_expiring_soon)
                        <span class="badge badge-warning">Expiring Soon</span>
                    @endif
                @endforeach
                {{-- Build the "· 98d left" suffix in PHP rather than with an
                     inline @if: Blade's directive regex uses \B, so an @if
                     written straight after a word character (Return@if) is
                     NOT compiled while its @endif is — which yields a fatal
                     "unexpected endif". --}}
                @if($product->has_returned_batches)
                    @php
                        $returnedBatch = $product->batches->filter(fn($b) => $b->returned_at)->sortByDesc('returned_at')->first();
                        $returnedOn = $returnedBatch ? ' · ' . $returnedBatch->returned_at->format('M d, Y') : '';
                    @endphp
                    <span class="badge badge-return-done" title="Sent back to the supplier">Returned{{ $returnedOn }}</span>
                @endif
                @if($product->is_medicine)
                    @if($product->needs_return)
                        @php
                            // Only batches actually inside the window, and the
                            // one closest to falling out of it.
                            $soonestReturn = $product->batches
                                ->filter(fn($b) => $b->needs_return && $b->return_days !== null && $b->return_days >= 0)
                                ->sortBy('return_days')->first();
                            $dueSuffix = $soonestReturn ? ' · ' . $soonestReturn->return_days . 'd left' : '';
                        @endphp
                        <span class="badge badge-return-due" title="90-120 days left before expiry">Need to Return{{ $dueSuffix }}</span>
                    @endif
                    @if($product->failed_return)
                        @php
                            // The worst offender: furthest past the deadline.
                            $worstReturn = $product->batches
                                ->filter(fn($b) => $b->failed_return && $b->return_days !== null)
                                ->sortBy('return_days')->first();
                            $lateSuffix = $worstReturn ? ' · ' . abs($worstReturn->return_days) . 'd overdue' : '';
                        @endphp
                        <span class="badge badge-return-late" title="Fewer than 90 days left before expiry, or already expired">Fail to Return{{ $lateSuffix }}</span>
                    @endif
                @elseif($product->needs_return)
                    @php
                        // Non-pharma has no supplier window, only the plain
                        // 10-day-before-expiry rule, so report days to expiry
                        // (or "expired") rather than a return-window figure —
                        // "Need to Return · 65d overdue" reads as a
                        // contradiction.
                        $npBatch = $product->batches
                            ->filter(fn($b) => $b->quantity > 0 && $b->expiry_date)
                            ->sortBy('expiry_date')->first();
                        $npSuffix = '';
                        if ($npBatch) {
                            $npSuffix = $npBatch->is_expired
                                ? ' · expired'
                                : ' · ' . max(0, (int) round(now()->diffInDays($npBatch->expiry_date, false))) . 'd to expiry';
                        }
                    @endphp
                    <span class="badge badge-return-due" title="Expired or within 10 days of expiry (default expiry rule)">Need to Return{{ $npSuffix }}</span>
                @endif
                @if(!$product->is_running_out && !$product->batches->contains(fn($b) => $b->quantity > 0 && ($b->is_expired || $b->is_expiring_soon)) && !$product->needs_return && !$product->failed_return)
                    <span class="badge badge-success">OK</span>
                @endif
                </div>
            </td>
            @if(auth()->user()->isAdmin())
                <td class="col-actions">
                    <div class="actions-cell">
                        {{-- Mark Returned lives here as well as on the product edit
                             page: this list is where you review what's due, so
                             acting on it shouldn't mean opening each product and
                             expanding its batch table. Targets the most urgent
                             batch — the one furthest through its return window. --}}
                        @php
                            // ProductBatch::$is_returnable applies the right
                            // rule for the product's category -- the 90-120 day
                            // supplier window for medicine, the plain expiry
                            // rule for everything else -- so a "Need to Return"
                            // badge always comes with a button to act on it.
                            $returnable = $product->batches
                                ->filter(fn($b) => $b->is_returnable)
                                ->sortBy('return_days')
                                ->first();
                        @endphp
                        @if($returnable)
                            <form method="POST" action="{{ route('batches.return', $returnable) }}"
                                  class="js-confirm"
                                  data-confirm-title="Mark this batch as returned?"
                                  data-confirm-body="Record batch {{ $returnable->batch_number }} of {{ $product->name }} as sent back to the supplier ({{ $returnable->return_days_label }}). It stops counting as sellable stock."
                                  data-confirm-label="Mark Returned"
                                  data-confirm-icon="ti-package-export"
                                  data-confirm-tone="neutral">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-success btn-sm" title="Batch {{ $returnable->batch_number }} &middot; {{ $returnable->return_days_label }}">
                                    Return
                                </button>
                            </form>
                        @else
                            {{-- Same element, just invisible: reserves the exact
                                 width a real Return button takes so Manage lands
                                 in the same column on every row rather than
                                 sliding left on rows with nothing to return. --}}
                            <button type="button" class="btn btn-success btn-sm" style="visibility:hidden;" aria-hidden="true" tabindex="-1">Return</button>
                        @endif
                        <a href="{{ route('products.edit', $product) }}" class="btn btn-info btn-sm"><i class="ti ti-pencil" aria-hidden="true"></i> Manage</a>
                    </div>
                </td>
            @endif
        </tr>
    @empty
        <tr><td colspan="{{ auth()->user()->isAdmin() ? 11 : 10 }}">No products match this filter.</td></tr>
    @endforelse
    </tbody>
</table></div>
