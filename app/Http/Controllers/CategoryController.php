<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->orderBy('name')->get();
        return view('products.categories', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        $category = Category::create($request->only('name'));
        AuditTrail::log('Created', "Added category: {$category->name}");
        Cache::forget('sidebar_categories');

        return back()->with('success', 'Category added successfully.');
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
        ]);

        $category->update($request->only('name'));
        AuditTrail::log('Updated', "Renamed category to: {$category->name}");
        Cache::forget('sidebar_categories');

        return back()->with('success', 'Category updated successfully.');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return back()->withErrors(['category' => 'Cannot delete a category that has products.']);
        }

        AuditTrail::log('Deleted', "Deleted category: {$category->name}");
        $category->delete();
        Cache::forget('sidebar_categories');

        return back()->with('success', 'Category deleted successfully.');
    }
}
