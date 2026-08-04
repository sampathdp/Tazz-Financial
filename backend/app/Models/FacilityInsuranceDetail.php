<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared between Loan (Leasing/Mortgage) and SpeedDraft (Lease/Mortgage) —
 *  see the facility_insurance_details migration docblock. */
class FacilityInsuranceDetail extends Model
{
    protected $table = 'facility_insurance_details';

    protected $primaryKey = 'credit_facility_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'insurance_company', 'policy_no', 'amount', 'contact_person', 'coverage', 'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }
}
