<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanChargeAllocation extends Model
{
    protected $fillable = ['loan_payment_id', 'loan_charge_id', 'amount_applied'];

    protected $casts = [
        'amount_applied' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }

    public function charge()
    {
        return $this->belongsTo(LoanCharge::class, 'loan_charge_id');
    }
}
