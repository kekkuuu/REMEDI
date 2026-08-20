<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesForecast extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_sku',
        'forecast_date',
        'forecast_units',
        'lower_ci_units',
        'upper_ci_units',
        'forecast_revenue',
        'lower_ci_revenue',
        'upper_ci_revenue',
        'method',
        'generated_at',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'forecast_units' => 'float',
        'lower_ci_units' => 'float',
        'upper_ci_units' => 'float',
        'forecast_revenue' => 'float',
        'lower_ci_revenue' => 'float',
        'upper_ci_revenue' => 'float',
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
                \DB::raw('SUM(forecast_units) as total_units'),
                \DB::raw('SUM(forecast_revenue) as total_revenue')
            )
            ->groupBy('product_sku')
            ->orderByDesc('total_revenue');
    }
}
