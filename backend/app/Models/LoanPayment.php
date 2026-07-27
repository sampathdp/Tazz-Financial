<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'code', 'loan_id', 'branch_id', 'amount', 'payment_method', 'clearance_status', 'is_settlement',
        'received_by', 'paid_at', 'receipt_no', 'bank_name', 'cheque_date', 'notes',
        'status', 'approval_status', 'approved_by', 'approved_at',
        'void_reason', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_settlement' => 'boolean',
        'paid_at' => 'datetime',
        'cheque_date' => 'date',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations()
    {
        return $this->hasMany(LoanPaymentAllocation::class);
    }
}
