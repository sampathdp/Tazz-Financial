<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityChargeAllocation extends Model
{
    protected $fillable = ['facility_payment_id', 'facility_charge_id', 'amount_applied'];

    protected $casts = [
        'amount_applied' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'facility_payment_id');
    }

    public function charge()
    {
        return $this->belongsTo(FacilityCharge::class, 'facility_charge_id');
    }
}
