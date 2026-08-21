<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanFacilityDetail extends Model
{
    protected $table = 'loan_facility_details';

    protected $primaryKey = 'credit_facility_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'loan_type_id', 'vehicle_no', 'down_payment', 'disbursement_method', 'interest_method', 'installment_frequency',
        'effective_monthly_rate', 'effective_annual_rate',
        'purpose', 'application_date', 'reference_name', 'reference_id_no', 'reference_address',
        'loan_officer_id', 'recovery_officer_id', 'first_installment_date',
        'freeze_date', 'confirmed_date', 'confirmed_by',
    ];

    protected $casts = [
        'down_payment' => 'decimal:2',
        'effective_monthly_rate' => 'decimal:8',
        'effective_annual_rate' => 'decimal:8',
        'application_date' => 'date:Y-m-d',
        'first_installment_date' => 'date:Y-m-d',
        'freeze_date' => 'date:Y-m-d',
        'confirmed_date' => 'date:Y-m-d',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function loanOfficer()
    {
        return $this->belongsTo(User::class, 'loan_officer_id');
    }

    public function recoveryOfficer()
    {
        return $this->belongsTo(User::class, 'recovery_officer_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
