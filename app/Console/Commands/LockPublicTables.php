<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn on row level security for every table in the public schema.
 *
 * Supabase can serve tables in `public` through its Data API to anyone holding
 * the project's publishable key, and `users` holds password hashes. With RLS on
 * and no policies that API gets no rows. The app is not affected: it connects as
 * the role that owns the tables, and an owner is not subject to RLS.
 *
 * Re-run after any migration that adds a table: a new table starts unlocked.
 */
class LockPublicTables extends Command
{
    protected $signature = 'db:lock-public-tables';

    protected $description = 'Enable row level security on every PostgreSQL table in the public schema';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('This only applies to PostgreSQL.');

            return self::FAILURE;
        }

        $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"))
            ->pluck('tablename');

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
        }

        $unlocked = DB::table('pg_tables')->where('schemaname', 'public')->where('rowsecurity', false)->count();
        $this->info("Row level security on for {$tables->count()} tables; {$unlocked} left without it.");

        return $unlocked === 0 ? self::SUCCESS : self::FAILURE;
    }
}
