<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single row in the shared approval_requests table — see that migration's
 * docblock for why every "submit, needs N approvals, then executes" workflow
 * in the system (loan cancellation, payment-void, future ones) shares this
 * one table instead of each getting its own.
 */
class ApprovalRequest extends Model
{
    protected $fillable = [
        'approval_type_id', 'subject_id', 'requested_by', 'requested_at', 'reason', 'required_approvals',
        'status', 'first_approved_by', 'first_approved_at', 'second_approved_by', 'second_approved_at',
        'third_approved_by', 'third_approved_at', 'fourth_approved_by', 'fourth_approved_at',
        'rejected_by', 'rejected_at', 'reject_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'first_approved_at' => 'datetime',
        'second_approved_at' => 'datetime',
        'third_approved_at' => 'datetime',
        'fourth_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'required_approvals' => 'integer',
    ];

    /** The four approver-slot column pairs, in order. Requests that need only
     *  1–2 approvals never touch the last two — the existing controllers fill
     *  first_/second_ inline; recordApproval() covers all four for Finance. */
    public const APPROVER_SLOTS = [
        ['first_approved_by', 'first_approved_at'],
        ['second_approved_by', 'second_approved_at'],
        ['third_approved_by', 'third_approved_at'],
        ['fourth_approved_by', 'fourth_approved_at'],
    ];

    public function approvalType()
    {
        return $this->belongsTo(ApprovalType::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function firstApprover()
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    public function secondApprover()
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function thirdApprover()
    {
        return $this->belongsTo(User::class, 'third_approved_by');
    }

    public function fourthApprover()
    {
        return $this->belongsTo(User::class, 'fourth_approved_by');
    }

    /** Only meaningful for the finance_request_payment / finance_transfer / finance_float_* buckets. */
    public function financeRequest()
    {
        return $this->hasOne(FinanceRequest::class, 'approval_request_id');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** subject_id has no real FK (see the migration), so these are only
     *  meaningful/eager-loadable when the caller already knows this row's
     *  approval_type — e.g. ApprovalController loads `loan` only for the
     *  loan_cancel bucket and `payment` only for the payment_void bucket. */
    public function loan()
    {
        return $this->belongsTo(Loan::class, 'subject_id');
    }

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'subject_id');
    }

    /** Only meaningful/eager-loadable for the speed_draft_application bucket — see loan()/payment() above. */
    public function speedDraft()
    {
        return $this->belongsTo(SpeedDraft::class, 'subject_id');
    }

    /** Only meaningful/eager-loadable for the cash_transfer bucket. */
    public function cashTransfer()
    {
        return $this->belongsTo(CashTransfer::class, 'subject_id');
    }

    /** Only meaningful/eager-loadable for the expense_approval bucket. */
    public function expense()
    {
        return $this->belongsTo(Expense::class, 'subject_id');
    }

    public function approvalsCount(): int
    {
        $count = 0;
        foreach (self::APPROVER_SLOTS as [$byCol]) {
            if ($this->$byCol) {
                $count++;
            }
        }

        return $count;
    }

    /** True once this user occupies an approver slot — used to refuse the same
     *  person a second approval slot on the same request. */
    public function hasApproved(int $userId): bool
    {
        foreach (self::APPROVER_SLOTS as [$byCol]) {
            if ((int) $this->$byCol === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fills the next free approver slot with this user and stamps the time.
     * Returns true once the request has reached required_approvals (the
     * caller then executes / flips status). Throws if the user already
     * approved or every slot is taken.
     */
    public function recordApproval(int $userId): bool
    {
        if ($this->hasApproved($userId)) {
            throw new \RuntimeException('You have already approved this request.');
        }
        foreach (self::APPROVER_SLOTS as [$byCol, $atCol]) {
            if (!$this->$byCol) {
                $this->$byCol = $userId;
                $this->$atCol = now();
                $this->save();

                return $this->approvalsCount() >= $this->required_approvals;
            }
        }
        throw new \RuntimeException('This request already has every approval slot filled.');
    }
}
