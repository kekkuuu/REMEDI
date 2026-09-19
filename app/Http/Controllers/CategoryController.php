<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->orderBy('name')->get();

        // Archived categories are listed apart, where Restore lives. Their
        // product counts are not shown: an archived category holds none.
        $archivedCategories = Category::onlyTrashed()->orderBy('name')->get();

        return view('products.categories', compact('categories', 'archivedCategories'));
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

    /**
     * Archive a category -- the replacement for Delete.
     *
     * Still refused while the category holds products, and for the same
     * reason as before: an archived category is hidden from the pickers and
     * the sidebar, and a product filed under a hidden category is a product
     * nobody can find or re-file. Move or archive the products first.
     *
     * What is different is the failure mode. `products.category_id` cascades
     * on delete, so the old code had to lock the row and re-check inside a
     * transaction to stop a concurrent product create being wiped along with
     * the category. Archiving deletes nothing, so a product that slips in
     * between the check and the stamp is merely filed under an archived
     * category (Product::category() reads it withTrashed, so it still shows
     * its name) instead of being destroyed. The lock stays anyway -- it is
     * cheap, and it keeps the check meaningful.
     */
    public function destroy(Request $request, Category $category)
    {
        return DB::transaction(function () use ($request, $category) {
            $locked = Category::where('id', $category->id)->lockForUpdate()->first();

            if (! $locked || $locked->products()->exists()) {
                return $this->actionFailed($request, 'Cannot archive a category that has products.', 'category');
            }

            $name = $locked->name;
            $locked->delete();

            // Logged AFTER the archive succeeds, never before -- see
            // ProductController::destroy().
            AuditTrail::log('Archived', "Archived category: {$name}");
            Cache::forget('sidebar_categories');

            return $this->actionOk($request, "Category \"{$name}\" archived.", back());
        });
    }

    /** Bring an archived category back. */
    public function restore(Request $request, Category $category)
    {
        abort_unless($category->trashed(), 404);

        $category->restore();

        AuditTrail::log('Restored', "Restored category: {$category->name}");
        Cache::forget('sidebar_categories');

        return $this->actionOk($request, "Category \"{$category->name}\" restored.", back());
    }
}
