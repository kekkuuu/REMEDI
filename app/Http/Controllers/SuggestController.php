<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead sources for the search boxes across the app.
 *
 * Every endpoint answers the same shape so one shared front-end component
 * (REMEDI.attachSuggest) can drive all of them:
 *
 *     { results: [ { label, meta, value }, ... ] }
 *
 * `value` is what gets written into the input when a suggestion is chosen —
 * usually the same as the label, but for products it's the exact name so the
 * subsequent search matches one row rather than a fuzzy set.
 */
class SuggestController extends Controller
{
    private const LIMIT = 8;

    /** Minimum term length; shorter queries match too much to be useful. */
    private function term(Request $request): ?string
    {
        $q = trim((string) $request->get('q', ''));

        return mb_strlen($q) >= 2 ? $q : null;
    }

    private function empty(): JsonResponse
    {
        return response()->json(['results' => []]);
    }

    /** Products by name, SKU or barcode — used by POS, Inventory, Products, Forecast. */
    public function products(Request $request): JsonResponse
    {
        if (! $q = $this->term($request)) {
            return $this->empty();
        }

        // likeTerm() escapes the user's own % and _ — see Controller.
        $like = $this->likeTerm($q);

        $rows = Product::query()
            ->with('category')
            ->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like))
            // Prefix matches first: typing "bio" should surface BIOGESIC
            // before something that merely contains "bio" mid-string.
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$q . '%'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'results' => $rows->map(fn ($p) => [
                'label' => $p->name,
                'meta' => $p->sku,
                'value' => $p->name,
                'id' => $p->id,
                'sku' => $p->sku,
                'price' => (float) $p->selling_price,
                'stock' => (int) $p->total_stock,
                'category' => $p->category->name ?? null,
            ])->values(),
        ]);
    }

    /** Sales by transaction number or cashier name. */
    public function sales(Request $request): JsonResponse
    {
        if (! $q = $this->term($request)) {
            return $this->empty();
        }

        // likeTerm() escapes the user's own % and _ — see Controller.
        $like = $this->likeTerm($q);

        $query = Sale::query()->with('user');

        // Same role scope SaleController applies to the list and the detail
        // page. This endpoint had none, so a staff account could read back
        // every cashier's transaction numbers, names and dates -- 8 results
        // against the 2 sales /sales will actually show them -- and could
        // enumerate a named colleague's takings by searching that name, since
        // the filter below matches on the cashier too.
        //
        // The scope sits OUTSIDE the search closure on purpose: it has to be
        // `user_id = X AND (transaction_no LIKE … OR cashier LIKE …)`. Folded
        // in, the OR would escape it and the guard would do nothing.
        //
        // No part of the UI currently fetches this route -- REMEDI.attachSuggest
        // only dispatches `suggest:live` and never calls the URL -- but the
        // route is registered and sits behind `auth` + `active` alone, so any
        // signed-in staff member can request it directly. Unused endpoints are
        // exactly where an authorisation gap survives unnoticed.
        if ($request->user()->isStaff()) {
            $query->where('user_id', $request->user()->id);
        }

        $rows = $query
            ->where(fn ($w) => $w->where('transaction_no', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)))
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'results' => $rows->map(fn ($s) => [
                'label' => $s->transaction_no,
                'meta' => ($s->user->name ?? 'N/A') . ' · ' . $s->created_at->format('M d, Y'),
                'value' => $s->transaction_no,
                'id' => $s->id,
            ])->values(),
        ]);
    }

    /** Users by name or email. */
    public function users(Request $request): JsonResponse
    {
        if (! $q = $this->term($request)) {
            return $this->empty();
        }

        // likeTerm() escapes the user's own % and _ — see Controller.
        $like = $this->likeTerm($q);

        $rows = User::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'results' => $rows->map(fn ($u) => [
                'label' => $u->name,
                'meta' => $u->email,
                'value' => $u->name,
                'id' => $u->id,
            ])->values(),
        ]);
    }

    /** Distinct audit actors/actions, so the log can be filtered by either. */
    public function audit(Request $request): JsonResponse
    {
        if (! $q = $this->term($request)) {
            return $this->empty();
        }

        // likeTerm() escapes the user's own % and _ — see Controller.
        $like = $this->likeTerm($q);

        $rows = AuditTrail::query()
            ->where(fn ($w) => $w->where('username', 'like', $like)
                ->orWhere('details', 'like', $like))
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'results' => $rows->map(fn ($a) => [
                'label' => $a->username,
                'meta' => \Illuminate\Support\Str::limit((string) $a->details, 44),
                'value' => $a->username,
            ])->unique('label')->values(),
        ]);
    }
}
