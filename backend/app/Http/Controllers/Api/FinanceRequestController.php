<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceRequest;
use App\Support\FinanceRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Finance Requests inbox — the single queue for every outbound payment
 * and transfer. Create / submit / cancel are requester actions
 * (perm:Finance Requests,Create); approve / reject are gated inline by
 * ApprovalType::isAuthorized() (same maker-checker pattern as loan_cancel /
 * expense_approval); execute is perm:Finance Requests,Edit.
 *
 * Branch isolation is automatic via FinanceRequest's BelongsToBranch scope.
 */
class FinanceRequestController extends Controller
{
    private const WITH = [
        'branch:id,name,code', 'requester:id,name', 'approver:id,name', 'executor:id,name',
        'sourceCashAccount:id,name,code', 'sourceBankAccount:id,name',
        'approvalRequest:id,status,required_approvals,first_approved_by,second_approved_by,third_approved_by,fourth_approved_by,rejected_by,reject_reason',
        'approvalRequest.firstApprover:id,name', 'approvalRequest.secondApprover:id,name',
        'approvalRequest.thirdApprover:id,name', 'approvalRequest.fourthApprover:id,name',
        'financeTransaction:id,code,txn_type,amount,status,journal_entry_id',
    ];

    public function index(Request $request)
    {
        $q = FinanceRequest::with(self::WITH)->orderByDesc('id');

        if ($s = $request->query('status')) {
            $q->whereIn('status', explode(',', $s));
        }
        if ($t = $request->query('request_type')) {
            $q->where('request_type', $t);
        }
        if ($p = $request->query('priority')) {
            $q->where('priority', $p);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('requested_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('requested_at', '<=', $to);
        }
        if ($term = $request->query('q')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$term}%")->orWhere('payee_name', 'like', "%{$term}%")->orWhere('purpose', 'like', "%{$term}%"));
        }

        return $q->paginate((int) $request->query('per_page', 50));
    }

    public function show(FinanceRequest $financeRequest)
    {
        return $financeRequest->load(self::WITH);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(FinanceRequest::REQUEST_TYPES)],
            'amount' => 'required|numeric|min:0.01',
            'branch_id' => 'nullable|exists:branches,id',
            'source_module' => 'nullable|string|max:64',
            'source_id' => 'nullable|integer',
            'payee_type' => ['nullable', Rule::in(['Customer', 'Vendor', 'User', 'Other'])],
            'payee_id' => 'nullable|integer',
            'payee_name' => 'nullable|string|max:191',
            'preferred_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'purpose' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
            'priority' => ['nullable', Rule::in(['Normal', 'Urgent'])],
            'meta' => 'nullable|array',
            'submit' => 'nullable|boolean',
        ]);

        $req = FinanceRequestService::open($data + ['requested_by' => $request->user()->id]);

        return response()->json($req->load(self::WITH), 201);
    }

    public function submit(Request $request, FinanceRequest $financeRequest)
    {
        return FinanceRequestService::submit($financeRequest, $request->user()->id)->load(self::WITH);
    }

    public function approve(Request $request, FinanceRequest $financeRequest)
    {
        return FinanceRequestService::approve($financeRequest, $request->user()->id)->load(self::WITH);
    }

    public function reject(Request $request, FinanceRequest $financeRequest)
    {
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);

        return FinanceRequestService::reject($financeRequest, $request->user()->id, $data['reject_reason'] ?? null)->load(self::WITH);
    }

    public function cancel(Request $request, FinanceRequest $financeRequest)
    {
        return FinanceRequestService::cancel($financeRequest, $request->user()->id)->load(self::WITH);
    }

    public function execute(Request $request, FinanceRequest $financeRequest)
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['Cash', 'Bank'])],
            'cash_account_id' => 'required_if:method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:method,Bank|nullable|exists:bank_accounts,id',
            'offset_account_id' => 'nullable|exists:chart_of_accounts,id',
            'dest_cash_account_id' => 'nullable|exists:cash_accounts,id',
            'dest_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'amount' => 'nullable|numeric|min:0.01',
            'entry_date' => 'nullable|date',
        ]);

        return FinanceRequestService::execute($financeRequest, $data, $request->user()->id)->load(self::WITH);
    }
}
