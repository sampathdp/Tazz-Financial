<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdWithdrawal extends Model
{
    use \App\Models\Concerns\HasVoidedRows;

    protected $fillable = [
        'fixed_deposit_id', 'voucher_no', 'principal_amount', 'interest_amount', 'total_amount', 'adjusted_amount', 'settled_credit_facility_id',
        'post_maturity_days', 'post_maturity_rate_percent', 'post_maturity_interest',
        'payment_method', 'reference_no', 'bank_account_id', 'notes', 'closed_at', 'created_by',
        'is_premature', 'premature_rate_percent', 'premature_held_days', 'premature_clawback',
    ];

    protected $casts = [
        'is_premature' => 'boolean',
        'premature_rate_percent' => 'decimal:3',
        'premature_clawback' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'adjusted_amount' => 'decimal:2',
        'post_maturity_rate_percent' => 'decimal:3',
        'post_maturity_interest' => 'decimal:2',
        'closed_at' => 'date:Y-m-d',
    ];

    public function fixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
