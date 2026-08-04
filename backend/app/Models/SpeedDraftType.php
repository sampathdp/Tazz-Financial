<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedDraftType extends Model
{
    protected $fillable = [
        'name', 'code', 'category', 'reference_format',
        'interest_rate_percent', 'rate_period',
        'min_amount', 'max_amount', 'min_period_months', 'max_period_months',
        'grace_period_days', 'requires_collateral',
        'sort_order', 'status',
    ];

    protected $casts = [
        'interest_rate_percent' => 'decimal:3',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'min_period_months' => 'integer',
        'max_period_months' => 'integer',
        'grace_period_days' => 'integer',
        'requires_collateral' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function speedDrafts()
    {
        return $this->hasMany(SpeedDraft::class);
    }
}
