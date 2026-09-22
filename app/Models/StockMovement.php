<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One append-only row per unit of stock movement — the stock card. See the
 * migration for why it exists and how it's scoped.
 *
 * `record()` is the one place a movement is written; every caller (addBatch,
 * checkout's FEFO walk, markBatchReturned, the manual adjustment endpoint)
 * goes through it rather than inserting directly, so `balance_after` can
 * never be computed two different ways.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'product_batch_id',
        'sale_id',
        'type',
        'quantity_change',
        'balance_after',
        'reason',
        'performed_by',
    ];

    public const TYPE_STOCK_IN = 'stock_in';

    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_PULL_OUT = 'pull_out';

    // A voided sale's stock going back onto the shelf -- distinct from
    // TYPE_RETURN, which is stock going back to the SUPPLIER (a batch's
    // quantity decreasing). Voiding increases it, the same direction as
    // TYPE_STOCK_IN, but it isn't a new delivery either -- it needs its own
    // label so the stock card reads "Void" rather than a delivery that
    // never happened.
    public const TYPE_VOID = 'void';

    /** Every type a caller may record, and the one list the adjustment form renders from. */
    public const TYPES = [
        self::TYPE_STOCK_IN,
        self::TYPE_SALE,
        self::TYPE_RETURN,
        self::TYPE_ADJUSTMENT,
        self::TYPE_PULL_OUT,
        self::TYPE_VOID,
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id')->withTrashed();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    // Users are archived, not deleted — withTrashed() so a row written by an
    // account since archived still resolves to a name, same as Sale::user().
    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }

    /**
     * Log one movement against a batch, AFTER the batch's quantity column has
     * already been updated to its new value — balance_after is read straight
     * off the batch rather than computed independently, so the ledger can
     * never drift from what the batch itself reports.
     */
    public static function record(
        ProductBatch $batch,
        string $type,
        int $quantityChange,
        ?string $reason = null,
        ?int $saleId = null,
        ?int $performedBy = null,
    ): self {
        return static::create([
            'product_id' => $batch->product_id,
            'product_batch_id' => $batch->id,
            'sale_id' => $saleId,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'balance_after' => (int) $batch->quantity,
            'reason' => $reason,
            'performed_by' => $performedBy ?? auth()->id(),
        ]);
    }
}
