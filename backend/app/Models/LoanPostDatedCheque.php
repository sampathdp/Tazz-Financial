<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class LoanPostDatedCheque extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'loan_id', 'branch_id', 'cheque_no', 'bank_name', 'cheque_date', 'amount',
        'status', 'handed_at', 'realized_at', 'loan_payment_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'cheque_date' => 'date',
        'amount' => 'decimal:2',
        'handed_at' => 'date',
        'realized_at' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payment()
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }
}
