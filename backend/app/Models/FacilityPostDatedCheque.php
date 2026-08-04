<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class FacilityPostDatedCheque extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'credit_facility_id', 'branch_id', 'cheque_no', 'bank_id', 'cheque_date', 'amount',
        'status', 'handed_at', 'realized_at', 'facility_payment_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'cheque_date' => 'date',
        'amount' => 'decimal:2',
        'handed_at' => 'date',
        'realized_at' => 'date',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'facility_payment_id');
    }
}
