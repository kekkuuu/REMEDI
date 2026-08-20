<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReceipt extends Model
{
    protected $fillable = [
        'product_sku',
        'qty',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'date',
    ];
}
