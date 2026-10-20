<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\BranchFundingAgreement;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Services\BranchFundingService;
use App\Services\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Branch-to-branch cash funding through Head Office. A branch asks to borrow from another branch; Head Office
 * (Approve permission) checks the lender's cash and the borrower's limit, then approves, which pays the money out
 * and posts the three linked entries. See BranchFundingService for the ledger side.
 */
class BranchFundingController extends Controller
{
    private const MODES = ['monthly', 'on_repayment'];

    /** Agreements the user may see: all for an all-branches role, otherwise those their branch is party to. */
    private function visible()
    {
        $user = request()->user();
        $q = BranchFundingAgreement::with(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code'])->orderByDesc('id');
        if ($user->role && !$user->role->can_view_all_branches) {
            $q->where(fn ($w) => $w->where('lender_branch_id', $user->branch_id)->orWhere('borrower_branch_id', $user->branch_id));
        }

        return $q;
    }

    private function find(int $id): BranchFundingAgreement
    {
        return $this->visible()->findOrFail($id);
    }

    public function index(Request $request)
    {
        $q = $this->visible();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q->get();
    }

    public function show(int $id)
    {
        $a = $this->find($id);
        $a->load('transactions');

        return $a;
    }

    /** Branches, their cash/bank ledger accounts and borrowing limits, for the request form. */
    public function lookups()
    {
        $branches = Branch::where('status', 'Active')->where('is_head_office', false)->orderBy('name')->get(['id', 'name', 'code', 'funding_borrow_limit']);
        $accounts = [];
        foreach (CashAccount::withoutGlobalScopes()->where('status', 'Active')->whereNotNull('branch_id')->get() as $till) {
            $accounts[(int) $till->branch_id][] = ['chart_account_id' => ChartOfAccount::forCashTill($till)->id, 'name' => "Cash - {$till->name}"];
        }
        foreach (BankAccount::withoutGlobalScopes()->where('status', 'Active')->whereNotNull('branch_id')->get() as $bank) {
            $accounts[(int) $bank->branch_id][] = ['chart_account_id' => ChartOfAccount::forBankAccount($bank)->id, 'name' => "Bank - {$bank->name}"];
        }

        return [
            'branches' => $branches->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'code' => $b->code,
                'funding_borrow_limit' => $b->funding_borrow_limit,
                'owed' => BranchFundingService::owedBy($b->id),
                'accounts' => $accounts[$b->id] ?? [],
            ])->values(),
            'accrual_modes' => self::MODES,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lender_branch_id' => 'required|integer|exists:branches,id',
            'borrower_branch_id' => 'required|integer|exists:branches,id|different:lender_branch_id',
            'lender_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'borrower_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'principal_amount' => 'required|numeric|gt:0',
            'interest_rate_percent' => 'required|numeric|min:0|max:100',
            'accrual_mode' => 'required|in:' . implode(',', self::MODES),
            'request_date' => 'required|date',
            'purpose' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        if ($user->role && !$user->role->can_view_all_branches && (int) $user->branch_id !== (int) $data['borrower_branch_id']) {
            throw ValidationException::withMessages(['borrower_branch_id' => 'You can only request funding for your own branch.']);
        }
        foreach (['lender_branch_id', 'borrower_branch_id'] as $field) {
            if (Branch::whereKey($data[$field])->where('is_head_office', true)->exists()) {
                throw ValidationException::withMessages([$field => 'Head Office is the clearing centre; branch funding is between two other branches. Use a Head Office loan disbursement for Head Office money.']);
            }
        }
        $this->assertAccountBelongs((int) $data['lender_account_id'], (int) $data['lender_branch_id'], 'lender_account_id');
        $this->assertAccountBelongs((int) $data['borrower_account_id'], (int) $data['borrower_branch_id'], 'borrower_account_id');

        return DB::transaction(function () use ($data, $user) {
            $a = BranchFundingAgreement::create($data + [
                'code' => NumberGenerator::next('branch_funding'),
                'status' => 'Requested', 'outstanding_principal' => 0, 'requested_by' => $user->id,
            ]);

            return $a->load(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code']);
        });
    }

    /** Admin-only edit of an agreement that is still awaiting approval (nothing posted yet). */
    public function adminUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'lender_account_id' => 'sometimes|integer|exists:chart_of_accounts,id',
            'borrower_account_id' => 'sometimes|integer|exists:chart_of_accounts,id',
            'principal_amount' => 'required|numeric|gt:0',
            'interest_rate_percent' => 'required|numeric|min:0|max:100',
            'accrual_mode' => 'required|in:' . implode(',', self::MODES),
            'request_date' => 'required|date',
            'purpose' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $a = BranchFundingAgreement::lockForUpdate()->findOrFail($id);
            if ($a->status !== 'Requested') {
                throw ValidationException::withMessages(['status' => "Agreement {$a->code} is {$a->status}; only a requested agreement can be edited."]);
            }
            if (isset($data['lender_account_id'])) {
                $this->assertAccountBelongs((int) $data['lender_account_id'], (int) $a->lender_branch_id, 'lender_account_id');
            }
            if (isset($data['borrower_account_id'])) {
                $this->assertAccountBelongs((int) $data['borrower_account_id'], (int) $a->borrower_branch_id, 'borrower_account_id');
            }
            $a->update($data);

            return $a->load(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code']);
        });
    }

    /** Head Office approves: checks the lender's cash and the borrower's limit, then pays the funding out. */
    public function approve(Request $request, int $id)
    {
        $data = $request->validate(['disbursed_on' => 'nullable|date']);

        return DB::transaction(function () use ($id, $data, $request) {
            $a = BranchFundingAgreement::lockForUpdate()->findOrFail($id);
            $this->visible()->findOrFail($id);
            if ($a->status !== 'Requested') {
                throw ValidationException::withMessages(['status' => "Agreement {$a->code} is {$a->status}, not awaiting approval."]);
            }
            $amount = number_format((float) $a->principal_amount, 2, '.', '');

            $cash = BranchFundingService::accountBalance((int) $a->lender_account_id);
            if (bccomp($cash, $amount, 2) < 0) {
                throw ValidationException::withMessages(['principal_amount' => "The lender account holds only {$cash}, which is less than the requested {$amount}."]);
            }
            $limit = Branch::whereKey($a->borrower_branch_id)->value('funding_borrow_limit');
            if ($limit !== null) {
                $after = bcadd(BranchFundingService::owedBy((int) $a->borrower_branch_id, $a->id), $amount, 2);
                if (bccomp($after, (string) $limit, 2) > 0) {
                    throw ValidationException::withMessages(['principal_amount' => "This would take the borrower to {$after} owed, over its funding limit of {$limit}."]);
                }
            }

            $a->update(['approved_by' => $request->user()->id]);
            BranchFundingService::disburse($a, $data['disbursed_on'] ?? now()->toDateString(), $request->user()->id);

            return $a->fresh(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code', 'transactions']);
        });
    }

    public function reject(Request $request, int $id)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);
        $a = $this->find($id);
        if ($a->status !== 'Requested') {
            throw ValidationException::withMessages(['status' => "Agreement {$a->code} is {$a->status}, not awaiting approval."]);
        }
        $a->update(['status' => 'Rejected', 'approved_by' => $request->user()->id, 'notes' => trim(($a->notes ? $a->notes . "\n" : '') . ($request->input('reason') ? 'Rejected: ' . $request->input('reason') : ''))]);

        return $a;
    }

    /** Books interest up to a date (month end for "monthly" agreements; works for any Active agreement). */
    public function accrue(Request $request, int $id)
    {
        $data = $request->validate(['through' => 'nullable|date']);

        return DB::transaction(function () use ($id, $data, $request) {
            $a = BranchFundingAgreement::lockForUpdate()->findOrFail($id);
            $this->visible()->findOrFail($id);
            BranchFundingService::accrue($a, $data['through'] ?? now()->toDateString(), $request->user()->id);

            return $a->fresh(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code', 'transactions']);
        });
    }

    public function repay(Request $request, int $id)
    {
        $data = $request->validate(['amount' => 'required|numeric|gt:0', 'payment_date' => 'nullable|date']);

        return DB::transaction(function () use ($id, $data, $request) {
            $a = BranchFundingAgreement::lockForUpdate()->findOrFail($id);
            $this->visible()->findOrFail($id);
            if ($a->status !== 'Active') {
                throw ValidationException::withMessages(['status' => "Agreement {$a->code} is {$a->status}; only an active one can be repaid."]);
            }
            $amount = number_format((float) $data['amount'], 2, '.', '');
            $balance = BranchFundingService::accountBalance((int) $a->borrower_account_id);
            if (bccomp($balance, $amount, 2) < 0) {
                throw ValidationException::withMessages(['amount' => "The borrower account holds only {$balance}, which is less than {$amount}."]);
            }
            BranchFundingService::repay($a, $amount, $data['payment_date'] ?? now()->toDateString(), $request->user()->id);

            return $a->fresh(['lenderBranch:id,name,code', 'borrowerBranch:id,name,code', 'transactions']);
        });
    }

    private function assertAccountBelongs(int $chartAccountId, int $branchId, string $field): void
    {
        $ok = CashAccount::withoutGlobalScopes()->where('chart_of_account_id', $chartAccountId)->where('branch_id', $branchId)->exists()
            || BankAccount::withoutGlobalScopes()->where('chart_of_account_id', $chartAccountId)->where('branch_id', $branchId)->exists();
        if (!$ok) {
            throw ValidationException::withMessages([$field => 'That account does not belong to the branch.']);
        }
    }
}
