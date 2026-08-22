<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credit_facilities row where facility_type = 'SpeedDraft'. See Loan for
 * the shared-table rationale. SpeedDraft-specific columns
 * (speed_draft_type_id, payment_frequency, disbursement_method) live on the
 * 1:1 speedDraftDetail relation (speed_draft_facility_details), accessed
 * explicitly, never proxied.
 */
class SpeedDraft extends Model
{
    use BelongsToBranch, HasPublicUuid, SoftDeletes;

    protected $table = 'credit_facilities';

    protected $fillable = [
        'branch_id', 'code', 'customer_id',
        'principal_amount', 'interest_rate_percent', 'rate_period', 'term_months',
        'start_date', 'maturity_date',
        'disbursed_amount', 'disbursed_at', 'outstanding_balance_cache',
        'status', 'applied_by', 'approved_by', 'approved_at', 'rejected_reason', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
        'term_months' => 'integer',
        'start_date' => 'date',
        'maturity_date' => 'date',
        'disbursed_amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
        'outstanding_balance_cache' => 'decimal:2',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('facilityType', function (Builder $builder) {
            $builder->where('credit_facilities.facility_type', 'SpeedDraft');
        });

        static::creating(function (SpeedDraft $speedDraft) {
            $speedDraft->facility_type = 'SpeedDraft';
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** The speed-draft-specific attributes for this facility — always present for a SpeedDraft row. */
    public function speedDraftDetail()
    {
        return $this->hasOne(SpeedDraftFacilityDetail::class, 'credit_facility_id');
    }

    /** Insurance policy on the leased/mortgaged asset — only present for
     *  Lease/Mortgage category drafts (see SpeedDraftType.category). */
    public function insurance()
    {
        return $this->hasOne(FacilityInsuranceDetail::class, 'credit_facility_id');
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collateral()
    {
        return $this->hasMany(FacilityCollateral::class, 'credit_facility_id');
    }

    public function schedules()
    {
        return $this->hasMany(FacilitySchedule::class, 'credit_facility_id')->orderBy('installment_no');
    }

    public function payments()
    {
        return $this->hasMany(FacilityPayment::class, 'credit_facility_id')->orderByDesc('paid_at');
    }

    /** Shared approval_requests table, scoped to this draft's 'speed_draft_application'
     *  rows via subject_id — mirrors Loan::applicationApprovalRequests()/pendingApplicationRequest().
     *  One per draft, created at store() time, resolved by approve()/reject(). */
    public function applicationApprovalRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'speed_draft_application'))
            ->orderByDesc('requested_at');
    }

    public function pendingApplicationRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'speed_draft_application'))
            ->where('status', 'PendingApproval');
    }
}
