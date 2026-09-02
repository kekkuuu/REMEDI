@extends('layouts.app')

@section('title', 'Add Product')

@section('content')
{{-- Back beside the title: the shared .page-head pattern, defined in
     layouts/app.blade.php. The page previously had no heading at all, so the
     back button sat above a bare form card with nothing to sit beside. --}}
<div class="page-head">
    <a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <div class="page-head-text">
        <h3>Add Product</h3>
        <p>Create a new catalogue entry. Stock is added afterwards, as batches.</p>
    </div>
</div>

{{-- .form-card / .form-grid / .form-chip are defined once in layouts/app so
     this page, Edit Product, Add User and Edit User all read the same way. Each
     field is introduced by an icon chip -- neutral, not tinted: two columns of
     similar-looking inputs are hard to scan by label alone, and the mark is
     what makes a field findable without reading every one. --}}
<div class="form-card">
    {{-- The same filled band Edit Product carries, so the two product pages
         open the same way. --}}
    <div class="section-head">
        <h4>Product Details</h4>
    </div>

    <form method="POST" action="{{ route('products.store') }}"
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-package"
          data-confirm-title="Add this product?" data-confirm-body="The product will be added to the catalogue and appear in Inventory."
          data-confirm-label="Add product">
        @csrf

        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-tag" aria-hidden="true"></i></span>
                    <label for="name">Product Name</label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Enter product name" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-barcode" aria-hidden="true"></i></span>
                    <label for="sku">SKU (Product Code)</label>
                </div>
                <input type="text" id="sku" name="sku" value="{{ old('sku') }}"
                       placeholder="Enter SKU or product code" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-category" aria-hidden="true"></i></span>
                    <label for="category_id">Category</label>
                </div>
                <select id="category_id" name="category_id" required>
                    <option value="">-- Select Category --</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-box" aria-hidden="true"></i></span>
                    <label for="unit">Unit</label>
                </div>
                {{-- A list, not free text. This field used to default to
                     lowercase "pcs" while every row in the catalogue says
                     "PCS", so each product added here started a second spelling
                     of the same unit. See Product::UNITS. --}}
                <select id="unit" name="unit" required>
                    @foreach(\App\Models\Product::unitOptions() as $unitOption)
                        <option value="{{ $unitOption }}" {{ old('unit', 'PCS') === $unitOption ? 'selected' : '' }}>{{ $unitOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-coin" aria-hidden="true"></i></span>
                    <label for="selling_price">Selling Price (&#8369;)</label>
                </div>
                <input type="number" step="0.01" id="selling_price" name="selling_price"
                       value="{{ old('selling_price') }}" placeholder="0.00" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip"><i class="ti ti-bell" aria-hidden="true"></i></span>
                    <label for="reorder_level">Reorder Level (Low Stock Threshold)</label>
                </div>
                <input type="number" id="reorder_level" name="reorder_level"
                       value="{{ old('reorder_level', 10) }}" required>
                <span class="form-field-note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    Alerts fire when sellable stock falls to this level.
                </span>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-device-floppy" aria-hidden="true"></i> Save Product
            </button>
            <a href="{{ route('products.index') }}" class="btn btn-secondary btn-lg">
                <i class="ti ti-x" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </form>
</div>
@endsection
