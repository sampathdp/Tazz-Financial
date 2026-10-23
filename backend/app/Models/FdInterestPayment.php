<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdInterestPayment extends Model
{
    protected $fillable = [
        'fixed_deposit_id', 'installment_no', 'voucher_no', 'due_date', 'amount',
        'status', 'paid_amount', 'paid_at', 'payment_method', 'reference_no', 'bank_account_id', 'paid_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'date:Y-m-d',
    ];

    public function fixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class);
    }

    /** The company bank account the payout was made from (Bank / Cheque methods). */
    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
