<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Holdout accuracy for one product's demand forecast.
 *
 * Written by `forecast:generate`, which refits the same cascade on the series
 * minus its last few months and scores it against the months held back.
 */
class ForecastAccuracy extends Model
{
    protected $table = 'forecast_accuracy';

    protected $fillable = [
        'product_sku',
        'mae',
        'rmse',
        'mape',
        'smape',
        'holdout_months',
        'points_scored',
        'points_scored_mape',
        'method',
        'generated_at',
    ];

    protected $casts = [
        'mae' => 'float',
        'rmse' => 'float',
        // Left nullable through the cast too: MAPE is undefined when every
        // month in the holdout sold nothing, and null must not become 0.0.
        'mape' => 'float',
        'smape' => 'float',
        'holdout_months' => 'integer',
        'points_scored' => 'integer',
        'points_scored_mape' => 'integer',
        'generated_at' => 'datetime',
    ];
}
