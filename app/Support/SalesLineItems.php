<?php

namespace App\Support;

use App\Models\SalesHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Sales Report's line-by-line sheet (2026-10-15, at the user's request:
 * "make the sales report like this" -- the transaction file's own columns).
 *
 * Two records, one layout:
 * - TILL sales are real lines: the transaction number, the time, the price
 *   charged. A line FEFO split across batches is ONE line here (grouped on
 *   sale + product + price, the rule every list of a sale's lines follows).
 *   Voided sales are left out, as in every revenue figure.
 * - The IMPORTED record has no orders and no times, only units per product per
 *   day, so each of its rows is one line marked "Imported", dated without a
 *   time and priced at the CURRENT selling price -- the same price every
 *   revenue figure in the app puts on it, so these lines add up to the
 *   report's Total Sales.
 *
 * Cost is the product's cost_price (what Revenue Today uses); a product with
 * none has a blank cost, line cost and profit rather than a zero that would
 * read as pure profit.
 *
 * Bounded by MAX_LINES: the imported record is ~385 product lines a day, and
 * an Excel file is built in memory and must stay under Vercel's ~4.5 MB
 * response limit. Over the cap the LATEST lines are kept and the sheet says so.
 */
final class SalesLineItems
{
    public const MAX_LINES = 40000;

    public const HEADINGS = [
        'Order#', 'Date / Time', 'Product ID', 'Product Name', 'SKU / Barcode', 'Category', 'Unit',
        'Quantity', 'Unit Price', 'Unit Cost', 'Line Total', 'Line Cost', 'Gross Profit',
    ];

    /**
     * @return array{lines: array<int, array>, total: int, shown: int}
     */
    public static function between(string $start, string $end, ?int $categoryId, ?string $sku, ?int $cashierId, int $cap = self::MAX_LINES): array
    {
        $end = min($end, SalesHistory::reportableThrough());

        $till = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('s.payment_voided', false)
            ->whereBetween('s.created_at', [$start.' 00:00:00', $end.' 23:59:59'])
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($sku, fn ($q) => $q->where('p.sku', $sku))
            ->when($cashierId, fn ($q) => $q->where('s.user_id', $cashierId))
            ->groupBy('s.id', 's.transaction_no', 's.created_at', 'si.product_id', 'si.price',
                'p.id', 'p.name', 'p.sku', 'p.unit', 'p.cost_price', 'c.name')
            ->select('s.transaction_no', 's.created_at', 'p.id as product_id', 'p.name', 'p.sku', 'p.unit',
                'p.cost_price', 'c.name as category', 'si.price')
            ->selectRaw('SUM(si.quantity) as qty, SUM(si.subtotal) as line_total');

        // A cashier narrows the whole report to their till sales: the imported
        // record has no cashier (see the Sales Report's cashier note).
        //
        // sales_history is read ON ITS OWN, by its sale_date index, and matched
        // to products with a subquery rather than a join: joined first, the
        // all-time "latest N lines" query took 68 s (measured 2026-10-15). The
        // product details are attached to the kept lines afterwards. Rows whose
        // SKU matches no product are left out, as the report's own totals do.
        $skus = DB::table('products')->select('sku')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($sku, fn ($q) => $q->where('sku', $sku));
        $history = $cashierId ? null : DB::table('sales_history')
            ->whereBetween('sale_date', [$start, $end])
            ->whereIn('product_sku', $skus);

        $total = DB::query()->fromSub($till, 't')->count() + ($history ? (clone $history)->count() : 0);

        // The latest `cap` of each, merged, then the latest `cap` of those.
        $tillRows = (clone $till)->orderByDesc('s.created_at')->orderBy('s.id')->limit($cap)->get()
            ->map(fn ($r) => self::line(
                $r->transaction_no, Carbon::parse($r->created_at), true, $r,
                (int) $r->qty, (float) $r->price, round((float) $r->line_total, 2)
            ));

        $historyRows = collect();
        if ($history) {
            $kept = $history->orderByDesc('sale_date')->orderByDesc('id')->limit($cap)
                ->get(['sale_date', 'product_sku', 'quantity_sold']);
            $products = DB::table('products as p')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereIn('p.sku', $kept->pluck('product_sku')->unique()->values())
                ->get(['p.id as product_id', 'p.name', 'p.sku', 'p.unit', 'p.cost_price', 'p.selling_price', 'c.name as category'])
                ->keyBy(fn ($p) => (string) $p->sku);

            $historyRows = $kept->map(function ($h) use ($products) {
                $p = $products[(string) $h->product_sku];
                $price = (float) $p->selling_price;

                return self::line('Imported', Carbon::parse($h->sale_date), false, $p,
                    (int) $h->quantity_sold, $price, round($h->quantity_sold * $price, 2));
            });
        }

        $lines = $tillRows->concat($historyRows)
            ->sortByDesc('_at')->take($cap)
            ->sortBy([['_at', 'asc'], ['_order', 'asc']])
            ->map(function ($l) {
                unset($l['_at'], $l['_order']);

                return array_values($l);
            })
            ->values()->all();

        return ['lines' => $lines, 'total' => $total, 'shown' => count($lines)];
    }

    private static function line(string $order, Carbon $at, bool $hasTime, object $r, int $qty, float $price, float $lineTotal): array
    {
        $cost = $r->cost_price === null ? null : (float) $r->cost_price;
        $lineCost = $cost === null ? null : round($qty * $cost, 2);

        return [
            '_at' => $at->format($hasTime ? 'Y-m-d H:i:s' : 'Y-m-d 00:00:00'),
            '_order' => $order.'|'.$r->name,
            'order' => $order,
            'when' => $at->format($hasTime ? 'm/d/Y h:i:s A' : 'm/d/Y'),
            'product_id' => 'PRD-'.str_pad((string) $r->product_id, 5, '0', STR_PAD_LEFT),
            'name' => $r->name,
            'sku' => (string) $r->sku,
            'category' => $r->category ?? '—',
            'unit' => $r->unit,
            'qty' => $qty,
            'price' => $price,
            'cost' => $cost,
            'line_total' => $lineTotal,
            'line_cost' => $lineCost,
            'profit' => $lineCost === null ? null : round($lineTotal - $lineCost, 2),
        ];
    }
}
