<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\SalesHistory;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Derives each product's reorder level from its OWN demand.
 *
 * The seeded levels were unrelated to how fast anything sells, and inconsistent
 * between units for no reason anyone could point at:
 *
 *   unit     reorder   sells/month   cover
 *   BOTTLE     6.5        13.0        ~2 weeks
 *   PACK       6.1        12.3        ~2 weeks
 *   TUBE       8.6        14.9        ~2 weeks
 *   PCS       12.5        13.3        ~1 month
 *   BOX       30.5        29.7        ~1 month
 *
 * A flat number per UNIT cannot fix that, which is the reason this works per
 * product instead: within BOX alone the busiest product sells 869 a month, so
 * the unit's average of ~30 would leave it about one day of cover while a
 * one-a-month product in the same unit sat permanently "low".
 *
 * The rule is COVERAGE_MONTHS of the product's own average monthly sales,
 * rounded up, with a floor. That is a reorder POINT in the usual sense -- buy
 * when what is left would not last the time it takes to restock -- using half a
 * month as the stand-in for supplier lead time, since nothing in the schema
 * records one. Give it a real lead time and this is the one number to change.
 *
 * Dry run by default. It rewrites the level on ~2,600 products, and that column
 * drives every low-stock alert in the app -- the bell, the toasts, the
 * dashboard panels and the inventory report -- so the preview is the point.
 */
class SetReorderLevels extends Command
{
    protected $signature = 'products:reorder-levels
                            {--apply : Write the changes (default is a dry run)}
                            {--months=0.5 : Months of demand a reorder level should cover}
                            {--floor=5 : Never set a level below this}
                            {--samples=10 : Example rows to print}';

    protected $description = 'Set each product reorder level from its own average monthly demand';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $months = max(0.0, (float) $this->option('months'));
        $floor = max(0, (int) $this->option('floor'));
        $samples = max(0, (int) $this->option('samples'));

        // Average units sold per month, over the months the product actually
        // sold in. Clamped to reportableThrough(): the seeded history runs to
        // the end of the current month, so an unclamped average is diluted by
        // months that have not happened.
        $demand = DB::table('sales_history')
            ->where('sale_date', '<=', SalesHistory::reportableThrough())
            ->groupBy('product_sku')
            ->pluck(
                DB::raw('SUM(quantity_sold) / COUNT(DISTINCT DATE_FORMAT(sale_date, "%Y-%m"))'),
                'product_sku'
            );

        $products = Product::get(['id', 'name', 'sku', 'unit', 'reorder_level']);
        $changes = [];

        foreach ($products as $product) {
            $monthly = (float) ($demand[$product->sku] ?? 0);
            $level = max($floor, (int) ceil($monthly * $months));

            if ($level !== (int) $product->reorder_level) {
                $changes[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'from' => (int) $product->reorder_level,
                    'to' => $level,
                    'monthly' => round($monthly, 1),
                ];
            }
        }

        $this->line(sprintf(
            '%d products, %d would change (%.2f months of cover, floor %d).',
            $products->count(), count($changes), $months, $floor
        ));

        if ($samples && $changes) {
            $rows = collect($changes)
                ->sortByDesc(fn ($c) => abs($c['to'] - $c['from']))
                ->take($samples)
                ->map(fn ($c) => [$c['name'], $c['unit'], $c['monthly'], $c['from'], $c['to']]);

            $this->table(['Product', 'Unit', 'Sold/month', 'From', 'To'], $rows);
        }

        if (! $changes) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Dry run — nothing written. Pass --apply to save.');

            return self::SUCCESS;
        }

        // Bulk update, so Product::saved never fires -- which is exactly why the
        // caches are cleared by hand below. A per-model save would fire ~2,600
        // times and rebuild the alert payload on each one.
        DB::transaction(function () use ($changes) {
            foreach (array_chunk($changes, 500) as $chunk) {
                foreach ($chunk as $change) {
                    DB::table('products')
                        ->where('id', $change['id'])
                        ->update(['reorder_level' => $change['to']]);
                }
            }
        });

        // reorder_level decides is_low_stock, so every surface that counts low
        // stock is now stale: the bell, the toasts, the notifications page and
        // the dashboard panels all read AlertService's cached payload.
        AlertService::forget();

        AuditTrail::log('Updated', sprintf(
            'Recalculated reorder levels from demand: %d products changed (%.2f months of cover)',
            count($changes), $months
        ));

        $this->info(sprintf('Updated %d products.', count($changes)));

        return self::SUCCESS;
    }
}
