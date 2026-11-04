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
        return Expense::with(['branch:id,name,code', 'category:id,name,chart_of_account_id', 'vendor:id,name', 'staff:id,staff_code,name', 'requester:id,name', 'approver:id,name', 'voucher', 'refunds.receiver:id,name',
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
            'vendor_id' => 'nullable|exists:vendors,id|prohibits:staff_id',
            'staff_id' => 'nullable|exists:staff,id',
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'expense_date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'is_refundable' => 'nullable|boolean',
            'expected_refund_date' => 'nullable|date|after_or_equal:expense_date|required_if:is_refundable,true,1',
        ]);
        $data['is_refundable'] = (bool) ($data['is_refundable'] ?? false);
        if ($data['is_refundable']) {
            $data['refund_status'] = 'Outstanding';
        } else {
            $data['expected_refund_date'] = null;
        }
        $branch = Branch::findOrFail($data['branch_id']);

        $expense = DB::transaction(function () use ($data, $branch, $request) {
            // Number drawn inside the transaction: a failed save must not leave a used number in the log.
            $code = NumberGenerator::next('expense', ['branch_code' => $branch->code]);
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
            $req->lockPending();
            if (Expense::lockForUpdate()->findOrFail($expense->id)->status !== 'Pending') {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'Only a pending expense can be approved.']);
            }
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
        // A refundable advance/deposit is an asset until returned or forfeited, not a cost.
        $debitAccountId = $expense->is_refundable
            ? \App\Models\ChartOfAccount::system('1410')->id
            : $category->chart_of_account_id;
        \App\Support\LedgerPoster::post([
            ['account_id' => $debitAccountId, 'debit' => (string) $expense->amount, 'credit' => 0, 'description' => $expense->description],
            ['account_id' => \App\Models\ChartOfAccount::system('2300')->id, 'debit' => 0, 'credit' => (string) $expense->amount],
        ], ($expense->expense_date ?? now())->toDateString(), [
            'source_type' => 'Expense',
            'source_id' => $expense->id,
            'description' => "Expense accrued {$expense->code}",
            'branch_id' => $expense->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /**
     * Records money coming back from the vendor for a paid refundable expense
     * (Dr till/bank / Cr 1410). With close=true the unreturned remainder is
     * forfeited to the category's expense account and the deposit is settled.
     */
    public function refund(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', new \App\Rules\Money],
            'close' => 'nullable|boolean',
            'payment_method' => 'nullable|in:Cash,Bank',
            'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:payment_method,Bank|nullable|exists:bank_accounts,id',
            'refunded_at' => 'nullable|date',
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:255',
        ]);
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $close = (bool) ($data['close'] ?? false);
        $hasMoney = bccomp($amount, '0', 2) > 0;
        if (!$hasMoney && !$close) {
            return response()->json(['message' => 'Enter the refund amount received, or close the deposit as forfeited.'], 422);
        }
        if ($hasMoney && empty($data['payment_method'])) {
            return response()->json(['message' => 'Choose whether the refund was received in cash or into a bank account.'], 422);
        }
        $userId = $request->user()->id;
        $date = isset($data['refunded_at']) ? \Illuminate\Support\Carbon::parse($data['refunded_at'])->toDateString() : now()->toDateString();

        $refund = DB::transaction(function () use ($expense, $data, $amount, $hasMoney, $close, $userId, $date) {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            if (!$expense->is_refundable || $expense->status !== 'Paid') {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'Only a paid refundable expense can be refunded.']);
            }
            if ($expense->refund_status === 'Settled') {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'This deposit is already settled.']);
            }
            $outstanding = bcsub(bcsub((string) $expense->amount, (string) $expense->refunded_amount, 2), (string) $expense->forfeited_amount, 2);
            if (bccomp($amount, $outstanding, 2) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => "Refund cannot exceed the outstanding deposit of {$outstanding}."]);
            }
            $forfeit = $close ? bcsub($outstanding, $amount, 2) : '0.00';
            $method = $hasMoney ? $data['payment_method'] : null;
            $nextNo = $expense->refunds()->count() + 1;
            $txn = null;

            if ($hasMoney) {
                if ($method === 'Cash') {
                    $till = \App\Models\CashAccount::findOrFail($data['cash_account_id']);
                    [$srcType, $srcId, $leaf] = ['cash', (int) $till->id, \App\Models\ChartOfAccount::forCashTill($till)];
                } else {
                    $bank = \App\Models\BankAccount::findOrFail($data['bank_account_id']);
                    [$srcType, $srcId, $leaf] = ['bank', (int) $bank->id, \App\Models\ChartOfAccount::forBankAccount($bank)];
                }
                $txn = \App\Support\FinancePostingService::execute([
                    'txn_type' => 'CASH_IN',
                    'amount' => $amount,
                    'entry_date' => $date,
                    'branch_id' => $expense->branch_id,
                    'movements' => [['account_type' => $srcType, 'account_id' => $srcId, 'delta' => '+' . $amount]],
                    'gl_lines' => [
                        ['account_id' => $leaf->id, 'debit' => $amount, 'credit' => 0],
                        ['account_id' => \App\Models\ChartOfAccount::system('1410')->id, 'debit' => 0, 'credit' => $amount, 'description' => $expense->description],
                    ],
                    'gl_source_type' => 'Expense',
                    'gl_source_id' => $expense->id,
                    'gl_description' => "Expense refund {$expense->code} #{$nextNo}",
                    'description' => "Expense refund {$expense->code} #{$nextNo}",
                    'source_module' => 'Expense',
                    'source_id' => $expense->id,
                    'idempotency_key' => "expense-refund-{$expense->id}-{$nextNo}",
                    'executed_by' => $userId,
                ]);
            }
            if (bccomp($forfeit, '0', 2) > 0) {
                \App\Support\LedgerPoster::post([
                    ['account_id' => $expense->category->chart_of_account_id, 'debit' => $forfeit, 'credit' => 0, 'description' => $expense->description],
                    ['account_id' => \App\Models\ChartOfAccount::system('1410')->id, 'debit' => 0, 'credit' => $forfeit],
                ], $date, [
                    'source_type' => 'Expense', 'source_id' => $expense->id,
                    'description' => "Expense deposit forfeited {$expense->code} #{$nextNo}",
                    'branch_id' => $expense->branch_id, 'posted_by' => $userId,
                ]);
            }

            $newRefunded = bcadd((string) $expense->refunded_amount, $amount, 2);
            $newForfeited = bcadd((string) $expense->forfeited_amount, $forfeit, 2);
            $left = bcsub(bcsub((string) $expense->amount, $newRefunded, 2), $newForfeited, 2);
            $expense->update([
                'refunded_amount' => $newRefunded,
                'forfeited_amount' => $newForfeited,
                'refund_status' => bccomp($left, '0', 2) === 0 ? 'Settled' : 'PartiallyRefunded',
            ]);

            return \App\Models\ExpenseRefund::create([
                'expense_id' => $expense->id, 'branch_id' => $expense->branch_id,
                'amount' => $amount, 'forfeited_amount' => $forfeit,
                'payment_method' => $method,
                'cash_account_id' => $method === 'Cash' ? $data['cash_account_id'] : null,
                'bank_account_id' => $method === 'Bank' ? $data['bank_account_id'] : null,
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'refunded_at' => $date, 'received_by' => $userId,
                'finance_transaction_id' => $txn?->id, 'journal_entry_id' => $txn?->journal_entry_id,
            ]);
        });

        return response()->json($refund->load('expense'), 201);
    }

    public function reject(Request $request, Expense $expense)
    {
        if ($expense->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending expense can be rejected.'], 422);
        }
        $req = $expense->pendingApprovalRequest;
        if (!ApprovalType::isAuthorized($request->user(), 'expense_approval')) {
            return response()->json(['message' => 'You are not an approver for expenses.'], 403);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($expense, $data, $req, $request) {
            if (Expense::lockForUpdate()->findOrFail($expense->id)->status !== 'Pending') {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'Only a pending expense can be rejected.']);
            }
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
            'payee_type' => $expense->staff_id ? 'Staff' : ($expense->vendor_id ? 'Vendor' : 'Other'),
            'payee_id' => $expense->staff_id ?: $expense->vendor_id,
            'payee_name' => $expense->staff?->name ?? $expense->vendor?->name,
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
