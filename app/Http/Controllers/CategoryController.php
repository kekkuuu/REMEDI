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
        // products.category_id is ON DELETE CASCADE (unlike sales.user_id,
        // which was hardened to RESTRICT), so the exists() check below is the
        // ONLY thing standing between this action and silently wiping every
        // product -- and its batches, also cascading -- in a category that
        // looked empty a moment ago. An unguarded check-then-delete leaves a
        // window: a product created in this category between the check and
        // the delete is destroyed with it, with none of destroy()'s own
        // guards (sold-count, sales_history, its own audit entry) ever
        // running. lockForUpdate() closes that window the same way
        // Sale::nextTransactionNo() closes its own race -- InnoDB's FK
        // implementation takes a lock on the referenced parent row before a
        // child INSERT can proceed, so a concurrent product create blocks
        // until this transaction commits or rolls back.
        return DB::transaction(function () use ($request, $category) {
            $locked = Category::where('id', $category->id)->lockForUpdate()->first();

            if (! $locked || $locked->products()->exists()) {
                return $this->actionFailed($request, 'Cannot delete a category that has products.', 'category');
            }

            $name = $locked->name;
            $locked->delete();

            // Logged AFTER the delete succeeds, never before -- see
            // ProductController::destroy() for why: an entry asserting a
            // deletion that did not happen is worse than the crash it
            // would otherwise accompany.
            AuditTrail::log('Deleted', "Deleted category: {$name}");
            Cache::forget('sidebar_categories');

            return $this->actionOk($request, "Category \"{$name}\" deleted successfully.", back());
        });
    }
}
