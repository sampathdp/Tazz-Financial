<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class FacilityPayment extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'code', 'credit_facility_id', 'branch_id', 'amount', 'payment_type', 'payment_method',
        'status', 'approval_status', 'clearance_status', 'receipt_no', 'bank_id',
        'cheque_date', 'realized_at', 'received_by', 'approved_by', 'approved_at', 'paid_at',
        'notes', 'void_reason', 'voided_by', 'voided_at', 'manual_allocation',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cheque_date' => 'date',
        'realized_at' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'manual_allocation' => 'array',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations()
    {
        return $this->hasMany(FacilityPaymentAllocation::class);
    }

    public function chargeAllocations()
    {
        return $this->hasMany(FacilityChargeAllocation::class);
    }

    /** Set only when this payment was auto-created by realizing a post-dated cheque, rather than recorded directly as a Cheque-method payment. */
    public function postDatedCheque()
    {
        return $this->hasOne(FacilityPostDatedCheque::class, 'facility_payment_id');
    }

    /** Shared approval_requests table, scoped to this payment's 'payment_void'
     *  rows via subject_id — see ApprovalRequest's docblock. */
    public function voidRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_void'))
            ->orderByDesc('requested_at');
    }

    /** The one void request currently awaiting approval, if any — a payment can
     *  only ever have at most one live at a time, see voidRequest()'s guard. */
    public function pendingVoidRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_void'))
            ->where('status', 'PendingApproval');
    }

    /** Shared approval_requests table, scoped to this payment's
     *  'payment_manual_allocation' rows via subject_id — see store()'s guard,
     *  which only ever lets one be pending per payment. */
    public function allocationRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_manual_allocation'))
            ->orderByDesc('requested_at');
    }

    /** The one manual-allocation request currently awaiting approval, if any. */
    public function pendingAllocationRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_manual_allocation'))
            ->where('status', 'PendingApproval');
    }

    /** Shared approval_requests table, scoped to this payment's
     *  'payment_settlement' rows — a full-settlement payment held at
     *  approval_status='PendingApproval' until approved. See
     *  LoanPaymentController::approveSettlement(). */
    public function settlementRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_settlement'))
            ->orderByDesc('requested_at');
    }

    /** The one settlement request currently awaiting approval, if any. */
    public function pendingSettlementRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_settlement'))
            ->where('status', 'PendingApproval');
    }
}
