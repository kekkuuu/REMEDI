<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Print Barcodes (2026-09-30, at the user's request): pick products, set how
 * many labels of each, print a sheet.
 *
 * Why this exists: labels made in Word with a Code 39 font and no `*` start /
 * stop characters look like barcodes and cannot be read by anything. Here the
 * bars are DRAWN (JsBarcode) as CODE 128 of the product's SKU, exactly as
 * stored -- not EAN-13, because many SKUs here do not carry a valid EAN check
 * digit, and an EAN encoder would silently print a different number. The
 * camera scanner and a scanner gun both read CODE 128, and the scan resolves
 * through the same SKU lookup as a factory barcode.
 *
 * Nothing is written: printing a label changes no record.
 */
class BarcodeController extends Controller
{
    /** One sheet's worth, and a bound on "add a whole category". */
    public const MAX_PRODUCTS = 300;

    public function index(Request $request): View
    {
        $request->validate(['product' => ['nullable', 'integer']]);

        $preselected = $request->filled('product')
            ? Product::whereKey($request->integer('product'))->get(['id', 'name', 'sku', 'selling_price'])
            : collect();

        return view('barcodes.index', [
            'categories' => Category::orderBy('name')->withCount('products')->get(['id', 'name']),
            'preselected' => $preselected->map(fn (Product $p) => $this->row($p))->values(),
            'maxProducts' => self::MAX_PRODUCTS,
        ]);
    }

    /** Every active product in one category, for the "Add all" button. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['bail', 'required', 'integer', Rule::exists('categories', 'id')->whereNull('archived_at')],
        ]);

        $products = Product::where('category_id', $data['category_id'])
            ->orderBy('name')
            ->limit(self::MAX_PRODUCTS)
            ->get(['id', 'name', 'sku', 'selling_price']);

        return response()->json([
            'products' => $products->map(fn (Product $p) => $this->row($p))->values(),
        ]);
    }

    private function row(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => (string) $p->sku,
            'price' => (float) $p->selling_price,
        ];
    }
}
