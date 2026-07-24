<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanWaiver extends Model
{
    protected $fillable = [
        'loan_id', 'waiver_type', 'amount', 'reason', 'approved_by', 'waived_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'waived_at' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
