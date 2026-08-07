<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingProduct extends Model
{
    protected $fillable = [
        'name', 'code', 'interest_method', 'allow_method_choice', 'annual_rate_percent', 'monthly_rate_percent', 'rate_is_manual',
        'allowed_terms_months', 'allow_custom_term', 'min_deposit_amount', 'max_deposit_amount',
        'reference_format', 'sort_order', 'status',
    ];

    protected $casts = [
        'allow_method_choice' => 'boolean',
        'annual_rate_percent' => 'decimal:3',
        'monthly_rate_percent' => 'decimal:3',
        'rate_is_manual' => 'boolean',
        'allowed_terms_months' => 'array',
        'allow_custom_term' => 'boolean',
        'min_deposit_amount' => 'decimal:2',
        'max_deposit_amount' => 'decimal:2',
    ];

    public function fixedDeposits()
    {
        return $this->hasMany(FixedDeposit::class);
    }
}
