@extends('layouts.app')

@section('title', 'Add Product')

@section('content')
<div class="page-back">
    <a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
</div>

<div class="card">
    <form method="POST" action="{{ route('products.store') }}">
        @csrf
        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:16px;">
            <div>
                <label>Product Name</label><br>
                <input type="text" name="name" value="{{ old('name') }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>SKU (Product Code)</label><br>
                <input type="text" name="sku" value="{{ old('sku') }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Category</label><br>
                <select name="category_id" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    <option value="">-- Select Category --</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Unit (e.g., pcs, box, bottle)</label><br>
                <input type="text" name="unit" value="{{ old('unit', 'pcs') }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Selling Price (₱)</label><br>
                <input type="number" step="0.01" name="selling_price" value="{{ old('selling_price') }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <div>
                <label>Reorder Level (Low Stock Threshold)</label><br>
                <input type="number" name="reorder_level" value="{{ old('reorder_level', 10) }}" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
        </div>
        <div style="margin-top:20px;">
            <button type="submit" class="btn btn-primary">Save Product</button>
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
