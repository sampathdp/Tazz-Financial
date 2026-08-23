<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdPartPayment extends Model
{
    protected $fillable = [
        'fixed_deposit_id', 'voucher_no', 'principal_amount', 'rate_percent', 'held_days',
        'interest_entitlement', 'interest_paid', 'clawback_amount', 'net_amount_paid',
        'payment_method', 'reference_no', 'notes', 'paid_at',
        'principal_before', 'monthly_interest_before', 'total_interest_before', 'maturity_amount_before',
        'created_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'rate_percent' => 'decimal:3',
        'held_days' => 'integer',
        'interest_entitlement' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'clawback_amount' => 'decimal:2',
        'net_amount_paid' => 'decimal:2',
        'paid_at' => 'date:Y-m-d',
        'principal_before' => 'decimal:2',
        'monthly_interest_before' => 'decimal:2',
        'total_interest_before' => 'decimal:2',
        'maturity_amount_before' => 'decimal:2',
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
