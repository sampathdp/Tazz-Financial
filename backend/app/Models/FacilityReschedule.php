<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * One reschedule (restructure) event on a loan — see the
 * facility_reschedules migration and LoanController::reschedule().
 * credit_facility_id is the loan that was rescheduled (now Restructured);
 * new_credit_facility_id is its replacement.
 */
class FacilityReschedule extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'credit_facility_id', 'new_credit_facility_id', 'branch_id', 'as_of_date',
        'capital_outstanding', 'unpaid_interest', 'overdue_interest', 'default_charges', 'other_charges',
        'interest_deduction', 'capitalised_interest', 'interest_written_off',
        'default_charges_action', 'retained_default_charges',
        'other_charges_rolled', 'added_charges_total', 'added_charges',
        'new_principal', 'new_interest_rate_percent', 'new_term_months',
        'new_interest_method', 'new_installment_frequency',
        'reason', 'created_by',
    ];

    protected $casts = [
        'as_of_date' => 'date:Y-m-d',
        'capital_outstanding' => 'decimal:2',
        'unpaid_interest' => 'decimal:2',
        'overdue_interest' => 'decimal:2',
        'default_charges' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'interest_deduction' => 'decimal:2',
        'capitalised_interest' => 'decimal:2',
        'interest_written_off' => 'decimal:2',
        'retained_default_charges' => 'decimal:2',
        'other_charges_rolled' => 'decimal:2',
        'added_charges_total' => 'decimal:2',
        'added_charges' => 'array',
        'new_principal' => 'decimal:2',
        'new_interest_rate_percent' => 'decimal:3',
        'new_term_months' => 'integer',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function newFacility()
    {
        return $this->belongsTo(Loan::class, 'new_credit_facility_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
