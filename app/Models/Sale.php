<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_no',
        'user_id',
        'total_amount',
        'amount_paid',
        'change_due',
        // Retained for historical sales only. The supervisor passcode that
        // could set this has been removed from checkout, which now always
        // writes false -- but sales taken before that keep their true value
        // so receipts and reports don't misreport them as normally paid.
        'payment_voided',
    ];

    protected $casts = [
        'payment_voided' => 'boolean',
    ];

    // A sale belongs to the user (cashier) who made it
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // A sale has many line items
    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * May this user see this sale?
     *
     * Staff see only their own transactions; admins see everything. Lives here
     * rather than being written out at each call site because THREE routes
     * expose the same sale and only one of them was checking:
     *
     *   /sales/{sale}         SaleController::show   — guarded, 403
     *   /pos/receipt/{sale}   PosController::receipt — NOT guarded, 200
     *   /suggest/sales        SuggestController      — was not scoped either
     *
     * A staff account could read any cashier's full receipt — transaction
     * number, date, cashier name, every line item, total, payment and change —
     * just by walking the ids, while the sales page returned 403 for the same
     * record. Any new route that renders a sale must call this.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->isAdmin() || $this->user_id === $user->id;
    }

    /** Length of the zero-padded counter in a transaction number. */
    public const SEQUENCE_PAD = 5;

    /** The `TXN-YYYYMMDD-` stem every transaction number for a given day shares. */
    public static function transactionPrefix(?\DateTimeInterface $date = null): string
    {
        return 'TXN-'.($date ? Carbon::instance($date) : now())->format('Ymd').'-';
    }

    /**
     * The next transaction number for today.
     *
     * Counts within THIS DAY, off the highest number already issued today —
     * not off `Sale::count()`, which is what this replaced. That was a global
     * row count stamped behind a per-day prefix, and the two disagree in two
     * ways that both end at the same place: a `QueryException` on
     * `sales.transaction_no`, which is `unique()`.
     *
     *  1. It regresses when ANY sale is deleted. The count drops, so the next
     *     checkout re-issues a number today has already used. Live data on this
     *     install already shows the drift — 2026-08-20 reached 00040 while
     *     2026-08-23 only reached 00031 — so rows have been removed before.
     *  2. Two registers checking out at once both read the same count inside
     *     their own transactions and both compute the same number.
     *
     * `lockForUpdate()` serialises concurrent checkouts once the day has at
     * least one sale: the second register blocks on the first register's lock
     * and re-reads the real maximum after it commits. It cannot close the
     * first-sale-of-the-day case on its own — two transactions may both hold a
     * gap lock over an empty range — so `PosController::checkout` retries the
     * transaction on a duplicate key. Both halves are needed; neither is
     * sufficient alone.
     *
     * Must be called inside a transaction, or the lock is released immediately
     * and buys nothing.
     */
    public static function nextTransactionNo(): string
    {
        $prefix = static::transactionPrefix();

        // The row, not MAX() — an aggregate's locking behaviour is murkier, and
        // ordering on the zero-padded string is the same as ordering on the
        // number it encodes, so this is an index-ordered read of one record.
        $last = static::where('transaction_no', 'like', $prefix.'%')
            ->orderByDesc('transaction_no')
            ->lockForUpdate()
            ->value('transaction_no');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, self::SEQUENCE_PAD, '0', STR_PAD_LEFT);
    }

    /*
     * seasonalTrends() used to live here, computed over this table. It was
     * moved to App\Models\SalesHistory: `sales` only holds live POS
     * checkouts, which on a real install span far too few months for a
     * season to be visible. Both callers (Dashboard card, Analytics report)
     * now use SalesHistory::seasonalTrends(), which returns the same shape.
     */
}
