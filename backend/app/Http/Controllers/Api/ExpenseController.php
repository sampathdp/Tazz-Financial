<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentVoucher;
use App\Services\NumberGenerator;
use App\Support\LedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return Expense::with(['branch:id,name,code', 'category:id,name,chart_of_account_id', 'vendor:id,name', 'requester:id,name', 'approver:id,name', 'voucher'])
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
        });

        return $expense->fresh();
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

    /**
     * Settles an approved expense: posts the double-entry journal (Dr the
     * category's expense account, Cr the till/bank it left from — see
     * LedgerPoster), moves the physical balance on that till/bank account,
     * and records the PaymentVoucher tying it all together.
     */
    public function pay(Request $request, Expense $expense)
    {
        if ($expense->status !== 'Approved') {
            return response()->json(['message' => 'Only an approved expense can be paid.'], 422);
        }
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
            'cheque_no' => 'required_if:payment_method,Cheque|nullable|string|max:20',
            'paid_at' => 'nullable|date',
        ]);

        $category = ExpenseCategory::findOrFail($expense->expense_category_id);
        if (!$category->chart_of_account_id) {
            return response()->json(['message' => "The expense category \"{$category->name}\" has no linked chart-of-accounts entry yet — set one in Masters > Expense Categories first."], 422);
        }

        $voucher = DB::transaction(function () use ($expense, $category, $data, $request) {
            $paidAt = $data['paid_at'] ?? now();
            $isCash = $data['payment_method'] === 'Cash';

            if ($isCash) {
                $till = CashAccount::lockForUpdate()->findOrFail($data['cash_account_id']);
                $outAccount = ChartOfAccount::forCashTill($till);
                $till->update(['current_balance' => bcsub((string) $till->current_balance, (string) $expense->amount, 2)]);
            } else {
                $bank = BankAccount::lockForUpdate()->findOrFail($data['bank_account_id']);
                $outAccount = ChartOfAccount::forBankAccount($bank);
                $bank->update(['current_balance' => bcsub((string) $bank->current_balance, (string) $expense->amount, 2)]);
            }

            $entry = LedgerPoster::post([
                ['account_id' => $category->chart_of_account_id, 'debit' => $expense->amount, 'credit' => 0, 'description' => $expense->description],
                ['account_id' => $outAccount->id, 'debit' => 0, 'credit' => $expense->amount],
            ], Carbon::parse($paidAt)->toDateString(), [
                'source_type' => 'Expense',
                'source_id' => $expense->id,
                'description' => "Expense payment {$expense->code}",
                'branch_id' => $expense->branch_id,
                'posted_by' => $request->user()->id,
            ]);

            $voucherCode = NumberGenerator::next('payment_voucher', ['branch_code' => $expense->branch->code]);
            $voucher = PaymentVoucher::create([
                'code' => $voucherCode,
                'expense_id' => $expense->id,
                'vendor_id' => $expense->vendor_id,
                'amount' => $expense->amount,
                'payment_method' => $data['payment_method'],
                'paid_at' => $paidAt,
                'paid_by' => $request->user()->id,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cheque_no' => $data['cheque_no'] ?? null,
                'status' => 'Issued',
                'journal_entry_id' => $entry->id,
            ]);
            NumberGenerator::attach($voucherCode, $voucher);

            $expense->update(['status' => 'Paid']);

            return $voucher;
        });

        return response()->json($voucher->load('journalEntry'), 201);
    }
}
