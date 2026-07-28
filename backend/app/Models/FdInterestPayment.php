<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdInterestPayment extends Model
{
    protected $fillable = [
        'fixed_deposit_id', 'installment_no', 'due_date', 'amount',
        'status', 'paid_amount', 'paid_at', 'payment_method', 'reference_no', 'paid_by',
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

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
