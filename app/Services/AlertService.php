<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that decides what "an open alert" is.
 *
 * The topbar bell, its dropdown and the /alerts polling endpoint all read this,
 * so the badge can never disagree with the list it opens -- which is the whole
 * reason it is a service rather than three copies of the same filter.
 *
 * The dashboards build their own Alerts panels from figures they already have
 * in hand (they load every batch anyway), so they deliberately do NOT call this
 * -- going through the cache there would cost a second pass over the same data.
 * Keep the two lists in step: same five kinds, same thresholds, same links.
 */
class AlertService
{
    public const CACHE_KEY = 'topbar_alerts';

    /**
     * 30 seconds, not the 10 minutes this used to sit at.
     *
     * The bell polls, so the cache TTL is the worst-case staleness a user sees.
     * The computation hydrates every in-stock batch and every product because
     * low stock / return state are computed accessors, not columns -- but it is
     * one computation per window shared by every signed-in user, and checkout
     * clears it outright (see forget()), so a sale shows up immediately rather
     * than up to 30s later.
     */
    public const TTL_SECONDS = 30;

    /**
     * How many individual notifications each kind contributes to the bell.
     *
     * Three apiece keeps the panel scannable and guarantees every kind that has
     * something is represented, which a single urgency-sorted list would not:
     * low stock outnumbers everything else by an order of magnitude.
     */
    public const PER_KIND = 3;

    /**
     * @return array{count:int, items:list<array<string,mixed>>, alerts:list<array<string,mixed>>, generated_at:string}
     */
    public function payload(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(self::TTL_SECONDS), function () {
            $today = today();
            $soon = $today->copy()->addDays(ProductBatch::EXPIRY_SOON_DAYS);

            // 120 days is the outer edge of the medicine return window, so it
            // bounds every expiry-driven state below. Same trick, and the same
            // number, as InventoryController's whereHas.
            $batches = ProductBatch::with('product.category')
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $today->copy()->addDays(120))
                ->get();

            // Keep the collections, not just their counts: the bell lists the
            // actual items now ("EFFICASCENT OINMENT 10g expires in 4 days"),
            // and re-filtering the same set twice to get both would double the
            // only expensive part of this method.
            $expiringBatches = $batches->filter(fn ($b) => $b->expiry_date->between($today, $soon))
                ->sortBy('expiry_date')->values();
            $expiredBatches = $batches->filter(fn ($b) => $b->is_expired)
                ->sortBy('expiry_date')->values();
            $dueBatches = $batches->filter(fn ($b) => $b->is_returnable)
                ->sortBy('return_days')->values();

            $expiring = $expiringBatches->count();
            $expired = $expiredBatches->count();
            $due = $dueBatches->count();
            $missed = $batches->filter(fn ($b) => $b->product?->is_medicine && $b->failed_return)->count();

            $lowProducts = Product::with('batches')->get()
                ->filter(fn ($p) => $p->is_low_stock)
                // Deepest below its own reorder line first -- "0 of 20" is a
                // more urgent notification than "19 of 20".
                ->sortBy(fn ($p) => $p->total_stock - $p->reorder_level)
                ->values();

            $low = $lowProducts->count();

            $alerts = collect([
                [
                    'kind' => 'low_stock',
                    'count' => $low,
                    'cls' => 'is-low',
                    'icon' => 'ti-alert-triangle',
                    'title' => 'Low stock alert',
                    'short' => 'low stock',
                    'body' => $low.' '.str('product')->plural($low).' at or below reorder level',
                    'filter' => 'low_stock',
                ],
                [
                    'kind' => 'expiring',
                    'count' => $expiring,
                    'cls' => 'is-expiring',
                    'icon' => 'ti-clock-exclamation',
                    'title' => 'Expiring soon',
                    'short' => 'expiring soon',
                    'body' => $expiring.' '.str('batch')->plural($expiring).' expire within 30 days',
                    'filter' => 'expiring',
                ],
                [
                    'kind' => 'expired',
                    'count' => $expired,
                    'cls' => 'is-expired',
                    'icon' => 'ti-alert-octagon',
                    'title' => 'Expired stock',
                    'short' => 'expired',
                    'body' => $expired.' expired '.str('batch')->plural($expired).' still in stock',
                    'filter' => 'expired',
                ],
                [
                    'kind' => 'need_to_return',
                    'count' => $due,
                    'cls' => 'is-return',
                    'icon' => 'ti-package-export',
                    'title' => 'Return window open',
                    'short' => 'returnable',
                    'body' => $due.' '.str('batch')->plural($due).' can still go back to the supplier',
                    'filter' => 'need_to_return',
                ],
                [
                    'kind' => 'fail_to_return',
                    'count' => $missed,
                    'cls' => 'is-missed',
                    'icon' => 'ti-calendar-x',
                    'title' => 'Return window missed',
                    'short' => 'missed returns',
                    'body' => $missed.' '.str('batch')->plural($missed).' missed the return window',
                    'filter' => 'fail_to_return',
                ],
            ])
                ->filter(fn ($a) => $a['count'] > 0)
                ->map(fn ($a) => $a + ['href' => route('inventory.index', ['filter' => $a['filter']])])
                ->values()
                ->all();

            // ── Item-level notifications ──
            //
            // The bell reads as a notification feed, so it names things:
            // "EFFICASCENT OINMENT 10g — expires in 4 days", not "31 batches
            // expire within 30 days". The summary above still drives the
            // "view all" footers, so the totals never disappear.
            //
            // A fixed slice per kind rather than one big sorted list: with 645
            // low-stock products, a pure urgency sort would fill the panel with
            // low stock and bury the two expired batches that actually need
            // pulling off the shelf today. Every kind that has anything gets
            // seen.
            $items = collect()
                ->concat($expiredBatches->take(self::PER_KIND)->map(fn ($b) => [
                    'kind' => 'expired',
                    'cls' => 'is-expired',
                    'icon' => 'ti-alert-octagon',
                    'title' => $b->product->name ?? 'Unknown product',
                    'body' => 'Expired '.$b->expiry_date->diffForHumans(['parts' => 1]).' · batch '.$b->batch_number,
                    'href' => route('inventory.index', ['filter' => 'expired']),
                ]))
                ->concat($expiringBatches->take(self::PER_KIND)->map(fn ($b) => [
                    'kind' => 'expiring',
                    'cls' => 'is-expiring',
                    'icon' => 'ti-clock-exclamation',
                    'title' => $b->product->name ?? 'Unknown product',
                    'body' => 'Expires '.$b->expiry_date->format('M j, Y').' · '.$b->expiry_label,
                    'href' => route('inventory.index', ['filter' => 'expiring']),
                ]))
                ->concat($dueBatches->take(self::PER_KIND)->map(fn ($b) => [
                    'kind' => 'need_to_return',
                    'cls' => 'is-return',
                    'icon' => 'ti-package-export',
                    'title' => $b->product->name ?? 'Unknown product',
                    // Just the label: return_days_label reads "12d left" for a
                    // medicine inside its window but "230d overdue" for an
                    // expired non-pharma batch, so a "can still go back to the
                    // supplier" lead-in contradicted half of them.
                    'body' => 'Return to supplier · '.$b->return_days_label,
                    'href' => route('inventory.index', ['filter' => 'need_to_return']),
                ]))
                ->concat($lowProducts->take(self::PER_KIND)->map(fn ($p) => [
                    'kind' => 'low_stock',
                    'cls' => 'is-low',
                    'icon' => 'ti-alert-triangle',
                    'title' => $p->name,
                    'body' => $p->total_stock.' '.$p->unit.' left · reorder at '.$p->reorder_level,
                    'href' => route('inventory.index', ['filter' => 'low_stock']),
                ]))
                ->values()
                ->all();

            return [
                // The badge counts the notifications actually listed, so it
                // matches what opening the panel shows. It is deliberately NOT
                // the row total: 645 low-stock products is not 645 things to
                // read, and a badge that can only ever say "9+" tells you
                // nothing. The per-kind totals live on the footer links.
                'count' => count($items),
                'items' => $items,
                // Kind-level rollup, still used for the "View all N" footers.
                'alerts' => $alerts,
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Drop the cached payload so the next poll recomputes.
     *
     * Called wherever stock moves (checkout, batch edits). Without this the
     * bell would lag a sale by up to TTL_SECONDS, which is exactly the kind of
     * "why does it still say 645" that makes a live badge worse than none.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
