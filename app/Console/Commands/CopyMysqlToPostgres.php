<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copy every row from a MySQL database into this app's PostgreSQL database.
 *
 * Point DB_* at the PostgreSQL target (already migrated) and SOURCE_DB_* at the
 * MySQL source, which is only ever read. Dry run without --write.
 *
 * Not copied: sessions and the cache, which rebuild themselves (everyone signs
 * in again), and `migrations`, which `php artisan migrate` already wrote.
 * The sales_history_archive_* tables have no migration, so they are created here.
 */
class CopyMysqlToPostgres extends Command
{
    private const SKIPPED = ['migrations', 'sessions', 'cache', 'cache_locks'];

    /** PostgreSQL allows 65,535 bound values in one statement. */
    private const MAX_BINDINGS = 60000;

    private const MAX_CHUNK = 2000;

    protected $signature = 'db:copy-mysql-to-postgres
        {--write : Actually copy (otherwise this only compares the two databases)}
        {--without-archives : Leave the sales_history_archive_* tables out (no page reads them)}';

    protected $description = 'Copy all rows from the MySQL database in SOURCE_DB_* into the PostgreSQL database in DB_*';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('DB_CONNECTION must be pgsql: this command writes to the default connection.');

            return self::FAILURE;
        }

        config(['database.connections.copy_source' => array_merge(config('database.connections.mysql'), [
            'host' => env('SOURCE_DB_HOST', '127.0.0.1'),
            'port' => env('SOURCE_DB_PORT', 3306),
            'database' => env('SOURCE_DB_DATABASE'),
            'username' => env('SOURCE_DB_USERNAME', 'root'),
            'password' => env('SOURCE_DB_PASSWORD', ''),
        ])]);

        if (! env('SOURCE_DB_DATABASE')) {
            $this->error('Set SOURCE_DB_DATABASE (and SOURCE_DB_HOST / PORT / USERNAME / PASSWORD) to the MySQL source.');

            return self::FAILURE;
        }

        $source = DB::connection('copy_source');
        $tables = collect($source->select('SHOW TABLES'))
            ->map(fn ($row) => (string) array_values((array) $row)[0])
            ->reject(fn ($table) => in_array($table, self::SKIPPED, true))
            ->reject(fn ($table) => $this->option('without-archives') && str_starts_with($table, 'sales_history_archive'))
            ->values()
            ->all();

        $missing = array_filter($tables, fn ($t) => ! Schema::hasTable($t) && ! str_starts_with($t, 'sales_history_archive'));
        if ($missing) {
            $this->error('Run php artisan migrate first. Missing here: '.implode(', ', $missing));

            return self::FAILURE;
        }

        // A migration writes the starter categories; with no products yet they
        // are only that, and MySQL's categories replace them.
        if ($this->option('write') && DB::table('products')->doesntExist()) {
            DB::table('categories')->delete();
        }

        foreach ($this->inForeignKeyOrder($tables) as $table) {
            $count = $source->table($table)->count();
            $existing = Schema::hasTable($table) ? DB::table($table)->count() : 0;

            if (! $this->option('write')) {
                $this->line(sprintf('%-42s %10s rows  (%s here)', $table, number_format($count), number_format($existing)));

                continue;
            }

            // Never on top of real data: a second run must not double anything.
            if ($existing > 0) {
                $this->error("{$table} already has {$existing} rows here. Copy into a freshly migrated database.");

                return self::FAILURE;
            }

            if (! Schema::hasTable($table)) {
                DB::statement("CREATE TABLE \"{$table}\" (LIKE sales_history INCLUDING ALL)");
            }

            $this->copyTable($table);

            $copied = DB::table($table)->count();
            if ($copied !== $count) {
                $this->error("{$table}: {$count} rows in MySQL but {$copied} here. Stopping.");

                return self::FAILURE;
            }

            $this->resetIdCounter($table);
            $this->info(sprintf('%-42s %10s rows copied', $table, number_format($copied)));
        }

        if ($this->option('write')) {
            // Fresh statistics, or the planner guesses until autovacuum gets round to it.
            DB::statement('ANALYZE');
        }

        return self::SUCCESS;
    }

    private function copyTable(string $table): void
    {
        $source = DB::connection('copy_source');
        $columns = Schema::connection('copy_source')->getColumnListing($table);
        $chunk = min(self::MAX_CHUNK, (int) floor(self::MAX_BINDINGS / max(1, count($columns))));
        $hasId = in_array('id', $columns, true);

        $query = $source->table($table)->orderBy($hasId ? 'id' : $columns[0]);
        $write = function ($rows) use ($table) {
            DB::table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
        };

        $hasId ? $query->chunkById($chunk, $write) : $query->chunk($chunk, $write);
    }

    /** Past the highest id copied, or the first new row here collides with an old one. */
    private function resetIdCounter(string $table): void
    {
        $sequence = Schema::hasColumn($table, 'id')
            ? DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', ['"'.$table.'"', 'id'])->name
            : null;

        if ($sequence) {
            DB::statement("SELECT setval('{$sequence}', GREATEST((SELECT COALESCE(MAX(id), 0) FROM \"{$table}\"), 1))");
        }
    }

    /**
     * Tables a foreign key points at come first.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function inForeignKeyOrder(array $tables): array
    {
        $parents = [];
        foreach (DB::select("SELECT conrelid::regclass::text AS child, confrelid::regclass::text AS parent FROM pg_constraint WHERE contype = 'f'") as $key) {
            $child = trim($key->child, '"');
            $parent = trim($key->parent, '"');
            if ($child !== $parent) {
                $parents[$child][] = $parent;
            }
        }

        $ordered = [];
        $visit = function (string $table) use (&$visit, &$ordered, $parents, $tables) {
            if (in_array($table, $ordered, true) || ! in_array($table, $tables, true)) {
                return;
            }
            foreach ($parents[$table] ?? [] as $parent) {
                $visit($parent);
            }
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }
}
