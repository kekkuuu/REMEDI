<?php

namespace App\Console\Commands;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-files products into the category their name says they belong in.
 *
 * Dry run by default: it prints what would move and changes nothing until
 * --apply is passed. The catalogue is ~2,600 products and moving one out of
 * Medicine / Pharmaceutical changes the supplier return window it is held to
 * (90-120 days becomes a flat 10 days), so the preview is the point.
 */
class ClassifyProducts extends Command
{
    protected $signature = 'products:classify
                            {--apply : Write the changes (default is a dry run)}
                            {--category= : Only consider products currently in this category}
                            {--samples=8 : Example product names to print per move}';

    protected $description = 'Reclassify products into the right category based on their name';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $samples = max(0, (int) $this->option('samples'));

        $products = Product::with('category')
            ->when($this->option('category'), function ($query, $name) {
                $query->whereHas('category', fn ($q) => $q->where('name', $name));
            })
            ->get(['id', 'name', 'category_id']);

        if ($products->isEmpty()) {
            $this->warn('No products matched.');

            return self::SUCCESS;
        }

        // [from => [to => [names...]]]
        $moves = [];
        $unmatched = 0;
        $alreadyRight = 0;

        foreach ($products as $product) {
            $target = ProductClassifier::classify($product->name);

            if ($target === null) {
                $unmatched++;

                continue;
            }

            $current = $product->category?->name;

            if ($current === $target) {
                $alreadyRight++;

                continue;
            }

            $moves[$current ?? '(none)'][$target][] = $product->name;
        }

        $movingCount = collect($moves)->flatten(1)->flatten()->count();

        $this->newLine();
        $this->line("Scanned <info>{$products->count()}</info> products: "
            ."<info>{$movingCount}</info> to move, "
            ."<comment>{$alreadyRight}</comment> already correct, "
            ."<comment>{$unmatched}</comment> unclassifiable (left alone).");
        $this->newLine();

        if ($movingCount === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->renderMoves($moves, $samples);

        if (! $apply) {
            $this->newLine();
            $this->warn('Dry run: nothing was written. Re-run with --apply to commit.');

            return self::SUCCESS;
        }

        $this->applyMoves($products);

        return self::SUCCESS;
    }

    /** Print the from -> to breakdown, largest first, with sample names. */
    private function renderMoves(array $moves, int $samples): void
    {
        $rows = [];

        foreach ($moves as $from => $targets) {
            foreach ($targets as $to => $names) {
                $rows[] = [$from, $to, count($names), $names];
            }
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        $this->table(
            ['From', 'To', 'Count'],
            array_map(fn ($r) => [$r[0], $r[1], $r[2]], $rows)
        );

        if ($samples === 0) {
            return;
        }

        foreach ($rows as [$from, $to, $count, $names]) {
            $this->newLine();
            $this->line("<comment>{$from}</comment> -> <info>{$to}</info> ({$count})");

            foreach (array_slice($names, 0, $samples) as $name) {
                $this->line("    {$name}");
            }

            if ($count > $samples) {
                $this->line('    ... and '.($count - $samples).' more');
            }
        }
    }

    /**
     * Commit the reclassification.
     *
     * Categories are resolved (and created) up front so the per-product loop
     * is a plain id write, and the whole thing runs in one transaction: a
     * half-reclassified catalogue is worse than an unclassified one.
     */
    private function applyMoves($products): void
    {
        $categoryIds = Category::pluck('id', 'name');

        foreach (ProductClassifier::NEW_CATEGORIES as $name) {
            if (! $categoryIds->has($name)) {
                $categoryIds[$name] = Category::create(['name' => $name])->id;
                $this->line("Created category: <info>{$name}</info>");
            }
        }

        $moved = 0;

        DB::transaction(function () use ($products, $categoryIds, &$moved) {
            // Group by target so each category is one UPDATE ... WHERE IN
            // rather than 400 single-row saves.
            $byTarget = [];

            foreach ($products as $product) {
                $target = ProductClassifier::classify($product->name);

                if ($target === null || $product->category?->name === $target) {
                    continue;
                }

                $byTarget[$target][] = $product->id;
            }

            foreach ($byTarget as $target => $ids) {
                Product::whereIn('id', $ids)->update(['category_id' => $categoryIds[$target]]);
                $moved += count($ids);
            }
        });

        // The sidebar's category list is cached for 6 hours (AppServiceProvider),
        // so without this the new categories do not appear until it expires.
        Cache::forget('sidebar_categories');

        AuditTrail::log('Reclassified Products', "Moved {$moved} products into name-derived categories.");

        $this->newLine();
        $this->info("Applied: {$moved} products moved.");
    }
}
