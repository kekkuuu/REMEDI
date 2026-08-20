@extends('layouts.app')

@section('title', 'Manage Categories')

@section('content')
<div class="page-back">
    <a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
</div>

<div>
    <div class="card" style="margin-bottom:20px;">
        <h4>Add New Category</h4>
        <form method="POST" action="{{ route('categories.store') }}" style="display:flex; gap:8px; max-width:500px;">
            @csrf
            <input type="text" name="name" placeholder="Category name" required style="flex:1; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <button type="submit" class="btn btn-primary">Add</button>
        </form>
    </div>

    <div class="card">
        <div class="table-scroll"><table class="remedi-table">
            <thead><tr><th>Category</th><th># Products</th><th>Actions</th></tr></thead>
            <tbody>
            @foreach($categories as $cat)
                <tr>
                    <td>
                        <form method="POST" action="{{ route('categories.update', $cat) }}" style="display:flex; gap:4px; max-width:400px;">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $cat->name }}" style="flex:1; padding:4px; border:1px solid #d1d5db; border-radius:4px;">
                            <button type="submit" class="btn btn-secondary" style="padding:4px 8px;">Save</button>
                        </form>
                    </td>
                    <td>{{ $cat->products_count }}</td>
                    <td>
                        <form method="POST" action="{{ route('categories.destroy', $cat) }}" onsubmit="return confirm('Delete this category?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger" style="padding:4px 8px;">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
</div>
@endsection
