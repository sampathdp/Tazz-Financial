<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use App\Support\FdLienService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credit_facilities row where facility_type = 'Loan'. Shares the
 * credit_facilities table (and every facility_* sub-resource table) with
 * SpeedDraft — see the credit_facilities migration for why the two products
 * were merged. Loan-specific columns (loan_type_id, down_payment,
 * interest_method, ...) live on the 1:1 loanDetail relation
 * (loan_facility_details), not on this model — accessed explicitly via
 * ->loanDetail, never proxied, so both attribute access and query-builder
 * calls stay unambiguous about which table they hit.
 */
class Loan extends Model
{
    use BelongsToBranch, HasPublicUuid, SoftDeletes;

    protected $table = 'credit_facilities';

    protected $fillable = [
        'branch_id', 'code', 'customer_id', 'customer_bank_detail_id',
        'principal_amount', 'interest_rate_percent', 'rate_period', 'term_months',
        'start_date', 'maturity_date',
        'disbursed_amount', 'disbursed_at', 'outstanding_balance_cache',
        'status', 'applied_by', 'approved_by', 'approved_at', 'rejected_reason', 'closed_at', 'created_by',
        'cancelled_reason', 'cancelled_by', 'cancelled_at', 'written_off_reason', 'written_off_by',
        'npl_flag', 'provision_required_pct', 'provision_required_amount', 'provision_posted_amount', 'provision_last_evaluated_at',
        'restructured_from_id',
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
        'cancelled_at' => 'datetime',
        'npl_flag' => 'boolean',
        'provision_required_pct' => 'integer',
        'provision_required_amount' => 'decimal:2',
        'provision_posted_amount' => 'decimal:2',
        'provision_last_evaluated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('facilityType', function (Builder $builder) {
            $builder->where('credit_facilities.facility_type', 'Loan');
        });

        static::creating(function (Loan $loan) {
            Customer::assertNotBlacklisted($loan->customer_id);
            $loan->facility_type = 'Loan';
        });

        // A loan that ends without using its FD (rejected, cancelled, or fully repaid) frees the FD.
        static::updated(function (Loan $loan) {
            if ($loan->wasChanged('status') && in_array($loan->status, FdLienService::RELEASING_STATUSES, true)) {
                FdLienService::releaseForLoan($loan, "Loan {$loan->status}");
            }
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

    /** The customer account the disbursement is paid to. */
    public function bankDetail()
    {
        return $this->belongsTo(CustomerBankDetail::class, 'customer_bank_detail_id')->withTrashed();
    }

    /** The loan-specific attributes for this facility — always present for a Loan row. */
    public function loanDetail()
    {
        return $this->hasOne(LoanFacilityDetail::class, 'credit_facility_id');
    }

    /** Insurance policy on the leased/mortgaged asset — only present for
     *  Leasing/Mortgage category loans (see LoanType.category). */
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

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function writtenOffBy()
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Terminated/Legal/SeizingOrderIssued/Repossessed recovery events, newest first. */
    public function statusEvents()
    {
        return $this->hasMany(FacilityStatusEvent::class, 'credit_facility_id')->orderByDesc('created_at');
    }

    public function charges()
    {
        return $this->hasMany(FacilityCharge::class, 'credit_facility_id');
    }

    public function postDatedCheques()
    {
        return $this->hasMany(FacilityPostDatedCheque::class, 'credit_facility_id')->orderBy('cheque_date');
    }

    public function documents()
    {
        return $this->hasMany(FacilityDocument::class, 'credit_facility_id');
    }

    public function diaryNotes()
    {
        return $this->hasMany(FacilityDiaryNote::class, 'credit_facility_id')->orderByDesc('created_at');
    }

    public function waivers()
    {
        return $this->hasMany(FacilityWaiver::class, 'credit_facility_id')->orderByDesc('waived_at');
    }

    public function guarantors()
    {
        return $this->hasMany(FacilityGuarantor::class, 'credit_facility_id');
    }

    /** Joint applicants/co-borrowers on this loan — customer_id above stays the primary applicant. */
    public function coApplicants()
    {
        return $this->hasMany(FacilityCoApplicant::class, 'credit_facility_id');
    }

    public function collateral()
    {
        return $this->hasMany(FacilityCollateral::class, 'credit_facility_id');
    }

    /**
     * A disbursement that stopped halfway (a network drop, a failure after the loan row was saved): the loan
     * is Active with a disbursed date but never got its repayment schedule. It can be disbursed again.
     */
    public function disbursementIncomplete(): bool
    {
        return $this->status === 'Active' && $this->disbursed_at !== null && !$this->schedules()->exists();
    }

    public function schedules()
    {
        return $this->hasMany(FacilitySchedule::class, 'credit_facility_id')->orderBy('installment_no');
    }

    public function payments()
    {
        return $this->hasMany(FacilityPayment::class, 'credit_facility_id')->orderByDesc('paid_at');
    }

    /**
     * True once real money has been collected against the loan itself - schedule allocations, a
     * pending settlement/manual allocation or a cheque. The mandatory Document-charge receipt
     * (charge-only, no schedule allocation) does not count, otherwise every loan with a processing
     * fee would be uncancellable the moment it is disbursed.
     */
    public function hasCollectionHistory(): bool
    {
        return $this->payments()->reorder()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Voided'))
            ->where(fn ($q) => $q->whereHas('allocations')
                ->orWhereIn('payment_type', ['Settlement', 'ManualAllocation'])
                ->orWhere('payment_method', 'Cheque'))
            ->exists();
    }

    /** Impairment provision Post/Reverse audit trail — see LoanProvisioning. */
    public function provisions()
    {
        return $this->hasMany(LoanProvision::class, 'credit_facility_id')->orderByDesc('created_at');
    }

    /** Shared approval_requests table, scoped to this loan's 'loan_cancel'
     *  rows via subject_id — see ApprovalRequest's docblock. */
    public function cancelRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_cancel'))
            ->orderByDesc('requested_at');
    }

    /** The one cancel request currently awaiting approval, if any — a loan can
     *  only ever have at most one live at a time, see cancelRequest()'s guard. */
    public function pendingCancelRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_cancel'))
            ->where('status', 'PendingApproval');
    }

    /** Same shared-table pattern as cancelRequests()/pendingCancelRequest(),
     *  scoped to this loan's 'loan_write_off' rows — see
     *  LoanController::writeOffRequest(). */
    public function writeOffRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_write_off'))
            ->orderByDesc('requested_at');
    }

    /** The one write-off request currently awaiting approval, if any — at most
     *  one live at a time, see writeOffRequest()'s guard. */
    public function pendingWriteOffRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_write_off'))
            ->where('status', 'PendingApproval');
    }

    /** Recurring commission cycles for this loan's sales executive — see facility_type SalesExecutiveCommission. */
    public function salesExecutiveCommissions()
    {
        return $this->hasMany(SalesExecutiveCommission::class, 'credit_facility_id');
    }

    /** The loan this one replaced when it was rescheduled — null for an
     *  original (never-rescheduled) loan. See LoanController::reschedule(). */
    public function restructuredFrom()
    {
        return $this->belongsTo(Loan::class, 'restructured_from_id');
    }

    /** The replacement loan created when this one was rescheduled — set once
     *  this loan's status is 'Restructured'. */
    public function restructuredTo()
    {
        return $this->hasOne(Loan::class, 'restructured_from_id');
    }

    /** Reschedule audit rows where this loan was the one rescheduled. */
    public function reschedules()
    {
        return $this->hasMany(FacilityReschedule::class, 'credit_facility_id')->orderByDesc('created_at');
    }

    /** Same shared-table pattern as cancelRequests()/pendingCancelRequest() above,
     *  scoped to this loan's 'loan_application' rows — one per loan, created at
     *  store() time, resolved by approve()/reject(). */
    public function applicationApprovalRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_application'))
            ->orderByDesc('requested_at');
    }

    public function pendingApplicationRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_application'))
            ->where('status', 'PendingApproval');
    }

    /** The LoanDisbursement finance request currently in flight for this loan
     *  (raised by LoanController::requestDisbursement(), still open) — the
     *  marker that a request-path disbursement is underway. Never more than
     *  one at a time, guarded in disbursementBlockReason(). */
    public function pendingDisbursementRequest()
    {
        return $this->hasOne(FinanceRequest::class, 'source_id')
            ->where('source_module', 'Loan')
            ->where('request_type', 'LoanDisbursement')
            ->whereNotIn('status', ['Rejected', 'Cancelled', 'Completed', 'Reversed'])
            ->orderByDesc('id');
    }
}
