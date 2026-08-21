<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningType extends Model
{
    protected $fillable = [
        'name', 'code', 'normal_rate_percent', 'special_rate_percent', 'special_rate_days',
        'cycle_days', 'ltv_percent', 'max_renewal_count', 'grace_period_days',
        'auction_eligible_days', 'auction_notice_days', 'status', 'sort_order',
    ];

    protected $casts = [
        'normal_rate_percent' => 'decimal:3',
        'special_rate_percent' => 'decimal:3',
        'special_rate_days' => 'integer',
        'cycle_days' => 'integer',
        'ltv_percent' => 'decimal:2',
        'max_renewal_count' => 'integer',
        'grace_period_days' => 'integer',
        'auction_eligible_days' => 'integer',
        'auction_notice_days' => 'integer',
        'sort_order' => 'integer',
    ];
}
