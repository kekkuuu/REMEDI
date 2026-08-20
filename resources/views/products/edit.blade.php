@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
<div class="page-back"><a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a></div>

{{-- Header mirrors products/create.blade.php: Back sits at the top of the
     page where it's reachable without scrolling past the batch tables,
     rather than buried under the form. --}}
<div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:20px;">
    {{-- Deliberately a fixed target, not url()->previous(): after a failed
         validation redirect the previous URL is this same edit page, so a
         "back" built on it would link to itself. --}}
    
    <div>
        <h3 style="margin:0; font-size:1.15rem;">{{ $product->name }}</h3>
        <span style="font-size:.85rem; color:#64748b;">SKU {{ $product->sku }}</span>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <h4>Product Details</h4>
    <form method="POST" action="{{ route('products.update', $product) }}">
        @csrf
        @method('PUT')
        {{-- Auto-fit columns: the form fills the width the card actually has
             instead of a fixed 600px column, and collapses to one column on
             narrow screens without a media query. --}}
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px;">
            <div>
                <label>Product Name</label><br>
                <input type="text" name="name" value="{{ old('name', $product->name) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>SKU</label><br>
                <input type="text" name="sku" value="{{ old('sku', $product->sku) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Category</label><br>
                <select name="category_id" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $product->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Unit</label><br>
                <input type="text" name="unit" value="{{ old('unit', $product->unit) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Selling Price (&#8369;)</label><br>
                <input type="number" step="0.01" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Reorder Level</label><br>
                <input type="number" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
        </div>

        <div style="margin-top:18px; display:flex; gap:10px;">
            <button type="submit" class="btn btn-primary">Update Product</button>
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:20px;">
    <h4>Add New Batch</h4>
    <form method="POST" action="{{ route('products.batches.store', $product) }}" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        @csrf
        <div>
            <label>Batch Number</label><br>
            <input type="text" name="batch_number" required style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>
        <div>
            <label>Quantity</label><br>
            <input type="number" name="quantity" min="1" required style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>
        <div>
            <label>Received Date</label><br>
            <input type="date" name="received_date" value="{{ now()->format('Y-m-d') }}" required style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>
        <div>
            <label>Expiry Date</label><br>
            <input type="date" name="expiry_date" value="{{ old('expiry_date', $suggestedExpiryDate?->format('Y-m-d')) }}" required style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            @if($suggestedExpiryDate)
                <div style="font-size:11px; color:#94a3b8; margin-top:3px; max-width:180px;">Suggested from this product's usual shelf life — feel free to adjust.</div>
            @endif
        </div>
        <button type="submit" class="btn btn-success">Add Batch</button>
    </form>
</div>

@php
    $existingBatches = $product->batches()->orderBy('expiry_date')->get();
@endphp
<div class="card">
    {{-- Collapsed by default to keep the page from being dominated by a
         long batch history table — expand only when you actually need to
         inspect or manage individual batches/lots. --}}
    <details>
        <summary style="cursor:pointer; font-weight:600; font-size:1rem; list-style:revert;">
            Existing Batches ({{ $existingBatches->count() }})
        </summary>
        <div style="margin-top:14px;">
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr><th>Batch No.</th><th>Quantity</th><th>Received</th><th>Expiry</th><th>Status</th><th>Return Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        @forelse($existingBatches as $batch)
            <tr>
                <td>{{ $batch->batch_number }}</td>
                <td>
                    <form method="POST" action="{{ route('batches.update', $batch) }}" style="display:flex; gap:4px; align-items:center;">
                        @csrf
                        @method('PUT')
                        <input type="number" name="quantity" value="{{ $batch->quantity }}" min="0" style="width:70px; padding:4px; border:1px solid #d1d5db; border-radius:4px;">
                        <input type="hidden" name="expiry_date" value="{{ $batch->expiry_date?->format('Y-m-d') }}">
                        <button type="submit" class="btn btn-info" style="padding:4px 8px;">Update</button>
                    </form>
                </td>
                <td>{{ $batch->received_date?->format('M d, Y') ?? '—' }}</td>
                <td>{{ $batch->expiry_date?->format('M d, Y') ?? '—' }}</td>
                <td>
                    @if($batch->is_expired)
                        <span class="badge badge-danger">Expired</span>
                    @elseif($batch->is_expiring_soon)
                        <span class="badge badge-warning">Expiring Soon</span>
                    @else
                        <span class="badge badge-success">Good</span>
                    @endif
                </td>
                <td>
                    @if($product->is_medicine)
                        @if($batch->is_returned)
                            <span class="badge badge-return-done" title="Returned {{ $batch->returned_at->format('M d, Y') }}{{ $batch->returnedBy ? ' by ' . $batch->returnedBy->name : '' }}">Successfully Returned</span>
                        @elseif($batch->needs_return)
                            <span class="badge badge-return-due" title="90-120 days left before expiry">Need to Return &middot; {{ $batch->return_days_label }}</span>
                        @elseif($batch->failed_return)
                            <span class="badge badge-return-late" title="Fewer than 90 days left before expiry, or already expired">Fail to Return &middot; {{ $batch->return_days_label }}</span>
                        @else
                            <span style="color:#94a3b8; font-size:12px;">—</span>
                        @endif
                    @else
                        <span style="color:#94a3b8; font-size:12px;">N/A</span>
                    @endif
                </td>
                {{-- flex goes on an inner wrapper, never the <td>:
                     display:flex on a table cell drops it out of the table's
                     column model and the row stops lining up with its header. --}}
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    @if($batch->is_returnable)
                        <form method="POST" action="{{ route('batches.return', $batch) }}" style="margin:0;" onsubmit="return confirm('Mark this batch as returned to the supplier?');">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success" style="padding:4px 8px;">Mark Returned</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('batches.destroy', $batch) }}" style="margin:0;" onsubmit="return confirm('Remove this batch?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger" style="padding:4px 8px;">Remove</button>
                    </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No batches yet. Add one above.</td></tr>
        @endforelse
        </tbody>
    </table></div>
        </div>
    </details>
</div>
@endsection
