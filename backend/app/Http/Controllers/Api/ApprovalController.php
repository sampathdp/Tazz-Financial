<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\UserAchievement;

/**
 * Admin-only consolidated inbox of everything awaiting approval — every
 * pending row in the shared approval_requests table (see that migration's
 * docblock), bucketed by approval_types.code. A future approval type shows
 * up here automatically once something creates a pending ApprovalRequest for
 * it — no change needed here. Loan Applications / Speed Drafts are approved
 * through this same table (codes loan_application/speed_draft_application —
 * see LoanController::approve()/SpeedDraftController::approve()), same as
 * every other bucket below.
 * Gated to the 'admin' role only (see routes/api.php), same as
 * Loan Adjustments and Cheque Clearance — not wired into the module
 * permission grid, deliberately kept out of per-role/per-user delegation.
 *
 * Finance module approvals (codes finance_request_payment / finance_transfer
 * / finance_float_* / finance_reversal) are NOT bucketed here — they have
 * their own dedicated Finance Requests screen with per-code canApprove()
 * gating and the approve / reject / execute actions in one place, which is a
 * better surface than this consolidated inbox for a queue that can be large.
 */
class ApprovalController extends Controller
{
    public function index()
    {
        $loanApplicationRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'loan:id,code,principal_amount,status,customer_id',
            'loan.customer:id,full_name',
            'loan.loanDetail.loanType:id,name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_application'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $speedDraftApplicationRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'speedDraft:id,code,principal_amount,status,customer_id',
            'speedDraft.customer:id,full_name',
            'speedDraft.speedDraftDetail.speedDraftType:id,name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'speed_draft_application'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $cancelRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'loan:id,code,principal_amount,status,customer_id',
            'loan.customer:id,full_name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_cancel'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $writeOffRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'loan:id,code,principal_amount,outstanding_balance_cache,status,customer_id',
            'loan.customer:id,full_name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'loan_write_off'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $voidRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'payment:id,code,amount,payment_type,credit_facility_id',
            'payment.facility:id,code,customer_id',
            'payment.facility.customer:id,full_name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_void'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $allocationRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'payment:id,code,amount,payment_type,manual_allocation,credit_facility_id',
            'payment.facility:id,code,customer_id',
            'payment.facility.customer:id,full_name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_manual_allocation'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $settlementRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'payment:id,code,amount,payment_type,credit_facility_id',
            'payment.facility:id,code,customer_id',
            'payment.facility.customer:id,full_name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'payment_settlement'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        $expenseRequests = ApprovalRequest::with([
            'requester:id,name',
            'firstApprover:id,name',
            'expense:id,code,amount,status,branch_id,expense_category_id,vendor_id',
            'expense.branch:id,name',
            'expense.category:id,name',
            'expense.vendor:id,name', 'expense.staff:id,staff_code,name',
        ])->whereHas('approvalType', fn ($q) => $q->where('code', 'expense_approval'))
            ->where('status', 'PendingApproval')->orderBy('requested_at')->get();

        // Staff achievements live in their own table (not approval_requests); shaped like the
        // other buckets so the inbox renders them with the same row.
        $achievementRequests = UserAchievement::with('user:id,name,branch_id', 'user.branch:id,name')
            ->where('status', UserAchievement::PENDING)->orderBy('updated_at')->get()
            ->map(fn (UserAchievement $a) => [
                'id' => $a->id,
                'requested_at' => $a->updated_at,
                'reason' => $a->description,
                'required_approvals' => 1,
                'requester' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'first_approver' => null,
                'achievement' => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'achieved_on' => $a->achieved_on?->format('Y-m-d'),
                    'branch' => $a->user?->branch?->name,
                ],
            ]);

        return [
            'loanApplicationRequests' => $loanApplicationRequests,
            'speedDraftApplicationRequests' => $speedDraftApplicationRequests,
            'loanCancelRequests' => $cancelRequests,
            'loanWriteOffRequests' => $writeOffRequests,
            'paymentVoidRequests' => $voidRequests,
            'allocationRequests' => $allocationRequests,
            'settlementRequests' => $settlementRequests,
            'expenseRequests' => $expenseRequests,
            'achievementRequests' => $achievementRequests,
        ];
    }
}
