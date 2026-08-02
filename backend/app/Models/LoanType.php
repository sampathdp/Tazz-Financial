<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanType extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'code', 'reference_format', 'is_product', 'frequency', 'collection_days',
        'interest_method', 'interest_rate_percent', 'rate_period',
        'min_amount', 'max_amount', 'min_term_months', 'max_term_months',
        'grace_period_days', 'default_charge_rate_percent', 'processing_fee_percent',
        'commission_type', 'commission_value', 'min_paid_installments',
        'sort_order', 'status',
    ];

    protected $casts = [
        'is_product' => 'boolean',
        'interest_rate_percent' => 'decimal:3',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'default_charge_rate_percent' => 'decimal:3',
        'grace_period_days' => 'integer',
        'processing_fee_percent' => 'decimal:3',
        'commission_value' => 'decimal:2',
        'min_paid_installments' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(LoanType::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(LoanType::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }
}
