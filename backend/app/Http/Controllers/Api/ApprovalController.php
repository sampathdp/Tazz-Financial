<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;

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

        return [
            'loanApplicationRequests' => $loanApplicationRequests,
            'speedDraftApplicationRequests' => $speedDraftApplicationRequests,
            'loanCancelRequests' => $cancelRequests,
            'paymentVoidRequests' => $voidRequests,
            'allocationRequests' => $allocationRequests,
        ];
    }
}
