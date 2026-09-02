<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Carbon;
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
     * How many per kind the full notifications page lists.
     *
     * Not "all": low stock alone runs to 645 products, and a page that dumps
     * every one of them is the Inventory list with extra steps. Ten per kind
     * keeps each section scannable at a glance; the summary chips carry the
     * real totals and link to the filtered Inventory view, so nothing is
     * unreachable.
     */
    public const PAGE_PER_KIND = 10;

    /**
     * @return array{count:int, items:list<array<string,mixed>>, alerts:list<array<string,mixed>>, generated_at:string}
     */
    public function payload(int $perKind = self::PER_KIND): array
    {
        return Cache::remember(self::CACHE_KEY.':'.$perKind, now()->addSeconds(self::TTL_SECONDS), function () use ($perKind) {
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
            // ! is_expired, not between($today, $soon): the two kinds must be
            // mutually exclusive. between()'s lower bound includes today, and
            // a batch expiring today is already expired by
            // ProductBatch::is_expired -- so it was emitted twice, once as an
            // "Expiring soon" row and again as an "Expired stock" row, with
            // both kind totals counting it. Matches is_expiring_soon and the
            // Inventory page's filters, which have always gated this way.
            $expiringBatches = $batches->filter(fn ($b) => ! $b->is_expired && $b->expiry_date->lte($soon))
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
                // Same rule the Inventory low-stock tab filters on. This
                // alert LINKS there, so a different definition would make
                // the badge promise a number the list does not show.
                ->filter(fn ($p) => $p->is_running_out)
                // Deepest below its own reorder line first. This ordering is no
                // longer what picks the notification slice (see $recentLow
                // below) -- it is kept because the collection is also what the
                // count is taken from, and a stable order keeps that cheap.
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
                // The expiring alert counts a 30-day horizon, so its link has to
                // ask Inventory for the same one — otherwise the tab applies its
                // 90-day planning view and a row promising 28 batches opens a
                // list of 85. Shared with the nine dashboard links via
                // ProductBatch::expiringSoonUrl() so there is one definition.
                ->map(fn ($a) => $a + ['href' => $a['kind'] === 'expiring'
                    ? ProductBatch::expiringSoonUrl()
                    : route('inventory.index', ['filter' => $a['filter']], false)])
                ->values()
                ->all();

            /* Which items the feed SHOWS -- a separate question from how many
             * there are.
             *
             * Selection used to be by SEVERITY: the three deepest below their
             * reorder line, the three soonest to expire. That reads like a
             * priority queue, and it is the right rule for the Inventory page,
             * which still sorts exactly that way.
             *
             * It is the wrong rule for a notification feed, because severity is
             * STABLE. The same three worst products won the slice every time, so
             * a product that dropped below its line today never entered the list
             * at all -- and since the toasts raise a card for an item id they
             * have not seen before, its alert simply never fired. Nothing was
             * broken; the news was never selected.
             *
             * Recency is what makes this real-time: whatever just became true is
             * what has not been seen yet. It is also right for the calendar-driven
             * kinds, where it looks backwards at first glance -- the batch that
             * entered the 30-day window this morning is news, while the one
             * expiring on Friday entered it a month ago and has been reported
             * every day since.
             *
             * The per-kind totals in `alerts` are untouched, and the "View all N"
             * links still reach everything.
             */
            $recentExpired = $expiredBatches->sortByDesc('expiry_date')->values();
            $recentExpiring = $expiringBatches->sortByDesc('expiry_date')->values();
            $recentDue = $dueBatches->sortByDesc(fn ($b) => self::returnWindowOpenedAt($b))->values();
            // Last time any of this product's batches moved -- a checkout that
            // pushes it under the line writes updated_at, so the sale just rung
            // up is the most recent thing that happened.
            $recentLow = $lowProducts
                ->sortByDesc(fn ($p) => optional($p->batches->max('updated_at'))->getTimestamp() ?? 0)
                ->values();

            // ── Item-level notifications ──
            //
            // The bell reads as a notification feed, so it names things:
            // "EFFICASCENT OINMENT 10g — expires in 4 days", not "31 batches
            // expire within 30 days". The summary above still drives the
            // "view all" footers, so the totals never disappear.
            //
            // SELECTION is per kind, ORDER is newest first — two separate
            // decisions, and both are load-bearing.
            //
            // Selection stays capped at $perKind because with 645 low-stock
            // products, taking the top N of one merged list would fill the
            // panel with low stock and bury the two expired batches that
            // actually need pulling off the shelf today. Every kind that has
            // anything still gets seen. WHICH ones is now decided by recency
            // rather than severity — see the $recent* slices above.
            //
            // Order is then purely reverse-chronological over that slice (see
            // the sortByDesc below), so the panel reads as a feed: whatever
            // became true most recently is at the top, kinds interleaved. The
            // cap is what makes that safe — recency can only reshuffle rows
            // that already earned their place.

            // Where an individual notification points: the product it names,
            // not the filter it came from. Clicking "EFFICASCENT OINTMENT 10g —
            // expired 4 days ago" used to land on all 31 expired batches, and
            // finding the one you clicked was then your problem.
            //
            // Deliberately `search`, NOT `filter`: this payload is cached for
            // TTL_SECONDS and stock moves underneath it, so a filter that no
            // longer matches would open an empty list instead of the product
            // just clicked. InventoryController's search covers name/sku/
            // barcode, so the SKU is an exact hit. The fragment targets the row
            // id that inventory/_rows.blade.php already renders.
            //
            // Not products.edit: /products is inside the role:admin group and
            // the bell is shown to staff too, who would get a 403 from their
            // own notification.
            // Relative URLs throughout (route(..., false)). This whole payload
            // is CACHED, so an absolute URL freezes whichever host happened to
            // warm the cache — see the note above forget().
            $itemHref = function ($product, string $fallbackFilter) {
                if (! $product) {
                    return route('inventory.index', ['filter' => $fallbackFilter], false);
                }

                return route('inventory.index', ['search' => $product->sku], false)
                    .'#product-row-'.$product->id;
            };

            $items = collect()
                ->concat($recentExpired->take($perKind)->map(fn ($b) => [
                    // Stable per batch/product, NOT per position: the bell
                    // remembers which notifications have been read, and an
                    // index-based id would mark the wrong one the moment the
                    // list reorders.
                    'id' => 'expired:'.$b->id,
                    'group' => 'alerts',
                    'kind' => 'expired',
                    'cls' => 'is-expired',
                    'icon' => 'ti-alert-octagon',
                    'title' => $b->product->name ?? 'Unknown product',
                    'body' => 'Expired '.$b->expiry_date->diffForHumans(['parts' => 1]).' · batch '.$b->batch_number,
                    'href' => $itemHref($b->product, 'expired'),
                    // The day it expired IS the day this alert began.
                    'sort_at' => $b->expiry_date->copy()->startOfDay()->toIso8601String(),
                ]))
                ->concat($recentExpiring->take($perKind)->map(fn ($b) => [
                    'id' => 'expiring:'.$b->id,
                    'group' => 'alerts',
                    'kind' => 'expiring',
                    'cls' => 'is-expiring',
                    'icon' => 'ti-clock-exclamation',
                    'title' => $b->product->name ?? 'Unknown product',
                    // Urgency first, date as the qualifier. Leading with
                    // "Expires <date>" and then appending expiry_label -- which
                    // is itself "Expires today" -- read "Expires Aug 23, 2026 ·
                    // Expires today".
                    'body' => $b->expiry_label.' · '.$b->expiry_date->format('M j, Y'),
                    'href' => $itemHref($b->product, 'expiring'),
                    // Crossed into the 30-day horizon this many days back.
                    'sort_at' => $b->expiry_date->copy()->startOfDay()
                        ->subDays(ProductBatch::EXPIRY_SOON_DAYS)->toIso8601String(),
                ]))
                ->concat($recentDue->take($perKind)->map(fn ($b) => [
                    'id' => 'need_to_return:'.$b->id,
                    'group' => 'alerts',
                    'kind' => 'need_to_return',
                    'cls' => 'is-return',
                    'icon' => 'ti-package-export',
                    'title' => $b->product->name ?? 'Unknown product',
                    // Just the label: return_days_label reads "12d left" for a
                    // medicine inside its window but "230d overdue" for an
                    // expired non-pharma batch, so a "can still go back to the
                    // supplier" lead-in contradicted half of them.
                    'body' => 'Return to supplier · '.$b->return_days_label,
                    'href' => $itemHref($b->product, 'need_to_return'),
                    'sort_at' => self::returnWindowOpenedAt($b),
                ]))
                ->concat($recentLow->take($perKind)->map(fn ($p) => [
                    // Low stock is keyed by product, and the id deliberately
                    // carries the stock level: restocking then dropping below
                    // the line again is a NEW notification, not one already read.
                    'id' => 'low_stock:'.$p->id.':'.$p->total_stock,
                    'group' => 'alerts',
                    'kind' => 'low_stock',
                    // Still the low_stock KIND -- the counts, the tabs and the
                    // Inventory filter all treat it as one. Only the colour
                    // splits: an empty shelf is not a warning that stock is
                    // getting low, it is the thing the warning was about.
                    'cls' => $p->total_stock <= 0 ? 'is-out' : 'is-low',
                    'icon' => 'ti-alert-triangle',
                    'title' => $p->name,
                    // Nothing on the shelf is a different message from running
                    // low, and it is the one a cashier needs to read at a glance.
                    'body' => ($p->total_stock <= 0
                        ? 'Out of stock'
                        : $p->total_stock.' '.$p->unit.' left')
                        .' · reorder at '.$p->reorder_level,
                    'href' => $itemHref($p, 'low_stock'),
                    // Last time any of this product's batches moved. A checkout
                    // that pushes a product under its reorder line writes
                    // updated_at, so the sale you just rang up surfaces at the
                    // top of the bell rather than wherever severity put it.
                    'sort_at' => optional($p->batches->max('updated_at'))->toIso8601String()
                        ?? $today->copy()->startOfDay()->toIso8601String(),
                ]))
                ->values()
                // Newest first, across every kind — the list is a feed, not a
                // set of per-kind buckets. The per-kind cap above still stands,
                // so this reorders what was already selected and can never let
                // 645 low-stock rows crowd out the two expired batches.
                ->sortByDesc(fn ($i) => strtotime($i['sort_at']))
                ->values()
                // The onset, rendered. sort_at has always carried the moment
                // each alert BEGAN; it was only ever used to order the feed.
                // Labelling it "Since ..." is what makes it printable without
                // repeating the old mistake: an inventory alert is a standing
                // condition, so "2 minutes ago" would claim it happened then,
                // whereas "Since Aug 24" says exactly what the value means.
                // Formatted here, once, so the bell, the notifications page and
                // the toasts cannot drift into three different date formats.
                ->map(fn ($i) => $i + ['when' => self::whenLabel($i['sort_at'] ?? null, true)])
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
     * Audit-derived notifications for the System and Updates tabs.
     *
     * ADMIN ONLY, and deliberately not folded into payload(): that method is
     * cached under one key shared by every signed-in user, so putting
     * role-dependent rows in it would serve a staff account whatever an admin
     * cached first. The audit trail itself sits behind role:admin, so these
     * rows must not reach staff at all -- the caller decides, and the bell
     * simply shows fewer tabs for staff.
     *
     * @return list<array<string,mixed>>
     */
    public function activity(int $limit = 6): array
    {
        return Cache::remember('topbar_activity', now()->addSeconds(self::TTL_SECONDS), function () use ($limit) {
            $rows = AuditTrail::query()
                ->latest('id')
                ->limit($limit * 3)
                ->get(['id', 'action', 'details', 'username', 'created_at']);

            return $rows->map(function ($row) {
                // System = who got in and whose account changed. Updates =
                // what happened to the data. Anything else is noise for a
                // notification panel and is dropped below.
                $isAccount = in_array($row->action, ['Login', 'Logout'], true)
                    || str_contains($row->details, 'user account');

                $isReport = str_contains($row->details, 'Report');

                [$group, $icon, $cls, $title] = match (true) {
                    $isAccount && $row->action === 'Login' => ['system', 'ti-login', 'is-system', 'Signed in'],
                    $isAccount && $row->action === 'Logout' => ['system', 'ti-logout', 'is-system', 'Signed out'],
                    $isAccount && $row->action === 'Created' => ['system', 'ti-user-plus', 'is-system', 'New user registered'],
                    $isAccount => ['system', 'ti-user-cog', 'is-system', 'Account updated'],
                    $isReport => ['updates', 'ti-file-text', 'is-update', 'New report generated'],
                    $row->action === 'Deleted' => ['updates', 'ti-trash', 'is-update', 'Record deleted'],
                    $row->action === 'Created' => ['updates', 'ti-plus', 'is-update', 'Record added'],
                    default => ['updates', 'ti-pencil', 'is-update', 'Record updated'],
                };

                return [
                    'id' => 'audit:'.$row->id,
                    'group' => $group,
                    'kind' => 'activity',
                    'cls' => $cls,
                    'icon' => $icon,
                    'title' => $title,
                    // Login/logout details already name the person ("Admin
                    // logged in"), so appending the username again read as
                    // "Admin logged in — Admin".
                    'body' => str_contains($row->details, $row->username)
                        ? $row->details
                        : $row->details.' — '.$row->username,
                    // A real event time, so the panel can say "2 minutes ago"
                    // and mean it. Inventory alerts deliberately carry none:
                    // they are a standing condition, not something that
                    // happened at a moment.
                    'at' => $row->created_at?->toIso8601String(),
                    // Same clock the inventory alerts sort on, so the merged
                    // feed interleaves audit rows and stock alerts correctly.
                    // Only `at` is ever rendered as "x ago" -- sort_at exists
                    // to order the list, not to make a claim about it.
                    'sort_at' => $row->created_at?->toIso8601String(),
                    // Absolute date/time beside the "x ago" the panel already
                    // stamps. No "Since" here: an audit row IS an event, so the
                    // timestamp is when it happened rather than when a
                    // condition started.
                    'when' => self::whenLabel($row->created_at?->toIso8601String()),
                    // Relative: activity() is cached under `topbar_activity`.
                    'href' => route('audit.index', [], false),
                ];
            })->filter(fn ($i) => $i['title'] !== 'Profile Test')
                ->take($limit)
                ->values()
                ->all();
        });
    }

    /**
     * A printable date/time for a feed row.
     *
     * Two rules, both about not inventing precision the value does not have:
     *
     * - Several onsets are derived from a DATE rather than a moment — an expiry,
     *   a return window that opens a fixed number of days before one — so they
     *   land exactly on midnight. Printing "12:00 AM" beside those would suggest
     *   the alert began at a particular second, so the clock is dropped.
     * - The year is shown only when it is not the current one. Expiry-derived
     *   onsets run years out, where the year is the whole point; a checkout from
     *   this morning does not need it.
     *
     * @param  bool  $since  Prefix with "Since" — for standing conditions, where
     *                       the timestamp is when the condition STARTED rather
     *                       than when something happened.
     */
    private static function whenLabel(?string $iso, bool $since = false): ?string
    {
        if (! $iso) {
            return null;
        }

        $at = Carbon::parse($iso);

        $label = $at->isCurrentYear() ? $at->format('M j') : $at->format('M j, Y');

        if ($at->format('H:i') !== '00:00') {
            $label .= ', '.$at->format('g:i A');
        }

        return $since ? 'Since '.$label : $label;
    }

    /**
     * When this batch first became returnable — the moment the alert began.
     *
     * Medicine opens its supplier window 120 days before expiry (see
     * ProductBatch::getReturnStatusAttribute); everything else has no formal
     * window and simply becomes returnable NON_PHARMA_RETURN_WINDOW_DAYS out.
     * Mirrors the thresholds ProductBatch::is_returnable applies, so a row's
     * position in the feed matches the rule that put it there.
     */
    private static function returnWindowOpenedAt(ProductBatch $batch): string
    {
        $window = ($batch->product && ! $batch->product->is_medicine)
            ? Product::NON_PHARMA_RETURN_WINDOW_DAYS
            : 120;

        return $batch->expiry_date->copy()->startOfDay()->subDays($window)->toIso8601String();
    }

    /**
     * Every href in this payload is RELATIVE, and must stay that way.
     *
     * The payload is cached and shared by every signed-in user, so an absolute
     * URL freezes whichever host happened to warm the cache. Reproduced: warm
     * it from a request to 127.0.0.1:8000, then read it from a localhost:8000
     * session and every notification links to 127.0.0.1 — a different origin,
     * so the session cookie is not sent and clicking a notification lands the
     * user on the login page looking signed out.
     *
     * With TTL_SECONDS at 30 that is not a one-off: on any install reachable
     * under two names (an IP and a hostname, or behind a proxy that rewrites
     * Host) whichever host polls first wins the next 30 seconds, so it flaps.
     *
     * `route($name, $params, false)` is the fix — relative paths behave
     * identically in an href and in the JS renderer, and carry no host at all.
     */

    /**
     * Drop the cached payload so the next poll recomputes.
     *
     * Called wherever stock moves (checkout, batch edits). Without this the
     * bell would lag a sale by up to TTL_SECONDS, which is exactly the kind of
     * "why does it still say 645" that makes a live badge worse than none.
     */
    public static function forget(): void
    {
        foreach ([self::PER_KIND, self::PAGE_PER_KIND] as $perKind) {
            Cache::forget(self::CACHE_KEY.':'.$perKind);
        }

        Cache::forget('topbar_activity');
    }
}
