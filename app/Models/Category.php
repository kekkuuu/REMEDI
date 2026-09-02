<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * The category name that drives the pharmaceutical supplier-return window.
     *
     * This is NOT just a label. `Product::is_medicine` matches on it, and that
     * decides whether a batch is held to the 90-120 day medicine return window
     * or to the flat 10-day non-pharma rule — so this string is business logic
     * that happens to be stored as user-editable display text.
     *
     * Declared once, here, because it used to be written out twice: as a
     * literal inside Product::getIsMedicineAttribute() and again as
     * ProductClassifier::MEDICINE. Two copies of a magic string that must agree
     * for the return rules to work at all.
     *
     * CategoryController::update refuses to rename a category away from this
     * name. See RULE_DRIVING_NAMES.
     */
    const MEDICINE = 'Medicine / Pharmaceutical';

    /**
     * Category names the app matches on by value, and therefore cannot let a
     * user rename away.
     *
     * Renaming one silently rewrites which rules a product is held to, with no
     * error and nothing in the audit trail to explain the change. Measured on
     * this catalogue, renaming MEDICINE reclassified 1,393 products, dropped
     * all 75 "Return window missed" alerts to zero, and moved 10 more batches
     * into "can be returned" under the wrong window.
     *
     * The durable fix is a stable key on `categories` (a slug or an explicit
     * `is_medicine` flag) so the display name stops being load-bearing. Until
     * that exists, this list is the guard rail.
     */
    const RULE_DRIVING_NAMES = [self::MEDICINE];

    /** Does this category's name drive a business rule that a rename would break? */
    public function drivesBusinessRules(): bool
    {
        return in_array($this->name, self::RULE_DRIVING_NAMES, true);
    }

    // "Beverages" and "Water & Beverages" both came through in the product
    // master and describe the same shelf, so they're kept merged under the
    // broader name.
    const CANONICAL_BEVERAGES = 'Water & Beverages';

    /**
     * Category names that the source CSVs spell more than one way, mapped
     * to the single name the app stores. Keyed lowercase so lookups are
     * case-insensitive.
     */
    const NAME_ALIASES = [
        'beverages' => self::CANONICAL_BEVERAGES,
    ];

    /**
     * Collapse a raw category name from an import/seed file onto the one
     * name the app actually uses. Unknown names pass through trimmed.
     */
    public static function normalizeName(?string $name): string
    {
        $name = trim((string) $name);

        return self::NAME_ALIASES[strtolower($name)] ?? $name;
    }

    // A category can have many products
    /**
     * The icon each category shows on the Manage Categories page.
     *
     * Keyed by name because that is what a category IS here -- the name already
     * carries business meaning (see MEDICINE and RULE_DRIVING_NAMES), so there
     * is nothing more stable to key on. Anything not listed falls back rather
     * than rendering an empty box, which matters because categories are
     * user-creatable: someone adding "Pet Care" gets a sensible glyph, not a
     * gap.
     */
    const ICONS = [
        'Baby Care' => 'ti-mood-kid',
        'General Merchandise' => 'ti-shopping-bag',
        'Household' => 'ti-home',
        'Medical Supplies & Devices' => 'ti-first-aid-kit',
        self::MEDICINE => 'ti-pill',
        'Milk & Dairy' => 'ti-milk',
        'Personal Care' => 'ti-user',
        'Snacks & Confectionery' => 'ti-candy',
        'Vitamins & Supplements' => 'ti-flask',
        self::CANONICAL_BEVERAGES => 'ti-bottle',
    ];

    public function getIconAttribute(): string
    {
        return self::ICONS[$this->name] ?? 'ti-category';
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
