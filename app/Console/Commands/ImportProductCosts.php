<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Set `products.cost_price` from the supplier's receiving file (the MASTER
 * INVENTORY LIST export, database/data/master_inventory_list.csv).
 *
 * Matched on NAME, never on the file's ItemCode: the export went through
 * Excel, which rewrote every barcode as scientific notation (4.80089E+12), so
 * the codes no longer identify anything. ItemName is tried first, then
 * ItemDescription (they differ for 39 products). Names are compared trimmed,
 * upper-cased and with runs of spaces collapsed -- the file carries leading
 * spaces on some rows.
 *
 * Only the COST column is read. The file's Price column is the supplier's
 * list price, not this shop's shelf price -- selling_price comes from the
 * sales record and is left alone here. A name listed twice with two different
 * costs is skipped rather than guessed; a blank or zero cost is skipped too,
 * because an empty cost_price means "not known yet" and the Revenue Today
 * tile already treats it that way, where a 0 would read as pure profit.
 *
 * Dry run unless --write, same as every other command here that rewrites data.
 */
class ImportProductCosts extends Command
{
    protected $signature = 'products:import-costs
        {--file=database/data/master_inventory_list.csv : The receiving/master inventory CSV}
        {--write : Actually update cost_price (otherwise this only reports)}';

    protected $description = 'Set products.cost_price from the receiving (master inventory) file, matched by name';

    public function handle(): int
    {
        $path = (string) $this->option('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $idx = array_flip(array_map('trim', $header));

        foreach (['ItemName', 'Cost'] as $needed) {
            if (! isset($idx[$needed])) {
                $this->error("The file has no `{$needed}` column.");

                return self::FAILURE;
            }
        }

        // name => list of costs seen for it (by ItemName, then ItemDescription)
        $byName = [];
        $byDesc = [];
        while (($row = fgetcsv($handle)) !== false) {
            $name = $this->norm($row[$idx['ItemName']] ?? '');
            if ($name === '') {
                continue;
            }
            $cost = (float) str_replace(',', '', (string) ($row[$idx['Cost']] ?? ''));
            $byName[$name][] = $cost;

            if (isset($idx['ItemDescription'])) {
                $desc = $this->norm($row[$idx['ItemDescription']] ?? '');
                if ($desc !== '') {
                    $byDesc[$desc][] = $cost;
                }
            }
        }
        fclose($handle);

        $updates = [];
        $unmatched = [];
        $conflicting = [];
        $blank = 0;
        $atOrAbovePrice = [];

        foreach (Product::get(['id', 'name', 'sku', 'selling_price', 'cost_price']) as $p) {
            $key = $this->norm($p->name);
            $costs = $byName[$key] ?? $byDesc[$key] ?? null;

            if ($costs === null) {
                $unmatched[] = $p->name;

                continue;
            }

            $distinct = array_values(array_unique(array_map(fn ($c) => round($c, 2), $costs)));
            if (count($distinct) > 1) {
                $conflicting[] = $p->name.' ('.implode(' / ', $distinct).')';

                continue;
            }

            $cost = $distinct[0];
            if ($cost <= 0) {
                $blank++;

                continue;
            }

            if ($cost >= (float) $p->selling_price) {
                $atOrAbovePrice[] = sprintf('%s: cost %.2f vs price %.2f', $p->name, $cost, $p->selling_price);
            }

            $updates[$p->id] = $cost;
        }

        $this->line('Products matched with a cost : '.number_format(count($updates)));
        $this->line('Not in the receiving file    : '.number_format(count($unmatched)));
        $this->line('Blank/zero cost (left empty) : '.$blank);
        $this->line('Same name, different costs   : '.count($conflicting).' (skipped)');
        foreach ($conflicting as $c) {
            $this->warn('  conflicting: '.$c);
        }
        $this->line('Cost at/above selling price  : '.count($atOrAbovePrice).' (set anyway -- check these)');
        foreach ($atOrAbovePrice as $c) {
            $this->warn('  '.$c);
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->info('Dry run. Nothing written. Re-run with --write to apply.');

            return self::SUCCESS;
        }

        // Bulk, in one transaction. Product::saved never fires, which is
        // right: cost_price feeds no cached aggregate (only the dashboard's
        // Revenue Today tile, computed per request).
        DB::transaction(function () use ($updates) {
            foreach (array_chunk($updates, 500, true) as $chunk) {
                foreach ($chunk as $id => $cost) {
                    DB::table('products')->where('id', $id)->update(['cost_price' => $cost, 'updated_at' => now()]);
                }
            }
        });

        $this->info('Updated cost_price on '.number_format(count($updates)).' products.');

        return self::SUCCESS;
    }

    private function norm(?string $s): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $s)));
    }
}
