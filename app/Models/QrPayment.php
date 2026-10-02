<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A PayMongo QR Ph code shown at the till -- see the qr_payments migration. */
class QrPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    /** Paid, but the sale could not be recorded; a person has to look. */
    public const STATUS_ATTENTION = 'attention';

    protected $fillable = ['intent_id', 'amount', 'items', 'payment_method', 'user_id', 'status', 'sale_id', 'message', 'expires_at', 'paid_at'];

    protected $casts = [
        'items' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
