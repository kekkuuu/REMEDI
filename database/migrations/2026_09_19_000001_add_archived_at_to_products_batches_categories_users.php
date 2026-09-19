<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The four records that used to have a Delete button now have an Archive
     * button, and archiving is a timestamp -- the row stays where it is.
     *
     * Deleting was destructive by construction: products.category_id cascades,
     * sale_items and sales_history refer to products and batches, and the
     * guards in the controllers existed to refuse the delete whenever history
     * pointed at the row -- which was nearly always, so the button mostly said
     * no. Archiving keeps every reference intact and simply takes the record
     * out of the working lists, so nothing has to be refused and nothing is
     * lost. Named archived_at rather than the Laravel default deleted_at so the
     * column says what the app does.
     *
     * Indexed because every query on these four tables now filters on it.
     */
    private const TABLES = ['products', 'product_batches', 'categories', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_archived_at_index');
                $blueprint->dropColumn('archived_at');
            });
        }
    }
};
