<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityGuarantor extends Model
{
    protected $fillable = ['credit_facility_id', 'guarantor_customer_id', 'guaranteed_amount'];

    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    /** The customer standing as guarantor for this facility. */
    public function guarantor()
    {
        return $this->belongsTo(Customer::class, 'guarantor_customer_id');
    }
}
