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
        // Its own error bag: the rename action below validates a field called
        // `name` too, and the page has to know WHICH form bounced -- the Add
        // dialog opens itself when this bag is non-empty, and must not open
        // because a rename in the table failed.
        $request->validateWithBag('addCategory', [
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        $category = Category::create($request->only('name'));
        AuditTrail::log('Created', "Added category: {$category->name}");
        Cache::forget('sidebar_categories');

        return $this->actionOk($request, "Category \"{$category->name}\" added successfully.", back());
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,'.$category->id,
        ]);

        // A rename looks like a cosmetic tidy-up, but Product::is_medicine
        // matches on this category's NAME, so renaming the pharmaceutical
        // category reclassifies every product in it. Measured here: 1,393
        // products stop being medicine, all 75 "Return window missed" alerts
        // disappear (Product::failed_return returns false for non-medicine),
        // and ten more batches become "returnable" under the 10-day non-pharma
        // rule instead of the 90-120 day supplier window.
        //
        // None of that surfaces anywhere -- no error, and the audit trail just
        // records a rename. Refuse it, and say why. See Category::MEDICINE for
        // the durable fix (a stable key, so the display name stops being
        // load-bearing).
        if ($category->drivesBusinessRules() && $request->input('name') !== $category->name) {
            return $this->actionFailed(
                $request,
                "\"{$category->name}\" cannot be renamed — the supplier return rules for medicine "
                .'are matched against this exact name, and renaming it would silently reclassify '
                .'every product in it.',
                'name'
            );
        }

        $category->update($request->only('name'));
        AuditTrail::log('Updated', "Renamed category to: {$category->name}");
        Cache::forget('sidebar_categories');

        return $this->actionOk($request, "Category renamed to \"{$category->name}\".", back());
    }

    public function destroy(Request $request, Category $category)
    {
        if ($category->products()->exists()) {
            return $this->actionFailed($request, 'Cannot delete a category that has products.', 'category');
        }

        $name = $category->name;

        AuditTrail::log('Deleted', "Deleted category: {$name}");
        $category->delete();
        Cache::forget('sidebar_categories');

        return $this->actionOk($request, "Category \"{$name}\" deleted successfully.", back());
    }
}
