<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityPaymentAllocation extends Model
{
    protected $fillable = ['facility_payment_id', 'facility_schedule_id', 'principal_applied', 'interest_applied', 'penalty_applied'];

    protected $casts = [
        'principal_applied' => 'decimal:2',
        'interest_applied' => 'decimal:2',
        'penalty_applied' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'facility_payment_id');
    }

    public function schedule()
    {
        return $this->belongsTo(FacilitySchedule::class, 'facility_schedule_id');
    }
}
