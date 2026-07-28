<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdWithdrawal extends Model
{
    protected $fillable = [
        'fixed_deposit_id', 'voucher_no', 'principal_amount', 'interest_amount', 'total_amount',
        'payment_method', 'reference_no', 'notes', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
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
