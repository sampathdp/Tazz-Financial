<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanSchedule extends Model
{
    protected $fillable = [
        'loan_id', 'installment_no', 'due_date',
        'principal_due', 'interest_due', 'penalty_due', 'total_due',
        'paid_amount', 'paid_at', 'status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'principal_due' => 'decimal:2',
        'interest_due' => 'decimal:2',
        'penalty_due' => 'decimal:2',
        'total_due' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function allocations()
    {
        return $this->hasMany(LoanPaymentAllocation::class);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_due - (float) $this->paid_amount, 2);
    }
}
