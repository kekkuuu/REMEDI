<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Http\Request;

class ProductController extends Controller
{
public function index(Request $request)
{
    $query = Product::with('category', 'batches');

    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%");
        });
    }

    if ($request->filled('category_id')) {
        $query->where('category_id', $request->category_id);
    }

    $products = $query->orderBy('name')->paginate(10)->withQueryString();
    $categories = Category::orderBy('name')->get();

    if ($request->wantsJson() || $request->ajax()) {
        return response()->json([
            'html' => view('products._rows', compact('products'))->render(),
            'pagination' => (string) $products->links(),
        ]);
    }

    return view('products.index', compact('products', 'categories'));
}

    public function create()
    {
        $categories = Category::orderBy('name')->get();
        return view('products.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'category_id' => 'required|exists:categories,id',
            'unit' => 'required|string|max:20',
            'selling_price' => 'required|numeric|min:0',
            'reorder_level' => 'required|integer|min:0',
        ]);

        $product = Product::create($validated);
        AuditTrail::log('Created', "Added product: {$product->name} (SKU: {$product->sku})");

        return redirect()->route('products.index')->with('success', 'Product added successfully.');
    }

    public function edit(Product $product)
    {
        $categories = Category::orderBy('name')->get();

        // Smart default for the "Add New Batch" form's expiry date: read
        // this product's typical shelf life off its most recently
        // received batch (days between received_date and expiry_date)
        // and project that forward from today, rather than reusing a
        // stale historical date. Staff can still edit it before
        // submitting — this just saves re-typing for the common case
        // where a product's shelf life is consistent batch to batch.
        $suggestedExpiryDate = null;
        $latestBatch = $product->batches()
            ->whereNotNull('received_date')
            ->whereNotNull('expiry_date')
            ->orderByDesc('received_date')
            ->first();

        if ($latestBatch) {
            $shelfLifeDays = $latestBatch->received_date->diffInDays($latestBatch->expiry_date);
            if ($shelfLifeDays > 0) {
                $suggestedExpiryDate = now()->addDays($shelfLifeDays);
            }
        }

        return view('products.edit', compact('product', 'categories', 'suggestedExpiryDate'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:50|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string|max:100|unique:products,barcode,' . $product->id,
            'category_id' => 'required|exists:categories,id',
            'unit' => 'required|string|max:20',
            'selling_price' => 'required|numeric|min:0',
            'reorder_level' => 'required|integer|min:0',
        ]);

        $product->update($validated);
        AuditTrail::log('Updated', "Updated product: {$product->name}");

        return redirect()->route('products.index')->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        AuditTrail::log('Deleted', "Deleted product: {$product->name}");
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }

    // ===== Batch Management =====

    public function addBatch(Request $request, Product $product)
    {
        $validated = $request->validate([
            'batch_number' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'expiry_date' => 'required|date|after:today',
            'received_date' => 'required|date',
        ]);

        $validated['product_id'] = $product->id;
        ProductBatch::create($validated);

        AuditTrail::log('Created', "Added batch '{$validated['batch_number']}' ({$validated['quantity']} units) for {$product->name}");

        return back()->with('success', 'Batch added successfully.');
    }

    public function updateBatch(Request $request, ProductBatch $batch)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:0',
            'expiry_date' => 'nullable|date',
        ]);

        $batch->update($validated);
        AuditTrail::log('Updated', "Updated batch '{$batch->batch_number}' for {$batch->product->name}");

        return back()->with('success', 'Batch updated successfully.');
    }

    public function destroyBatch(ProductBatch $batch)
    {
        $name = $batch->product->name;
        $batchNumber = $batch->batch_number;

        AuditTrail::log('Deleted', "Removed batch '{$batchNumber}' from {$name}");
        $batch->delete();

        return back()->with('success', 'Batch removed successfully.');
    }

    // Mark a batch as physically returned to the supplier. Only meaningful
    // once it's inside (or has missed) the return window — see
    // ProductBatch::getReturnStatusAttribute().
    public function markBatchReturned(Request $request, ProductBatch $batch)
    {
        $batch->update([
            'returned_at' => now(),
            'returned_by' => $request->user()->id,
        ]);

        AuditTrail::log('Updated', "Marked batch '{$batch->batch_number}' of {$batch->product->name} as returned to supplier");

        return back()->with('success', 'Batch marked as returned.');
    }
}
