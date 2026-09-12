<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

    /**
     * Units the till may actually sell — excludes expired and returned batches.
     *
     * Distinct from total_stock, deliberately. `total_stock` is what is
     * physically on the shelf, which is the right number for the inventory
     * report's valuation and for "how much is sitting here". `sellable_stock`
     * is what checkout is allowed to draw on. They differ by exactly the stock
     * that needs pulling: on this install 79 expired batches (3,434 units) plus
     * anything marked returned.
     *
     * Same relationLoaded() shape as total_stock so the eager-loaded pages do
     * not re-query per product.
     */
    public function getSellableStockAttribute(): int
    {
        if ($this->relationLoaded('batches')) {
            return (int) $this->batches->filter(fn ($b) => $b->is_sellable)->sum('quantity');
        }

        return (int) $this->batches()->sellable()->sum('quantity');
    }

    /**
     * Is this product at or below the level where it should be reordered?
     *
     * Measured against SELLABLE stock, not total_stock.
     *
     * A reorder level answers "when do I need to buy more?", and that depends
     * on what can actually be dispensed — not on how many unsellable boxes are
     * sitting in the back awaiting disposal. Comparing total_stock hid 37
     * products here that had NOTHING sellable and were still reported as
     * adequately stocked. The worst was ASICLAV 625 MG TAB X14, a prescription
     * antibiotic: one batch of 301 units, expired three months ago, reorder
     * level 30, is_low_stock false. The till would dispense none of it and
     * nobody was ever told to order more.
     *
     * total_stock remains the right number for the inventory report's
     * valuation — those units are physically present and are an asset (or a
     * disposal liability) either way. It is the reorder decision specifically
     * that has to read sellable_stock.
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->sellable_stock <= $this->reorder_level;
    }

    /**
     * "Reorder this" — as opposed to is_low_stock's "cannot sell this".
     *
     * is_low_stock compares SELLABLE stock, which is the right question for the
     * till: expired units cannot be dispensed, so a shelf full of them is
     * functionally empty. It is the wrong question for a Low Stock LIST, where
     * every row is an instruction to buy more. A product with 301 units that
     * happen to be expired is not a purchasing problem; it is a clearing
     * problem, and the Expired filter beside it already says so.
     *
     * Two tests, and it took both:
     *
     *   1. total_stock <= reorder_level — running out of what it physically
     *      has. Drops the obvious case, 301 expired units against a reorder
     *      level of 5.
     *   2. Not holding stock it cannot sell. Test 1 alone still listed 82
     *      products that were under their reorder level AND had nothing but
     *      expired units left.
     *
     * total_stock <= 0 deliberately still counts: a product with nothing at all
     * is genuinely out and does need reordering. The exclusion is only for
     * shelves holding unsellable stock.
     *
     * ONE definition, because the bell's low-stock alert links straight at the
     * Inventory tab's low-stock filter. When those two disagreed about the
     * expiring horizon the badge promised 28 batches and opened a list of 85 —
     * see InventoryController. Anything that counts or lists "low stock" for a
     * human reads this; only the POS grid still asks is_low_stock, because at
     * the register "can I sell this?" really is the whole question.
     */
    public function getIsRunningOutAttribute(): bool
    {
        return $this->total_stock <= $this->reorder_level
            && ! ($this->total_stock > 0 && $this->sellable_stock <= 0);
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

    /**
     * Drop the memo whenever the underlying attributes change.
     *
     * The accessors above cache into $derivedMemo for the length of the
     * request, which is right while an instance is read-only -- but an instance
     * that is UPDATED or refresh()ed mid-request keeps answering from values
     * that no longer exist. Reproduced: an expired batch whose expiry_date was
     * moved into the future still reported is_expired = true, and therefore
     * is_sellable = false, on the same instance after refresh().
     *
     * setRawAttributes() covers hydration and refresh(); setAttribute() covers
     * fill()/update()/direct assignment. Hydration sets raw attributes on a
     * fresh instance whose memo is already empty, so this costs nothing on the
     * read path the memo exists to speed up.
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->derivedMemo = [];

        return parent::setRawAttributes($attributes, $sync);
    }

    public function setAttribute($key, $value)
    {
        $this->derivedMemo = [];

        return parent::setAttribute($key, $value);
    }

    public function getIsMedicineAttribute(): bool
    {
        return $this->derivedMemo['is_medicine'] ??= (
            // Category::MEDICINE, not a literal: this string is matched in two
            // places and both must agree or the return window silently changes.
            $this->category?->name === Category::MEDICINE
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
    /**
     * The units a product may be stocked in.
     *
     * Free text before this, which is how the catalogue ended up with one
     * product whose unit is the string "20". It also let the Add Product form
     * default to lowercase "pcs" while all 2,637 other rows say "PCS" -- every
     * product added through the form would have started a second spelling of
     * the same unit, and anything grouping by unit would have split it in two.
     *
     * Uppercase because that is what the catalogue already uses. Ordered by how
     * common they are, so the usual choice is near the top of the list rather
     * than alphabetically buried.
     *
     * unitOptions() is what the forms should render: it folds in a product's
     * own current value when that value is not on this list, so editing the
     * odd legacy row does not silently rewrite its unit.
     */
    const UNITS = ['PCS', 'BOX', 'BOTTLE', 'PACK', 'TUBE', 'SACHET'];

    /**
     * @return list<string>
     */
    public static function unitOptions(?string $current = null): array
    {
        $units = self::UNITS;

        if ($current !== null && $current !== '' && ! in_array($current, $units, true)) {
            $units[] = $current;
        }

        return $units;
    }

    const NON_PHARMA_RETURN_WINDOW_DAYS = 10;

    /**
     * Categories carrying a longer non-pharma return window than the flat
     * default above.
     *
     * Baby Care and Vitamins & Supplements are both restricted, dated stock
     * in the same way medicine is (formula and supplements are pulled by lot
     * and expiry the same way drugs are), so a supplier will still take them
     * back well before the 10-day line that works for snacks or household
     * goods — but the flat window meant "Mark Returned", the bell, the
     * toasts and both dashboard rings only ever caught them 10 days out,
     * often too close to expiry for the return to actually go through.
     */
    const EXTENDED_RETURN_WINDOW_CATEGORIES = ['Baby Care', 'Vitamins & Supplements'];

    /** The longer window those categories get instead of the flat default. */
    const EXTENDED_RETURN_WINDOW_DAYS = 30;

    /**
     * The non-pharma return window THIS product is actually held to.
     *
     * The one place that resolves category -> window, so
     * getNeedsReturnAttribute() below, ProductBatch::return_days,
     * ProductBatch::is_returnable, AlertService::returnWindowOpenedAt() and
     * DashboardController's non-pharma tallies all agree on the same number
     * for the same product rather than each reading the flat constant
     * directly.
     */
    public function getNonPharmaReturnWindowDaysAttribute(): int
    {
        return in_array($this->category?->name, self::EXTENDED_RETURN_WINDOW_CATEGORIES, true)
            ? self::EXTENDED_RETURN_WINDOW_DAYS
            : self::NON_PHARMA_RETURN_WINDOW_DAYS;
    }

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
            // A batch already sent back is not still waiting to be sent back.
            // The medicine branch above gets this for free -- ProductBatch's
            // return_status answers "Successfully Returned" before it considers
            // the window -- but this branch computed the window itself and
            // never looked at returned_at, so a returned non-pharma batch kept
            // raising "Need to Return" in the row badge, the Inventory filter
            // and the dashboard counts. It sat next to the "Returned" badge on
            // the same row, telling the user to do a thing they had just done.
            //
            // The batch is NOT hidden by this: it keeps its row, its expiry
            // badge and its place in the Returned filter. Only the claim that
            // it still needs returning goes away.
            if ($b->returned_at || $b->quantity <= 0 || ! $b->expiry_date) {
                return false;
            }

            // is_expired is EXCLUDED here on purpose -- once a batch is past
            // its own expiry date, "you can still send this back" is no
            // longer true, whatever the window is. It falls to
            // failed_return below instead. This used to read
            // `$b->is_expired || days_to_expiry <= window`, which meant an
            // already-expired batch still carried a live "Need to Return"
            // badge AND a working Mark Returned button -- the exact
            // asymmetry the medicine branch above was built to avoid
            // (return_status answers "Fail to Return", not "Need to
            // Return", the moment is_expired is true). today()-anchored via
            // days_to_expiry, matching every other expiry calculation.
            // $this is the product itself here, so its own category
            // resolves the window (flat 10 days, or 30 for Baby Care /
            // Vitamins & Supplements).
            return ! $b->is_expired && $b->days_to_expiry <= $this->non_pharma_return_window_days;
        });
    }

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

    /**
     * True when any in-stock batch has missed its own return window.
     *
     * Medicine: the 90-120 day supplier window (ProductBatch::$failed_return,
     * itself gated on the pharma-only day bands -- see its docblock). Every
     * other category has no formal supplier window, so "failed" simply means
     * expired and never returned: past that point the shelf is a write-off,
     * not something a Mark Returned click can still act on, which is exactly
     * why is_returnable and getNeedsReturnAttribute() above both exclude an
     * expired batch. Before this existed, an expired non-pharma batch had
     * nowhere to go: not "Need to Return" (excluded above), not "Fail to
     * Return" (this always answered false for non-medicine) -- it simply
     * stopped being flagged at all the moment it expired, the opposite of
     * what an inventory alert is for.
     */
    public function getFailedReturnAttribute(): bool
    {
        $batches = $this->relationLoaded('batches') ? $this->batches : $this->batches()->get();

        if ($this->is_medicine) {
            return $batches->contains(fn ($b) => $b->quantity > 0 && $b->failed_return);
        }

        return $batches->contains(fn ($b) => $b->quantity > 0 && ! $b->returned_at && $b->is_expired);
    }

    /**
     * Expired batches still physically on the shelf.
     *
     * The inventory report has referenced `$product->expiredBatches` since it
     * was written, but nothing ever defined it: Product has `batches` and
     * nothing else. Eloquent resolves an unknown name to NULL rather than
     * erroring, so `@if($p->expiredBatches && ...)` was simply always false and
     * the per-row "N expired batches" badge never rendered -- on screen or on
     * the printout -- while the Expired Stock KPI above it counted them
     * correctly. A page reporting a non-zero expired count with no way to see
     * which products.
     *
     * `quantity > 0` because this is what still needs pulling off the shelf; a
     * returned batch has been shipped back and is 0. Same relationLoaded()
     * shape as total_stock, so the report's eager load is not undone by a query
     * per product.
     *
     * @return Collection<int, ProductBatch>
     */
    public function getExpiredBatchesAttribute()
    {
        if ($this->relationLoaded('batches')) {
            return $this->batches
                ->filter(fn ($b) => $b->quantity > 0 && $b->is_expired)
                ->sortBy('expiry_date')
                ->values();
        }

        return $this->batches()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today())
            ->orderBy('expiry_date')
            ->get();
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
