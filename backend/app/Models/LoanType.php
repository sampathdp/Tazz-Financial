<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanType extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'code', 'is_product', 'frequency',
        'interest_method', 'interest_rate_percent', 'rate_period',
        'min_amount', 'max_amount', 'min_term_months', 'max_term_months',
        'penalty_rate_percent', 'penalty_method', 'processing_fee_percent',
        'sort_order', 'status',
    ];

    protected $casts = [
        'is_product' => 'boolean',
        'interest_rate_percent' => 'decimal:3',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'penalty_rate_percent' => 'decimal:3',
        'processing_fee_percent' => 'decimal:3',
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
