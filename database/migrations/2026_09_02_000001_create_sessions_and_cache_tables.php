<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep sessions and the cache when the filesystem is not durable.
 *
 * Locally both drivers are `file`, which is correct: XAMPP serves one process
 * off one disk. A container host is neither. Its filesystem is rebuilt on every
 * deploy and is not shared between instances, so `file` means every deploy
 * signs everyone out, and a second instance cannot read the first one's cache.
 *
 * That second half matters more here than it usually would. This app leans on
 * the cache for correctness, not just speed: AlertService's payload is what the
 * bell, the toasts and /notifications all read, and SalesHistory's aggregates
 * (3.9s each to rebuild, 53.6s if the index hint is lost) carry the
 * sales_cache_version / pos_cache_version stamps that a checkout bumps. Two
 * instances with private caches would disagree about both.
 *
 * The schema is Laravel's own, so `database` is a drop-in for either driver.
 * Guarded with hasTable(): a deploy re-runs migrate, and Breeze installs may
 * already carry the sessions table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                // mediumText, not text: text tops out at 64 KB and the alert
                // payload plus the history aggregates are comfortably larger.
                // An oversized value is TRUNCATED rather than refused, which
                // would surface as unserialization errors, not as a write error.
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
    }
};
