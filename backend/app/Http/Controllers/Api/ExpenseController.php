<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\Expense;
use App\Services\NumberGenerator;
use App\Support\ExpensePaymentExecutor;
use App\Support\FinanceRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return Expense::with(['branch:id,name,code', 'category:id,name,chart_of_account_id', 'vendor:id,name', 'requester:id,name', 'approver:id,name', 'voucher',
            'pendingApprovalRequest:id,subject_id,status,required_approvals,requested_by,first_approved_by',
            'pendingApprovalRequest.requester:id,name', 'pendingApprovalRequest.firstApprover:id,name',
            'pendingPaymentRequest:id,code,source_id,status,amount,requested_by',
            'pendingPaymentRequest.requester:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->orderByDesc('id')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'description' => 'nullable|string|max:255',
        ]);
        $branch = Branch::findOrFail($data['branch_id']);
        $code = NumberGenerator::next('expense', ['branch_code' => $branch->code]);

        $expense = DB::transaction(function () use ($data, $code, $request) {
            $expense = Expense::create($data + [
                'code' => $code,
                'status' => 'Pending',
                'requested_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($code, $expense);

            $type = ApprovalType::where('code', 'expense_approval')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $expense->id,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'reason' => $data['description'] ?? null,
                'required_approvals' => $type->required_approvals,
            ]);

            return $expense;
        });

        return response()->json($expense->load('category:id,name'), 201);
    }

    /** Maker-checker via the shared approval_requests table (code
     *  'expense_approval') — mirrors LoanController::approveCancelRequest().
     *  The expense flips to Approved (ready for pay()) only once enough
     *  distinct approvals are in. */
    public function approve(Request $request, Expense $expense)
    {
        if ($expense->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending expense can be approved.'], 422);
        }
        $req = $expense->pendingApprovalRequest;
        if (!$req) {
            return response()->json(['message' => 'No pending approval request found for this expense.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'expense_approval')) {
            return response()->json(['message' => 'You are not an approver for expenses.'], 403);
        }
        if ($req->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own expense request.'], 422);
        }
        if ($req->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }

        DB::transaction(function () use ($expense, $req, $request) {
            if (!$req->first_approved_by) {
                $req->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $req->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($req->fresh()->approvalsCount() < $req->required_approvals) {
                return;
            }

            $expense->update(['status' => 'Approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $req->update(['status' => 'Approved']);

            self::postAccrual($expense->fresh(), $request->user()->id);
        });

        return $expense->fresh();
    }

    /**
     * Recognises an approved expense as it is incurred (accrual basis):
     * Dr the category's expense account / Cr 2300 Accrued Expenses. Payment
     * later relieves 2300 rather than hitting the expense account again
     * (see ExpensePaymentExecutor). Idempotent per expense.
     */
    public static function postAccrual(Expense $expense, ?int $userId): void
    {
        $category = $expense->category;
        if (!$category || !$category->chart_of_account_id) {
            return; // guarded again at pay() time with a clear message
        }
        if (\App\Models\JournalEntry::where('source_type', 'Expense')->where('source_id', $expense->id)
            ->where('description', 'like', 'Expense accrued%')->exists()) {
            return;
        }
        \App\Support\LedgerPoster::post([
            ['account_id' => $category->chart_of_account_id, 'debit' => (string) $expense->amount, 'credit' => 0, 'description' => $expense->description],
            ['account_id' => \App\Models\ChartOfAccount::system('2300')->id, 'debit' => 0, 'credit' => (string) $expense->amount],
        ], ($expense->expense_date ?? now())->toDateString(), [
            'source_type' => 'Expense',
            'source_id' => $expense->id,
            'description' => "Expense accrued {$expense->code}",
            'branch_id' => $expense->branch_id,
            'posted_by' => $userId,
        ]);
    }

    public function reject(Request $request, Expense $expense)
    {
        if ($expense->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending expense can be rejected.'], 422);
        }
        $req = $expense->pendingApprovalRequest;
        if ($req && !ApprovalType::isAuthorized($request->user(), 'expense_approval')) {
            return response()->json(['message' => 'You are not an approver for expenses.'], 403);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($expense, $data, $req, $request) {
            $expense->update(['status' => 'Rejected', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            if ($req) {
                $req->update([
                    'status' => 'Rejected',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'reject_reason' => $data['reject_reason'] ?? null,
                ]);
            }
        });

        return $expense->fresh();
    }

    private const PAY_RULES = [
        'payment_method' => ['required', 'in:Cash,Bank,Cheque'],
        'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
        'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
        'cheque_no' => 'required_if:payment_method,Cheque|nullable|string|max:20',
        'paid_at' => 'nullable|date',
    ];

    private function paymentBlockReason(Expense $expense): ?string
    {
        if ($expense->status !== 'Approved') {
            return 'Only an approved expense can be paid.';
        }
        if ($expense->pendingPaymentRequest()->exists()) {
            return 'A payment request for this expense is already awaiting finance approval or payment.';
        }

        return null;
    }

    /**
     * The direct pay path (Finance / admin, perm Expenses,Edit): moves the
     * money and records the PaymentVoucher in one call — see
     * ExpensePaymentExecutor. Everyone else raises a request via
     * requestPayment().
     */
    public function pay(Request $request, Expense $expense)
    {
        if ($block = $this->paymentBlockReason($expense)) {
            return response()->json(['message' => $block], 422);
        }
        $data = $request->validate(self::PAY_RULES);

        $voucher = ExpensePaymentExecutor::pay($expense, $data, $request->user()->id);

        return response()->json($voucher->load('journalEntry', 'financeTransaction'), 201);
    }

    /**
     * The request path (perm Expenses,Create): raises an ExpensePayment
     * finance request. Finance approves + executes it (money moves there),
     * then the CompleteExpensePaymentOnSettled listener writes the voucher
     * and flips the expense to Paid.
     */
    public function requestPayment(Request $request, Expense $expense)
    {
        if ($block = $this->paymentBlockReason($expense)) {
            return response()->json(['message' => $block], 422);
        }
        $category = $expense->category;
        if (!$category || !$category->chart_of_account_id) {
            return response()->json(['message' => "The expense category has no linked chart-of-accounts entry yet — set one in Masters › Expense Categories first."], 422);
        }
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'cheque_no' => 'nullable|string|max:20',
        ]);
        $method = $data['payment_method'] ?? 'Cash';

        $financeRequest = FinanceRequestService::open([
            'request_type' => 'ExpensePayment',
            'amount' => $expense->amount,
            'branch_id' => $expense->branch_id,
            'source_module' => 'Expense',
            'source_id' => $expense->id,
            'payee_type' => $expense->vendor_id ? 'Vendor' : 'Other',
            'payee_id' => $expense->vendor_id,
            'payee_name' => $expense->vendor?->name,
            'preferred_method' => $method,
            'purpose' => "Expense payment - {$expense->code}",
            'requested_by' => $request->user()->id,
            'meta' => [
                'gl_source_type' => 'Expense',
                'gl_source_id' => $expense->id,
                // Payment relieves the 2300 payable (recognised at approval time);
                // it does not hit the category expense account again.
                'offset_account_id' => \App\Models\ChartOfAccount::system('2300')->id,
                'payment_method' => $method,
                'cheque_no' => $data['cheque_no'] ?? null,
                'source_bank_account_id' => $method === 'Cash' ? null : ($data['bank_account_id'] ?? null),
            ],
        ]);

        return response()->json([
            'message' => 'Payment request sent to Finance.',
            'financeRequest' => $financeRequest->load('branch:id,name'),
        ], 201);
    }
}
