<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsProduct extends Model
{
    protected $fillable = [
        'name', 'code', 'interest_rate_percent', 'interest_calc_method', 'interest_posting_frequency',
        'min_opening_deposit', 'min_balance', 'withdrawal_fee', 'ledger_fee', 'dormancy_days',
        'reference_format', 'sort_order', 'status',
    ];

    protected $casts = [
        'interest_rate_percent' => 'decimal:3',
        'min_opening_deposit' => 'decimal:2',
        'min_balance' => 'decimal:2',
        'withdrawal_fee' => 'decimal:2',
        'ledger_fee' => 'decimal:2',
        'dormancy_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function accounts()
    {
        return $this->hasMany(SavingsAccount::class);
    }
}
