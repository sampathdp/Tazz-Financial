<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanInsuranceDetail extends Model
{
    protected $primaryKey = 'credit_facility_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'insurance_company', 'policy_no', 'amount', 'contact_person', 'coverage', 'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }
}
