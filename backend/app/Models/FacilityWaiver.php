<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityWaiver extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'waiver_type', 'amount', 'reason', 'approved_by', 'waived_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'waived_at' => 'date',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
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
