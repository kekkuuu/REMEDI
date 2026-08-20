<?php

namespace App\Console\Commands;

use App\Services\ProductClassifier;
use Illuminate\Console\Command;

/**
 * Rewrites the Category column of a product master CSV using the same rules
 * the app itself classifies by (App\Services\ProductClassifier).
 *
 * The supplier's own Category column reads like a substring match over the
 * product name, so the exports ship deodorants under Medicine / Pharmaceutical
 * and ice cream under Personal Care. `products:classify` fixes that in the
 * database; this fixes it in the file, so the next reseed starts from correct
 * data instead of relying on the seeder to correct it every time.
 *
 * Dry run unless --write is passed. The first write of a given file leaves a
 * .bak copy beside it, since the original supplier categories are not
 * recoverable once overwritten.
 */
class ClassifyProductCsv extends Command
{
    protected $signature = 'products:classify-csv
                            {files* : Product master CSV files to rewrite}
                            {--write : Write the files (default is a dry run)}
                            {--column=Category : Name of the category column}
                            {--name-column=Product Name : Name of the product name column}';

    protected $description = 'Re-derive the Category column of a product master CSV from product names';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $categoryColumn = $this->option('column');
        $nameColumn = $this->option('name-column');
        $exit = self::SUCCESS;

        foreach ($this->argument('files') as $path) {
            if (! file_exists($path)) {
                $this->error("File not found: {$path}");
                $exit = self::FAILURE;

                continue;
            }

            $handle = fopen($path, 'r');
            $header = fgetcsv($handle);

            $categoryIndex = $header ? array_search($categoryColumn, $header, true) : false;
            $nameIndex = $header ? array_search($nameColumn, $header, true) : false;

            if ($categoryIndex === false || $nameIndex === false) {
                fclose($handle);
                $this->error("{$path}: expected columns '{$categoryColumn}' and '{$nameColumn}' in the header.");
                $exit = self::FAILURE;

                continue;
            }

            $rows = [];
            $changes = [];
            $unchanged = 0;
            $unplaceable = 0;

            while (($row = fgetcsv($handle)) !== false) {
                // A short row would silently drop the category into the wrong
                // column on write, so pad rather than assume well-formed input.
                $row = array_pad($row, count($header), '');

                $target = ProductClassifier::classify($row[$nameIndex] ?? '');
                $current = trim((string) ($row[$categoryIndex] ?? ''));

                if ($target === null) {
                    // No rule was confident: keep whatever the supplier said.
                    $unplaceable++;
                } elseif ($target === $current) {
                    $unchanged++;
                } else {
                    $changes["{$current} -> {$target}"] = ($changes["{$current} -> {$target}"] ?? 0) + 1;
                    $row[$categoryIndex] = $target;
                }

                $rows[] = $row;
            }

            fclose($handle);

            $changed = array_sum($changes);
            $this->newLine();
            $this->line("<info>{$path}</info>");
            $this->line('  '.count($rows)." rows: <info>{$changed}</info> recategorized, "
                ."<comment>{$unchanged}</comment> already correct, "
                ."<comment>{$unplaceable}</comment> left as-is (no rule matched).");

            arsort($changes);
            foreach (array_slice($changes, 0, 12, true) as $move => $count) {
                $this->line(sprintf('    %5d  %s', $count, $move));
            }

            if (count($changes) > 12) {
                $this->line('    ... and '.(count($changes) - 12).' more kinds of move');
            }

            if (! $write || $changed === 0) {
                continue;
            }

            // The supplier's original categories are wrong, but they are also
            // the only copy of what the file said before this ran.
            $backup = $path.'.bak';
            if (! file_exists($backup)) {
                copy($path, $backup);
                $this->line("    backup written: {$backup}");
            }

            $out = fopen($path, 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);

            $this->info('    written.');
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Dry run: no files were changed. Re-run with --write to commit.');
        }

        return $exit;
    }
}
