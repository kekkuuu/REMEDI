<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    public function scopeNextMonthOnly($query)
    {
        $nextMonth = now()->addMonthNoOverflow()->startOfMonth()->toDateString();

        return $query->whereDate('forecast_date', $nextMonth);
    }

    public function scopeSixMonthTotals($query)
    {
        return $query->select(
                'product_sku',
                DB::raw('SUM(forecast_value) as total_forecast')
            )
            ->groupBy('product_sku')
            ->orderByDesc('total_forecast');
    }
}
