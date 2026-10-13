<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lending rules for loans secured by a fixed deposit of one funding product - see the fd_loan_policies migration. */
class FdLoanPolicy extends Model
{
    protected $fillable = [
        'funding_product_id', 'max_ltv_percent', 'rate_mode', 'rate_spread_percent', 'fixed_rate_percent',
        'min_loan_amount', 'max_loan_amount', 'processing_fee_percent', 'min_days_to_maturity',
        'cap_term_to_maturity', 'auto_adjust_on_maturity', 'status',
    ];

    protected $casts = [
        'max_ltv_percent' => 'decimal:2',
        'rate_spread_percent' => 'decimal:3',
        'fixed_rate_percent' => 'decimal:3',
        'min_loan_amount' => 'decimal:2',
        'max_loan_amount' => 'decimal:2',
        'processing_fee_percent' => 'decimal:2',
        'min_days_to_maturity' => 'integer',
        'cap_term_to_maturity' => 'boolean',
        'auto_adjust_on_maturity' => 'boolean',
    ];

    public function fundingProduct()
    {
        return $this->belongsTo(FundingProduct::class);
    }

    /** Annual loan rate this policy charges against the given FD. */
    public function loanRateFor(FixedDeposit $fd): float
    {
        return $this->rate_mode === 'fixed'
            ? (float) $this->fixed_rate_percent
            : round((float) $fd->interest_rate_percent + (float) $this->rate_spread_percent, 3);
    }
}
