<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    /**
     * The Inventory link for the dashboards' and bell's "expiring soon" figures.
     *
     * Those surfaces count a 30-day horizon (EXPIRY_SOON_DAYS) while the
     * Inventory tab defaults to a 90-day planning view, so a bare
     * `?filter=expiring` link showed 85 products under a heading promising 28.
     * The horizon has to travel with the link.
     *
     * One definition because there are NINE of these links — four on the admin
     * dashboard, four on the staff dashboard, one in the shared actions partial
     * — plus the bell's. Nine literals would be nine chances for the tenth to
     * be written without the parameter, which is exactly how this drifted the
     * first time.
     */
    public static function expiringSoonUrl(): string
    {
        // Relative (third argument false). AlertService caches its payload, and
        // an absolute URL bakes in whichever host warmed the cache — see the
        // note on that class.
        return route('inventory.index', [
            'filter' => 'expiring',
            'days' => self::EXPIRY_SOON_DAYS,
        ], false);
    }

    /** How many letters of the product name a batch number carries. */
    public const BATCH_CODE_LETTERS = 3;

    /** Width of the zero-padded counter in an auto-generated batch number. */
    public const BATCH_SEQUENCE_PAD = 2;

    /**
     * The product's letters for a batch number: HERACLENE 1MG TAB -> HER.
     *
     * LETTERS ONLY, and that matters on this catalogue -- names here open with
     * digits and punctuation often enough that "the first three characters"
     * would produce codes like `3M ` and `G. `. Stripping to letters first
     * gives `3M TAPE` -> MTA and `G. CROSS ETHYL 70%` -> GCR, which still point
     * at the product a human is looking for.
     *
     * Padded with X when a name has fewer than three letters, so the code is
     * always the same width and the number always parses the same way.
     */
    public static function batchNameCode(Product $product): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', (string) $product->name);
        $code = strtoupper(substr($letters, 0, self::BATCH_CODE_LETTERS));

        return str_pad($code, self::BATCH_CODE_LETTERS, 'X');
    }

    /**
     * The stem every auto-generated batch number carries, for this product on
     * this day: `HER-20260901-`.
     *
     * Kept apart from nextBatchNumber() so the form's JS and the server agree
     * on the format by construction: the view echoes this prefix, the browser
     * appends its own guess at the sequence for display, and the SERVER is
     * still the one that decides — same split as `Sale::transactionPrefix()`
     * beside `Sale::nextTransactionNo()`.
     */
    public static function batchNumberPrefix(Product $product, \DateTimeInterface|string $receivedDate): string
    {
        $date = $receivedDate instanceof \DateTimeInterface
            ? Carbon::instance($receivedDate)
            : Carbon::parse($receivedDate);

        return self::batchNameCode($product).'-'.$date->format('Ymd').'-';
    }

    /**
     * The next batch number for this product on this delivery date.
     *
     * `AAA-YYYYMMDD-NN` -- three letters of the product name, the day it was
     * received, and a counter within THAT PRODUCT on THAT DAY.
     *
     * The date is the received date rather than today, because a delivery
     * entered a day late still belongs to the day it arrived -- that is the
     * whole point of the field being editable, and a number stamped with the
     * day someone got round to typing it in would contradict the
     * `received_date` beside it.
     *
     * The `-NN` is not decoration: without it a second delivery of the same
     * product on the same day would produce the identical number, and the two
     * rows in the batch table could not be told apart. It counts rather than
     * incrementing a stored maximum, which would regress the moment a batch is
     * deleted -- the bug `Sale::nextTransactionNo()` documents at length.
     *
     * Scoped per product, not globally: `batch_number` carries no unique
     * constraint and nothing joins on it, and the letters already separate two
     * different products received the same day.
     */
    public static function nextBatchNumber(Product $product, \DateTimeInterface|string $receivedDate): string
    {
        $prefix = static::batchNumberPrefix($product, $receivedDate);

        // lockForUpdate(), same shape as Sale::nextTransactionNo() -- and for
        // the same reason. Deriving the number server-side stops a user from
        // TYPING a colliding one, but does nothing about two requests both
        // reading "last -01" before either has inserted "-02": without a
        // lock held until the caller's transaction commits, two people
        // adding a batch for the same product on the same day can both be
        // handed the identical "-02", silently defeating the one thing this
        // numbering scheme exists for ("a second delivery... is the
        // identical string, and the two rows cannot be told apart"). Only
        // effective when the caller wraps this in DB::transaction() --
        // see ProductController::addBatch().
        //
        // Same residual gap Sale::nextTransactionNo() has, and for the same
        // reason: a row lock has nothing to lock when THIS is the first
        // batch for this product on this day -- no row matches the prefix
        // yet, so two concurrent first-of-day requests can still both land
        // on "-01". PosController::withTransactionNoRetry() closes that
        // narrower window for transaction numbers by retrying on a unique-
        // constraint violation; batch_number carries no unique constraint at
        // all (nothing joins on it -- see the table in CLAUDE.md), so there
        // is no violation to retry on without a schema change. Left as the
        // documented gap rather than adding one unilaterally.
        $last = static::where('product_id', $product->id)
            ->where('batch_number', 'like', $prefix.'%')
            ->orderByDesc('batch_number')
            ->lockForUpdate()
            ->value('batch_number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, self::BATCH_SEQUENCE_PAD, '0', STR_PAD_LEFT);
    }

    /**
     * The highest sequence already issued for this product, per received day.
     *
     * `['20260903' => 2, ...]`, for the Add New Batch form: the browser has to
     * show the number the save will actually produce, and it cannot ask the
     * server on every keystroke of a date field. The server still recomputes
     * on submit — this is the DISPLAY, nextBatchNumber() is the rule — but the
     * two agree because both read the same prefix and the same padding.
     *
     * Days with no generated batch are simply absent, and the caller treats
     * absent as zero, so the map only ever carries the handful of days this
     * product has actually received stock on.
     *
     * @return array<string, int>
     */
    public static function takenSequences(Product $product): array
    {
        $taken = [];

        foreach ($product->batches as $batch) {
            if (! $batch->received_date) {
                continue;
            }

            $day = $batch->received_date->format('Ymd');
            $prefix = static::batchNumberPrefix($product, $batch->received_date);

            if (! str_starts_with((string) $batch->batch_number, $prefix)) {
                continue;   // a supplier lot number, or a seeded OPENING-*
            }

            $sequence = (int) substr((string) $batch->batch_number, strlen($prefix));
            $taken[$day] = max($taken[$day] ?? 0, $sequence);
        }

        return $taken;
    }

    /**
     * The single definition of stock the till is allowed to sell.
     *
     * Three conditions, and each one was missing somewhere:
     *
     *  - `quantity > 0` — the only one anything checked.
     *  - not returned. Marking a batch returned records that it went back to
     *    the supplier; the units are no longer in the building.
     *  - not expired. FEFO orders by `expiry_date ASC`, which is right for
     *    rotation but means that WITHOUT this the register reaches for the
     *    most-expired batch FIRST. Measured before this existed: 79 batches /
     *    3,434 units / ₱41,351.99 of expired stock were sellable, with expired
     *    antihistamine syrup at the head of the queue.
     *
     * Mirrors is_sellable below, which is the in-memory form for callers that
     * already have the batches loaded. Keep the two in step.
     */
    public function scopeSellable($query)
    {
        return $query->where('quantity', '>', 0)
            ->whereNull('returned_at')
            // `> today`, not `>=`: is_expired treats the expiry date itself as
            // expired -- we do not sell on it.
            // A batch with no expiry recorded (historical import) cannot be
            // judged expired, so it stays sellable -- same call is_expired makes.
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', today()));
    }

    /**
     * In-memory twin of scopeSellable(), for the eager-loaded paths.
     *
     * Defers to is_expired rather than re-deriving the date, so there is still
     * only one definition of "expired" in the model.
     */
    public function getIsSellableAttribute(): bool
    {
        return $this->derivedMemo['is_sellable'] ??= (
            $this->quantity > 0
            && ! $this->returned_at
            && ! $this->is_expired
        );
    }

    /**
     * Sale lines drawn from this batch.
     *
     * FEFO checkout records which batch each line came out of, so this is how
     * you ask "has anything been sold from this batch?" — the question
     * ProductController::destroyBatch has to answer before deleting, because
     * `sale_items.product_batch_id` is ON DELETE RESTRICT.
     */
    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
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
            ? $this->product->non_pharma_return_window_days
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
     *                 uses: NOT expired, and within the product's own
     *                 non_pharma_return_window_days of expiring (10 days
     *                 flat, or 30 for Baby Care / Vitamins & Supplements).
     *                 Once actually expired it falls to
     *                 Product::getFailedReturnAttribute() instead, same as
     *                 medicine -- this used to treat "expired" as still
     *                 returnable on the theory that non-pharma has no "fail"
     *                 state, which reintroduced the exact asymmetry this
     *                 accessor exists to prevent: a live Mark Returned button
     *                 sitting on a batch the supplier will not take back.
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
                return ! $this->is_expired
                    && $this->days_to_expiry <= $this->product->non_pharma_return_window_days;
            }

            return $this->needs_return;
        })();
    }
}
