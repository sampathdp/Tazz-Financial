<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class FacilityCharge extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'credit_facility_id', 'branch_id', 'charge_type', 'due_amount', 'paid_amount',
        'charged_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'charged_at' => 'date',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
