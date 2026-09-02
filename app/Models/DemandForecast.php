<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemandForecast extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_sku',
        'forecast_date',
        'forecast_value',
        'lower_ci',
        'upper_ci',
        'generated_at',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'forecast_value' => 'float',
        'lower_ci' => 'float',
        'upper_ci' => 'float',
        'generated_at' => 'datetime',
    ];

    public function scopeForProduct($query, string $productSku)
    {
        return $query->where('product_sku', $productSku)->orderBy('forecast_date');
    }
}
