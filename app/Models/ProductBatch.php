<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'batch_number',
        'quantity',
        'qty_received',
        'unit_cost',
        'dr_no',
        'expiry_date',
        'received_date',
        'returned_at',
        'returned_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'received_date' => 'date',
        'returned_at' => 'datetime',
        'unit_cost' => 'float',
    ];

    /**
     * Per-instance memo for the derived expiry/return values below.
     *
     * They're pure functions of expiry_date / returned_at / quantity, but
     * each evaluation costs a Carbon diff, and callers read them repeatedly:
     * the Dashboard alone asks every medicine batch for is_returned,
     * needs_return AND failed_return, each of which recomputes return_status,
     * which itself recomputes is_expired. That was ~10k Carbon operations
     * over ~1,600 batches and dominated the page's PHP time (~0.6s).
     * Computing once per instance removes the repetition.
     *
     * Deliberately not cached across requests -- these depend on now(), and
     * an instance never outlives the request that loaded it.
     */
    protected array $derivedMemo = [];

    // A batch belongs to one product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // The user who marked this batch as returned to the supplier
    public function returnedBy()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    // Check if this batch is expired
    public function getIsExpiredAttribute(): bool
    {
        // ??= is safe for a bool memo: it only assigns when the stored value
        // is null, so a memoized `false` is still a hit, not a recompute.
        return $this->derivedMemo['is_expired'] ??= (
            $this->expiry_date // no expiry recorded (e.g. historical import) — can't be judged expired
                ? $this->expiry_date->isPast()
                : false
        );
    }

    // Check if this batch is expiring soon (within 3 months / 90 days)
    public function getIsExpiringSoonAttribute(): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return ! $this->is_expired && $this->days_to_expiry <= 90;
    }

    /**
     * Pharmacy return-window rule (applies to the Medicine / Pharmaceutical
     * category — enforce the category check where this is consumed):
     *
     *   - already marked returned -> "Successfully Returned"
     *   - 90 to 120 days left before expiry  -> "Need to Return"
     *     (still enough shelf life left for the supplier to accept it back)
     *   - fewer than 90 days left, or already expired -> "Fail to Return"
     *     (the return window has been missed)
     *   - more than 120 days left -> not due for review yet (null)
     */
    public function getReturnStatusAttribute(): ?string
    {
        // null is a meaningful result here ("not due for review yet"), so this
        // memo checks for key presence rather than using ??= — otherwise every
        // not-yet-due batch would recompute on each read.
        if (array_key_exists('return_status', $this->derivedMemo)) {
            return $this->derivedMemo['return_status'];
        }

        return $this->derivedMemo['return_status'] = $this->computeReturnStatus();
    }

    private function computeReturnStatus(): ?string
    {
        if ($this->returned_at) {
            return 'Successfully Returned';
        }

        if (! $this->expiry_date || $this->quantity <= 0) {
            return null;
        }

        if ($this->is_expired) {
            return 'Fail to Return';
        }

        // days_to_expiry is signed, but is_expired is already handled above,
        // so everything reaching here is in the future and the value is
        // positive. It is anchored to today() rather than now(), so a batch
        // does not slip a day early into "Fail to Return" late in the evening.
        $daysUntilExpiry = $this->days_to_expiry;

        if ($daysUntilExpiry >= 90 && $daysUntilExpiry <= 120) {
            return 'Need to Return';
        }

        if ($daysUntilExpiry < 90) {
            return 'Fail to Return';
        }

        return null;
    }

    public function getNeedsReturnAttribute(): bool
    {
        return $this->return_status === 'Need to Return';
    }

    // While inside the return window, how many days remain before this
    // batch drops under the 90-day floor and becomes "Fail to Return".
    public function getDaysToReturnDeadlineAttribute(): ?int
    {
        if (! $this->needs_return) {
            return null;
        }

        return $this->days_to_expiry - 90;
    }

    /**
     * Human countdown for the expiry badges: "7 days left" / "Expires today"
     * / "3 days ago".
     *
     * Lives here rather than being re-written as a ternary in each view — the
     * same string is shown on both dashboards, and the "today" case needs
     * special wording: a batch expiring today is treated as expired (we don't
     * sell on the expiry date), so "0 days left" read as a contradiction.
     */
    public function getExpiryLabelAttribute(): ?string
    {
        $days = $this->days_to_expiry;

        if ($days === null) {
            return null;
        }

        if ($days === 0) {
            return 'Expires today';
        }

        return $days > 0
            ? $days.' '.Str::plural('day', $days).' left'
            : abs($days).' '.Str::plural('day', abs($days)).' ago';
    }

    /**
     * Days relative to this batch's supplier-return deadline.
     * Positive = that many days left before the window closes.
     * Negative = that many days past it (a "Fail to Return").
     *
     * Unlike days_to_return_deadline (which is null unless the batch is
     * currently returnable), this is defined for failed batches too, so the
     * UI can show "12d overdue" instead of a bare status word.
     *
     * NOTE: passes `false` to diffInDays deliberately — Carbon 3 returns a
     * SIGNED value and the sign is the whole point here. Do not add `true`.
     */
    public function getReturnDaysAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        $window = ($this->product && ! $this->product->is_medicine)
            ? Product::NON_PHARMA_RETURN_WINDOW_DAYS
            : 90;

        // Same today()-not-now() rule as days_to_expiry: a partial day must
        // not shift the count.
        $daysToExpiry = (int) today()->diffInDays($this->expiry_date->copy()->startOfDay(), false);

        return $daysToExpiry - $window;
    }

    /** Human form of return_days: "30d left" / "12d overdue". */
    public function getReturnDaysLabelAttribute(): ?string
    {
        $days = $this->return_days;

        if ($days === null) {
            return null;
        }

        return $days >= 0 ? $days.'d left' : abs($days).'d overdue';
    }

    /**
     * Severity of this batch's EXPIRY, which is a different question from its
     * supplier-return status and must not borrow that scale.
     *
     *   expired  - already past expiry_date
     *   critical - a week or less left
     *   soon     - a month or less
     *   watch    - inside the 3-month "expiring soon" horizon
     *   null     - beyond the horizon
     *
     * The return window (90-120 days before expiry for medicine) sits ABOVE
     * this whole range: by the time a batch is "expiring soon" at all it has
     * already dropped out of the returnable window. Two separate scales,
     * deliberately given two separate colour families in the UI.
     */
    public const EXPIRY_CRITICAL_DAYS = 7;

    public const EXPIRY_SOON_DAYS = 30;

    public const EXPIRY_WATCH_DAYS = 90;

    public function getExpirySeverityAttribute(): ?string
    {
        if (! $this->expiry_date) {
            return null;
        }

        if ($this->is_expired) {
            return 'expired';
        }

        $days = $this->days_to_expiry;

        return match (true) {
            $days <= self::EXPIRY_CRITICAL_DAYS => 'critical',
            $days <= self::EXPIRY_SOON_DAYS => 'soon',
            $days <= self::EXPIRY_WATCH_DAYS => 'watch',
            default => null,
        };
    }

    /**
     * Whole calendar days until expiry; negative once expired.
     *
     * Measured from today() (midnight), NOT now(). expiry_date is a date with
     * no time, so diffing from the current timestamp truncated a partial day
     * and reported one day fewer than the calendar: at 23:34 on Aug 18, an
     * Aug 25 expiry came out as 6 days instead of 7.
     */
    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expiry_date
            ? (int) today()->diffInDays($this->expiry_date->copy()->startOfDay(), false)
            : null;
    }

    public function getFailedReturnAttribute(): bool
    {
        return $this->return_status === 'Fail to Return';
    }

    public function getIsReturnedAttribute(): bool
    {
        return $this->return_status === 'Successfully Returned';
    }

    /**
     * Should this batch offer a "Mark Returned" action?
     *
     * This is the one place that applies the category check `return_status`
     * asks its consumers to make. Without it the views each gated the button
     * on `$product->is_medicine` and simply showed nothing for everything
     * else -- so a non-pharma product could carry a "Need to Return" badge
     * with no way to act on it, while every "Fail to Return" row (pharma by
     * definition) had a button. That asymmetry was the visible bug.
     *
     *   Medicine    - only while INSIDE the 90-120 day supplier window.
     *                 "Fail to Return" deliberately gets no button: the window
     *                 has been missed, the supplier will not take the stock
     *                 back, and offering the action implies otherwise. Those
     *                 batches are a write-off to be disposed of, not returned.
     *   Everything else - no supplier window exists, so it falls back to the
     *                 same plain expiry rule Product::getNeedsReturnAttribute
     *                 uses: expired, or within NON_PHARMA_RETURN_WINDOW_DAYS
     *                 of expiring. There is no "fail" state on this side, so
     *                 an expired non-pharma batch still offers the action.
     *
     * Memoized like is_expired and return_status -- the inventory list reads
     * it once per batch per row, across ~2,500 batches.
     */
    public function getIsReturnableAttribute(): bool
    {
        return $this->derivedMemo['is_returnable'] ??= (function (): bool {
            if ($this->returned_at || $this->quantity <= 0 || ! $this->expiry_date) {
                return false;
            }

            if ($this->product && ! $this->product->is_medicine) {
                return $this->is_expired
                    || $this->days_to_expiry <= Product::NON_PHARMA_RETURN_WINDOW_DAYS;
            }

            return $this->needs_return;
        })();
    }
}
