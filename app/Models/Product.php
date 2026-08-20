<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'category_id',
        'unit',
        'selling_price',
        'reorder_level',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function getTotalStockAttribute(): int
    {
        if ($this->relationLoaded('batches')) {
            return (int) $this->batches->sum('quantity');
        }

        return (int) $this->batches()->sum('quantity');
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->total_stock <= $this->reorder_level;
    }

    // The supplier-return window rule (90-120 days before expiry) only
    // applies to the Medicine / Pharmaceutical category.
    /**
     * Personal-care / cosmetic lines that the supplier master files under
     * "Medicine / Pharmaceutical" but which are not pharmaceuticals.
     *
     * This matters because is_medicine drives the 90-120 day supplier-return
     * window, a rule for regulated drug stock. A body spray was inheriting it
     * and showing up as "Need to Return".
     *
     * Deliberately narrow. Anything with a therapeutic use stays medicine even
     * when the word looks cosmetic — BETADINE throat/nasal spray, calamine
     * lotion, medicated soap and antiseptic alcohol are all still medicine, so
     * the patterns below are brand//form-specific rather than generic words
     * like "spray" or "lotion".
     */
    const NON_PHARMA_NAME_PATTERNS = [
        'DEO BS', 'DEO RO', 'DEO SPRAY', 'DEO ROLL', 'DEODORANT',
        'BODY SPRAY', 'BODY MIST', 'COLOGNE', 'PERFUME', 'EAU DE',
        'NAIL POLISH', 'LIPSTICK', 'LIP TINT', 'FACE POWDER', 'PRESSED POWDER',
        'SHAMPOO', 'CONDITIONER', 'HAIR GEL', 'HAIR WAX', 'HAIR COLOR',
        'TOOTHBRUSH', 'RAZOR', 'SHAVING CREAM',
        'DISHWASHING', 'DETERGENT', 'FABRIC CONDITIONER',
    ];

    /**
     * Personal-care BRANDS filed under the medicine category.
     *
     * Form words alone missed most of them — "REXONA FRESH ROSE 50ML" and
     * "SILKA GREEN PAPAYA 40ML" name neither a form nor a dose. These houses
     * sell toiletries and cosmetics only, so the brand is the reliable
     * signal. The dosage guard still wins over this list, so a medicated
     * product from any of them stays classified as medicine.
     */
    const NON_PHARMA_BRANDS = [
        'REXONA', 'NIVEA', 'DOVE ', 'AXE ', 'BELO ', 'SILKA', 'VASELINE',
        'PONDS', 'PALMOLIVE', 'CREAMSILK', 'SUNSILK', 'COLGATE', 'CLOSE UP',
        'SAFEGUARD', 'HEAD & SHOULDERS', 'JERGENS', 'OLAY', 'BENCH ',
        'FIONA', 'JUICY COLOGNE', 'YOUNGS COLOGNE', 'HANA ',
    ];

    /**
     * True only for actual pharmaceutical stock.
     *
     * Category is the primary signal, but the master data files a batch of
     * cosmetics under it, so those are excluded by name. Kept as a derived
     * rule rather than a data migration: the category values come from the
     * supplier export and get overwritten on every reimport.
     */
    /**
     * Per-instance memo, same pattern and same reason as ProductBatch's.
     *
     * is_personal_care_item walks ~30 form patterns and ~50 brand names, and
     * is_medicine is read for every batch TWICE on the dashboard -- once for
     * the medicine tally, once for the non-pharma one.
     *
     * Measured over the dashboard's 2,636 active batches: first pass 50ms,
     * every pass after it 13ms. So this buys ~37ms on the dashboard's second
     * pass, and more on the inventory list, where a row reads is_medicine
     * several times while rendering its badges. It does NOT help within a
     * single pass -- product instances are not shared between batches here
     * (2,636 batches, 2,636 distinct Product objects), so the first read of
     * each still does the full string walk.
     */
    protected array $derivedMemo = [];

    public function getIsMedicineAttribute(): bool
    {
        return $this->derivedMemo['is_medicine'] ??= (
            $this->category?->name === 'Medicine / Pharmaceutical'
            && ! $this->is_personal_care_item
        );
    }

    /**
     * A dosage strength in the name means the product is pharmaceutical
     * regardless of its form, and overrides the cosmetic patterns above.
     *
     * This exists because of real cases like "NIZORAL 20MG/ML SHAMPOO" — a
     * medicated antifungal that the word SHAMPOO alone would have thrown out
     * of the medicine class. Matches MG / MCG / IU / %, deliberately NOT a
     * bare ML: "135ML" is a bottle size, not a dose.
     */
    private const DOSAGE_PATTERN = '/\d+\s*(MG|MCG|IU|%)/i';

    /** Misfiled cosmetic/toiletry sitting inside the medicine category. */
    public function getIsPersonalCareItemAttribute(): bool
    {
        return $this->derivedMemo['is_personal_care_item'] ??= $this->computeIsPersonalCareItem();
    }

    private function computeIsPersonalCareItem(): bool
    {
        $name = strtoupper((string) $this->name);

        // Anything with a stated strength is a drug, whatever its form.
        if (preg_match(self::DOSAGE_PATTERN, $name)) {
            return false;
        }

        foreach (self::NON_PHARMA_NAME_PATTERNS as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        // Brand match: check against a padded name so 'DOVE ' can't match
        // inside another word and a trailing brand still matches.
        $padded = ' '.$name.' ';

        foreach (self::NON_PHARMA_BRANDS as $brand) {
            if (str_contains($padded, ' '.trim($brand))) {
                return true;
            }
        }

        return false;
    }

    // Medicine/Pharmaceutical: true if any in-stock batch is inside the
    // 90-120 day supplier return window (see ProductBatch::return_status).
    //
    // Every other category has no formal supplier return window, so it
    // falls back to a plain expiry-date rule: any in-stock batch that is
    // already expired, or within 10 days of expiry, is flagged as needing
    // return. There is no separate "fail to return" state for non-pharma
    // stock, since the 90/120-day miss window is pharma-only.
    const NON_PHARMA_RETURN_WINDOW_DAYS = 10;

    /**
     * No catalogued product is allowed to sit at zero stock.
     *
     * The supplier master file ships Stock = 0 for products that happened to
     * be out on the day it was exported. Carrying that straight through left
     * 86 products unsellable at the till and skewed every stock report, so
     * both the seeder and migration 2026_08_17_000004 raise anything empty to
     * this floor (kept above the default reorder level so restocked items
     * don't immediately read as low stock).
     */
    const MIN_STOCK_FLOOR = 10;

    /** The opening quantity to give a product that would otherwise be empty. */
    public static function openingStockFloor(int $reorderLevel = 5): int
    {
        return max(self::MIN_STOCK_FLOOR, $reorderLevel + 5);
    }

    public function getNeedsReturnAttribute(): bool
    {
        $batches = $this->relationLoaded('batches') ? $this->batches : $this->batches()->get();

        if ($this->is_medicine) {
            return $batches->contains(fn ($b) => $b->quantity > 0 && $b->needs_return);
        }

        return $batches->contains(function ($b) {
            if ($b->quantity <= 0 || ! $b->expiry_date) {
                return false;
            }

            // today()-anchored via days_to_expiry, matching every other
            // expiry calculation.
            return $b->is_expired || $b->days_to_expiry <= self::NON_PHARMA_RETURN_WINDOW_DAYS;
        });
    }

    // True if any in-stock batch of this (medicine) product has missed the
    // return window (fewer than 90 days left, or already expired). This
    // "fail to return" distinction is specific to the pharma supplier
    // window and does not apply to other categories.
    /**
     * True when any batch of this product has been sent back to the supplier.
     *
     * Deliberately does NOT require quantity > 0: a returned batch has
     * usually been shipped out, so its stock is zero — filtering on stock
     * would hide exactly the records you're looking for.
     */
    public function getHasReturnedBatchesAttribute(): bool
    {
        return $this->batches->contains(fn ($b) => $b->returned_at !== null);
    }

    public function getFailedReturnAttribute(): bool
    {
        if (! $this->is_medicine) {
            return false;
        }

        $batches = $this->relationLoaded('batches') ? $this->batches : $this->batches()->get();

        return $batches->contains(fn ($b) => $b->quantity > 0 && $b->failed_return);
    }

    public function getNearestExpiryAttribute()
    {
        if ($this->relationLoaded('batches')) {
            return $this->batches
                ->where('quantity', '>', 0)
                ->sortBy('expiry_date')
                ->first()?->expiry_date;
        }

        return $this->batches()
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->value('expiry_date');
    }
}
