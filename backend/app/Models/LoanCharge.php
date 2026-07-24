<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class LoanCharge extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'loan_id', 'branch_id', 'charge_type', 'due_amount', 'paid_amount',
        'charged_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'charged_at' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
