<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    /*
     * seasonalTrends() used to live here, computed over this table. It was
     * moved to App\Models\SalesHistory: `sales` only holds live POS
     * checkouts, which on a real install span far too few months for a
     * season to be visible. Both callers (Dashboard card, Analytics report)
     * now use SalesHistory::seasonalTrends(), which returns the same shape.
     */
}
