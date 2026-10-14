<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The till's uploaded GCash QR (Safeguard, 2026-10-01) was removed as a
 * feature on 2026-10-15 at the user's request. Nothing reads the saved code
 * any more, and it carries the account holder's details, so the row goes
 * too rather than sitting in the settings table (and in every full backup).
 *
 * Not reversible: the code came from the shop's GCash app ("My QR") and can
 * be had from there again if the feature ever returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'pos_gcash_qr_payload')->delete();
    }

    public function down(): void
    {
        // Nothing to restore -- see above.
    }
};
