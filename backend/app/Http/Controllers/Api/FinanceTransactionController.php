<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\FinanceAuditEvent;
use App\Models\FinanceSetting;
use App\Models\FinanceTransaction;
use App\Support\CurrentBranch;
use App\Support\FinancePostingService;
use Illuminate\Http\Request;

/**
 * The immutable money-movement ledger (finance_transactions) — read-only
 * except for reversals, which are either done directly (if the
 * reversal_requires_approval setting is off) or through the finance_reversal
 * maker-checker.
 */
class FinanceTransactionController extends Controller
{
    private const WITH = [
        'branch:id,name,code', 'executor:id,name', 'requester:id,name',
        'journalEntry:id,code,status', 'reversalOf:id,code', 'reversal:id,code,status',
    ];

    public function index(Request $request)
    {
        $q = CurrentBranch::applyScope(FinanceTransaction::with(self::WITH))->orderByDesc('id');

        if ($t = $request->query('txn_type')) {
            $q->whereIn('txn_type', explode(',', $t));
        }
        if ($s = $request->query('status')) {
            $q->whereIn('status', explode(',', $s));
        }
        if ($from = $request->query('from')) {
            $q->whereDate('executed_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('executed_at', '<=', $to);
        }
        if ($acc = $request->query('cash_account_id')) {
            $q->where(fn ($w) => $w->where(['source_account_type' => 'cash', 'source_account_id' => $acc])
                ->orWhere(['dest_account_type' => 'cash', 'dest_account_id' => $acc]));
        }
        if ($term = $request->query('q')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
        }

        return $q->paginate((int) $request->query('per_page', 50));
    }

    public function show(FinanceTransaction $financeTransaction)
    {
        return $financeTransaction->load(array_merge(self::WITH, [
            'pendingReverseRequest:id,subject_id,status,required_approvals,requested_by,first_approved_by',
            'pendingReverseRequest.requester:id,name',
        ]));
    }

    /** Reverse a completed transaction — direct if allowed by settings, otherwise raises a finance_reversal approval. */
    public function reverseRequest(Request $request, FinanceTransaction $financeTransaction)
    {
        if ($financeTransaction->status !== 'Completed') {
            return response()->json(['message' => 'Only a completed transaction can be reversed.'], 422);
        }
        if ($financeTransaction->reversal_of_id) {
            return response()->json(['message' => 'A reversal entry cannot itself be reversed.'], 422);
        }
        if ($financeTransaction->pendingReverseRequest()->exists()) {
            return response()->json(['message' => 'A reversal is already pending approval for this transaction.'], 422);
        }
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if (!FinanceSetting::bool('reversal_requires_approval', true)) {
            $reversal = FinancePostingService::reverse($financeTransaction, $data['reason'], $request->user()->id);

            return response()->json(['message' => 'Transaction reversed.', 'reversal' => $reversal->load(self::WITH)], 201);
        }

        $type = ApprovalType::where('code', 'finance_reversal')->firstOrFail();
        $approval = ApprovalRequest::create([
            'approval_type_id' => $type->id,
            'subject_id' => $financeTransaction->id,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'reason' => $data['reason'],
            'required_approvals' => $type->required_approvals,
        ]);
        FinanceAuditEvent::record('transaction.reverse_requested', $financeTransaction, [
            'amount' => $financeTransaction->amount, 'reason' => $data['reason'],
            'meta' => ['code' => $financeTransaction->code],
        ]);

        return response()->json(['message' => 'Reversal sent for approval.', 'approvalRequest' => $approval->load('requester:id,name')], 201);
    }

    public function approveReverse(Request $request, ApprovalRequest $approvalRequest)
    {
        if ($approvalRequest->approvalType->code !== 'finance_reversal') {
            abort(404);
        }
        if ($approvalRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'finance_reversal')) {
            return response()->json(['message' => 'You are not an approver for finance reversals.'], 403);
        }
        if ($approvalRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own reversal request.'], 422);
        }
        try {
            $complete = $approvalRequest->recordApproval($request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($complete) {
            $ftx = FinanceTransaction::findOrFail($approvalRequest->subject_id);
            FinancePostingService::reverse($ftx, $approvalRequest->reason ?? 'Approved reversal', $request->user()->id);
            $approvalRequest->update(['status' => 'Approved']);
        }

        return $approvalRequest->fresh()->load('requester:id,name', 'firstApprover:id,name');
    }

    public function rejectReverse(Request $request, ApprovalRequest $approvalRequest)
    {
        if ($approvalRequest->approvalType->code !== 'finance_reversal') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'finance_reversal')) {
            return response()->json(['message' => 'You are not an approver for finance reversals.'], 403);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $approvalRequest->update([
            'status' => 'Rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'reject_reason' => $data['reject_reason'] ?? null,
        ]);

        return $approvalRequest->fresh();
    }
}
