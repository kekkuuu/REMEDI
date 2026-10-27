<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A staff member notifying an admin about a product that is low on stock or
 * holding expired stock, and the admin's decision on it (2026-09-28).
 *
 * Approving records that the admin has seen it and authorised the fix --
 * reordering for low stock, pulling the stock for expired -- and nothing
 * more: it moves no stock itself, because the fix happens through the paths
 * that already do that properly (Add New Batch, Mark Returned, a batch
 * adjustment with a reason), each with its own stock-card row.
 */
class StockReport extends Model
{
    // Added 2026-10-23, at the user's request: an EMPTY shelf is its own
    // report, not "low stock" -- the same split the Inventory tab draws with
    // its Out of Stock filter and graphite badge.
    public const TYPE_OUT_OF_STOCK = 'out_of_stock';

    public const TYPE_LOW_STOCK = 'low_stock';

    public const TYPE_EXPIRED = 'expired';

    public const TYPES = [
        self::TYPE_OUT_OF_STOCK => 'Out of stock',
        self::TYPE_LOW_STOCK => 'Low stock',
        self::TYPE_EXPIRED => 'Expired stock',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for admin',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = ['product_id', 'type', 'note', 'status', 'reported_by', 'reviewed_by', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime'];

    /** Archived products keep their reports readable. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Reports waiting on an admin -- the sidebar's badge, so it is on every
     * page and cached. Cleared by every write in StockReportController; the
     * TTL only bounds a write from somewhere else.
     */
    public static function pendingCount(): int
    {
        return (int) Cache::memo()->remember('stock_reports_pending', 60,
            fn () => static::where('status', self::STATUS_PENDING)->count());
    }

    public static function forgetPendingCount(): void
    {
        Cache::memo()->forget('stock_reports_pending');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Is the condition a staff member wants to report actually true of this
     * product right now? The ONE check behind both the Notify button and the
     * endpoint, so the button cannot offer a report the endpoint refuses.
     * Reads the shared rules: nothing on the shelf (`total_stock <= 0`, the
     * Inventory Out of Stock filter) for out of stock; `is_running_out` for low
     * stock (the rule every low-stock list uses) but NOT once the shelf is
     * empty, which is out of stock instead -- one problem, one button; an
     * expired batch still holding units for expired.
     */
    public static function applies(Product $product, string $type): bool
    {
        return match ($type) {
            self::TYPE_OUT_OF_STOCK => $product->total_stock <= 0,
            self::TYPE_LOW_STOCK => (bool) $product->is_low_in_stock,
            self::TYPE_EXPIRED => $product->batches->contains(fn ($b) => $b->quantity > 0 && ! $b->returned_at && $b->is_expired),
            default => false,
        };
    }
}
