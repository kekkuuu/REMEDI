<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\ProductBatch;
use App\Models\SaleItem;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove the duplicate opening-stock batch a second seed/import run left
 * behind on 2,637 of ~2,638 products.
 *
 * Every product's OPENING-<sku> batch (see ProductBatch::batchNameCode --
 * these predate the AAA-YYYYMMDD-NN scheme and carry no real per-delivery
 * sequence) exists TWICE: one row received 2026-08-15 (the real one -- it is
 * the row that accumulated real POS sales and the row an admin actually
 * marked returned, in every case checked) and one received 2026-09-09 that
 * is always untouched (quantity === qty_received, no sale_items, never
 * returned). That second row is what made the Inventory list show a
 * "Returned" badge sitting next to a live "Need to Return" badge and a Mark
 * Returned button on the same row -- not the same batch contradicting
 * itself, but a genuine duplicate nobody asked for.
 *
 * Verified against production before this was written: across all 2,637
 * duplicate pairs, the later-received row NEVER carries a sale_items
 * reference or a returned_at, and the earlier one sometimes carries both --
 * zero exceptions. This command still checks both per row rather than
 * trusting that pattern, because `sale_items.product_batch_id` is
 * ON DELETE RESTRICT and a row that turned out to be wrong would otherwise
 * take real transaction history down with it.
 *
 *   php artisan batches:dedupe-opening-stock --dry-run
 *   php artisan batches:dedupe-opening-stock --apply
 */
class DedupeOpeningStockBatches extends Command
{
    protected $signature = 'batches:dedupe-opening-stock
                            {--apply : Actually delete the duplicate rows; without this, only report}';

    protected $description = 'Remove the duplicate OPENING-<sku> batch a second seed/import run left on most products';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $dupeKeys = ProductBatch::select('product_id', 'batch_number')
            ->groupBy('product_id', 'batch_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupeKeys->isEmpty()) {
            $this->info('No duplicate batch_number groups found. Nothing to do.');

            return self::SUCCESS;
        }

        $rows = ProductBatch::whereIn('product_id', $dupeKeys->pluck('product_id')->unique())
            ->get(['id', 'product_id', 'batch_number', 'quantity', 'qty_received', 'received_date', 'returned_at']);

        $batchIdsWithSales = SaleItem::query()->distinct()->pluck('product_batch_id')->flip();

        $groups = $rows->groupBy(fn ($r) => $r->product_id.'|'.$r->batch_number)
            ->filter(fn ($g) => $g->count() > 1);

        $toDelete = [];
        $skipped = [];

        foreach ($groups as $key => $group) {
            // Ambiguous: this command only knows how to resolve a clean pair.
            // Three or more rows, or a tie on received_date, needs a human.
            if ($group->count() !== 2 || $group->pluck('received_date')->unique()->count() !== 2) {
                $skipped[] = "{$key}: {$group->count()} rows, ambiguous shape -- left alone";

                continue;
            }

            $sorted = $group->sortBy('received_date')->values();
            $older = $sorted->first();
            $newer = $sorted->last();

            $newerHasSales = $batchIdsWithSales->has($newer->id);
            $newerReturned = (bool) $newer->returned_at;

            if ($newerHasSales || $newerReturned) {
                // The pattern this command was built around does not hold for
                // this pair -- the newer row is the one with real history, so
                // deleting it would be wrong. Try the older row instead only
                // if IT is clean; otherwise both rows carry history and there
                // is genuinely nothing safe to remove automatically.
                $olderHasSales = $batchIdsWithSales->has($older->id);
                $olderReturned = (bool) $older->returned_at;

                if ($olderHasSales || $olderReturned) {
                    $skipped[] = "{$key}: both rows carry sales/return history -- left alone";

                    continue;
                }

                $toDelete[] = $older;

                continue;
            }

            $toDelete[] = $newer;
        }

        $this->info(sprintf(
            '%s duplicate groups: %s safe to remove, %s skipped.',
            number_format($groups->count()), number_format(count($toDelete)), number_format(count($skipped))
        ));

        foreach (array_slice($skipped, 0, 20) as $reason) {
            $this->warn("  {$reason}");
        }
        if (count($skipped) > 20) {
            $this->warn('  ... '.(count($skipped) - 20).' more skipped groups not shown.');
        }

        if (! $apply) {
            $this->info('Dry run: nothing was deleted. Re-run with --apply to remove the '.count($toDelete).' duplicate rows.');

            return self::SUCCESS;
        }

        if (! $toDelete) {
            $this->info('Nothing safe to delete.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toDelete) {
            foreach (array_chunk(array_map(fn ($b) => $b->id, $toDelete), 500) as $chunk) {
                ProductBatch::whereIn('id', $chunk)->delete();
            }
        });

        AuditTrail::log('Deleted', sprintf(
            'Removed %s duplicate opening-stock batches (second seed/import run left one behind on most products; %s groups left unresolved for manual review)',
            number_format(count($toDelete)), number_format(count($skipped))
        ));

        AlertService::forget();

        $this->info(sprintf('Deleted %s duplicate batch rows.', number_format(count($toDelete))));

        return self::SUCCESS;
    }
}
