<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCoApplicant extends Model
{
    protected $fillable = ['credit_facility_id', 'co_applicant_customer_id', 'share_percent'];

    protected $casts = [
        'share_percent' => 'decimal:2',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    /** The customer standing as a joint applicant/co-borrower on this facility. */
    public function coApplicant()
    {
        return $this->belongsTo(Customer::class, 'co_applicant_customer_id');
    }
}
