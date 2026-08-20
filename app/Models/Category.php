<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

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
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}