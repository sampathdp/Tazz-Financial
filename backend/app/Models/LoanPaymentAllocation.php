<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPaymentAllocation extends Model
{
    protected $fillable = ['loan_payment_id', 'loan_schedule_id', 'principal_applied', 'interest_applied', 'penalty_applied'];

    protected $casts = [
        'principal_applied' => 'decimal:2',
        'interest_applied' => 'decimal:2',
        'penalty_applied' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }

    public function schedule()
    {
        return $this->belongsTo(LoanSchedule::class, 'loan_schedule_id');
    }
}
