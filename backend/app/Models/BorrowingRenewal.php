<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowingRenewal extends Model
{
    protected $fillable = [
        'borrowing_id', 'renewed_on', 'previous_maturity_date', 'new_maturity_date',
        'previous_term_months', 'new_term_months',
        'previous_interest_rate_percent', 'new_interest_rate_percent',
        'interest_paid_amount', 'notes', 'created_by',
    ];

    protected $casts = [
        'renewed_on' => 'date:Y-m-d',
        'previous_maturity_date' => 'date:Y-m-d',
        'new_maturity_date' => 'date:Y-m-d',
        'previous_interest_rate_percent' => 'decimal:3',
        'new_interest_rate_percent' => 'decimal:3',
        'interest_paid_amount' => 'decimal:2',
    ];

    public function borrowing()
    {
        return $this->belongsTo(Borrowing::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
