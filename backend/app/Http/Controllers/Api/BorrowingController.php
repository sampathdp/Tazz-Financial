<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Borrowing;
use App\Models\BorrowingTransaction;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\Pawning;
use App\Services\NumberGenerator;
use App\Support\BorrowingInterestAccrual;
use App\Support\BorrowingLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BorrowingController extends Controller
{
    private const TYPES = ['Party', 'Overdraft', 'RePledgedPawning', 'Other'];

    private const METHODS = ['Cash', 'Bank', 'Cheque'];

    private const TXN_KINDS = ['Drawdown', 'InterestPayment', 'PrincipalRepayment', 'Settlement'];

    private const RELATIONS = [
        'branch:id,name,code',
        'bankAccount:id,name,bank_id,account_number,current_balance,overdraft_limit',
        'bankAccount.bank:id,name',
        'receiptCashAccount:id,name',
        'receiptBankAccount:id,name',
        'creator:id,name',
    ];

    private const DETAIL_RELATIONS = [
        'transactions.creator:id,name',
        'repledgedItems.pawning:id,code,principal_amount,status',
        'renewals.creator:id,name',
    ];

    public function index(Request $request)
    {
        $q = Borrowing::with(self::RELATIONS)->orderByDesc('id');
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q->get()->each(fn (Borrowing $b) => $this->applyLiveOverdraft($b));
    }

    public function show(Borrowing $borrowing)
    {
        $borrowing->load(array_merge(self::RELATIONS, self::DETAIL_RELATIONS));
        $this->applyLiveOverdraft($borrowing);

        return $borrowing;
    }

    /**
     * Form dropdown data — cash accounts, bank accounts and in-house pawning
     * tickets — served under the Borrowings permission so the Record Borrowing
     * form works without also granting Cashbook / Bank Accounts / Pawnings view
     * rights.
     */
    public function lookups()
    {
        return response()->json([
            'cash_accounts' => CashAccount::where('status', 'Active')
                ->orderBy('name')->get(['id', 'name', 'branch_id', 'status']),
            'bank_accounts' => BankAccount::where('status', 'Active')
                ->orderBy('name')->get(['id', 'name', 'account_number', 'branch_id', 'account_type', 'overdraft_limit', 'current_balance', 'status']),
            'pawnings' => Pawning::where('status', 'Active')
                ->orderByDesc('id')->get(['id', 'code', 'principal_amount', 'status']),
        ]);
    }

    public function nextNumber(Request $request)
    {
        $data = $request->validate(['branch_id' => 'required|exists:branches,id']);
        $branch = Branch::find($data['branch_id']);

        return response()->json(['code' => NumberGenerator::peek('borrowing', [
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
        ])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'type' => ['required', Rule::in(self::TYPES)],
            'lender_name' => 'required|string|max:160',
            'source' => 'nullable|string|max:255',
            'purpose' => 'nullable|string',
            'principal_amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'interest_rate_percent' => 'nullable|numeric|min:0|max:999',
            'term_months' => 'nullable|integer|min:1|max:600',
            'borrowing_date' => 'required|date',
            'maturity_date' => 'nullable|date|after_or_equal:borrowing_date',
            'on_maturity' => ['nullable', Rule::in(['Renew', 'Settle'])],
            'reference_no' => 'nullable|string|max:64',
            'contract_no' => 'nullable|string|max:80',
            'notes' => 'nullable|string',

            'receipt_method' => ['nullable', 'required_unless:type,Overdraft', Rule::in(self::METHODS)],
            'receipt_cash_account_id' => 'nullable|exists:cash_accounts,id|required_if:receipt_method,Cash',
            'receipt_bank_account_id' => 'nullable|exists:bank_accounts,id|required_if:receipt_method,Bank',

            'bank_account_id' => 'nullable|exists:bank_accounts,id|required_if:type,Overdraft',
            'set_overdraft_limit' => 'nullable|boolean',

            'repledged_items' => 'array|required_if:type,RePledgedPawning',
            'repledged_items.*.pawning_id' => 'required|integer|exists:credit_facilities,id',
            'repledged_items.*.appraised_value_snapshot' => 'nullable|numeric|min:0',
            'repledged_items.*.financier_name' => 'nullable|string|max:160',
            'repledged_items.*.external_reference' => 'nullable|string|max:120',
        ]);

        $isOverdraft = $data['type'] === 'Overdraft';

        $borrowing = DB::transaction(function () use ($data, $request, $isOverdraft) {
            $branch = Branch::find($data['branch_id']);
            $number = NumberGenerator::next('borrowing', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'user_id' => $request->user()->id,
            ]);

            $borrowing = Borrowing::create([
                'borrowing_number' => $number,
                'branch_id' => $data['branch_id'],
                'type' => $data['type'],
                'lender_name' => $data['lender_name'],
                'source' => $data['source'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'principal_amount' => $data['principal_amount'],
                'outstanding_amount' => $isOverdraft ? 0 : $data['principal_amount'],
                'interest_rate_percent' => $data['interest_rate_percent'] ?? null,
                'term_months' => $data['term_months'] ?? null,
                'borrowing_date' => $data['borrowing_date'],
                'maturity_date' => $data['maturity_date']
                    ?? (isset($data['term_months']) ? \Carbon\Carbon::parse($data['borrowing_date'])->addMonthsNoOverflow((int) $data['term_months'])->toDateString() : null),
                'on_maturity' => $data['on_maturity'] ?? null,
                // Interest accrues monthly from the day the money is received (not for an overdraft: the bank charges that).
                'interest_accrued_through' => $isOverdraft ? null : $data['borrowing_date'],
                'contract_no' => $data['contract_no'] ?? null,
                'receipt_method' => $isOverdraft ? null : $data['receipt_method'],
                'receipt_cash_account_id' => $isOverdraft ? null : ($data['receipt_cash_account_id'] ?? null),
                'receipt_bank_account_id' => $isOverdraft ? null : ($data['receipt_bank_account_id'] ?? null),
                'bank_account_id' => $isOverdraft ? $data['bank_account_id'] : null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'Active',
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($number, $borrowing);

            if ($isOverdraft && ($data['set_overdraft_limit'] ?? false)) {
                BankAccount::whereKey($data['bank_account_id'])->update(['overdraft_limit' => $data['principal_amount']]);
            }

            if ($data['type'] === 'RePledgedPawning') {
                foreach ($data['repledged_items'] as $item) {
                    $borrowing->repledgedItems()->create($item);
                }
            }

            // Overdraft registers the facility only — the draw-downs are ordinary
            // bank withdrawals posted by the Bank module. Everything else brings
            // cash in now, posted like an FD deposit (Cash method only).
            if (!$isOverdraft) {
                BorrowingLedgerPoster::postDrawdown($borrowing);
            }

            return $borrowing;
        });

        return response()->json($this->fresh($borrowing), 201);
    }

    /**
     * Rolls a matured borrowing over for another term — updates its
     * maturity_date / term / rate in place and logs the prior values to
     * borrowing_renewals. Interest is booked up to the roll-over date first
     * (at the old rate). If interest is paid at roll-over, say how
     * (payment_method + account) and it is recorded as a real InterestPayment
     * — clearing accrued interest (2220) before any new expense; with none
     * given nothing is paid and the accrued interest simply stays payable.
     */
    public function renew(Request $request, Borrowing $borrowing)
    {
        if ($borrowing->status !== 'Active') {
            return response()->json(['message' => 'Only an active borrowing can be renewed.'], 422);
        }
        if ($borrowing->type === 'Overdraft') {
            return response()->json(['message' => 'An overdraft facility is managed on the linked bank account, not renewed here.'], 422);
        }

        $paying = (float) $request->input('interest_paid_amount', 0) > 0;
        $data = $request->validate([
            'renewed_on' => 'required|date',
            'new_maturity_date' => 'required|date|after:renewed_on',
            'new_term_months' => 'nullable|integer|min:1|max:600',
            'new_interest_rate_percent' => 'nullable|numeric|min:0|max:999',
            'interest_paid_amount' => ['nullable', 'numeric', 'min:0', new \App\Rules\Money],
            'payment_method' => [Rule::requiredIf($paying), 'nullable', Rule::in(self::METHODS)],
            'cash_account_id' => ['nullable', 'exists:cash_accounts,id', Rule::requiredIf($paying && $request->input('payment_method') === 'Cash')],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', Rule::requiredIf($paying && in_array($request->input('payment_method'), ['Bank', 'Cheque'], true))],
            'notes' => 'nullable|string',
        ]);

        DB::transaction(fn () => $this->performRenewal($borrowing, $data, (int) $request->user()->id));

        return response()->json($this->fresh($borrowing->refresh()));
    }

    /** The roll-over itself — shared with the borrowings:auto-renew command. Call inside a DB transaction. */
    public function performRenewal(Borrowing $borrowing, array $data, int $userId): void
    {
        $borrowing->setRawAttributes(Borrowing::lockForUpdate()->findOrFail($borrowing->id)->getAttributes(), true);
        // Book interest at the old rate up to the roll-over date before the rate can change.
        BorrowingInterestAccrual::accrue($borrowing, $data['renewed_on'], $userId);

        $paid = (string) ($data['interest_paid_amount'] ?? 0);
        if (bccomp($paid, '0', 2) > 0) {
            $this->recordTransaction($borrowing, [
                'kind' => 'InterestPayment', 'amount' => $paid, 'txn_date' => $data['renewed_on'],
                'payment_method' => $data['payment_method'], 'cash_account_id' => $data['cash_account_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null, 'notes' => 'Interest paid at renewal',
            ], $userId);
        }

        $borrowing->renewals()->create([
            'renewed_on' => $data['renewed_on'],
            'previous_maturity_date' => $borrowing->maturity_date,
            'new_maturity_date' => $data['new_maturity_date'],
            'previous_term_months' => $borrowing->term_months,
            'new_term_months' => $data['new_term_months'] ?? $borrowing->term_months,
            'previous_interest_rate_percent' => $borrowing->interest_rate_percent,
            'new_interest_rate_percent' => $data['new_interest_rate_percent'] ?? $borrowing->interest_rate_percent,
            'interest_paid_amount' => $paid,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);

        $borrowing->update([
            'maturity_date' => $data['new_maturity_date'],
            'term_months' => $data['new_term_months'] ?? $borrowing->term_months,
            'interest_rate_percent' => $data['new_interest_rate_percent'] ?? $borrowing->interest_rate_percent,
        ]);
    }

    public function addTransaction(Request $request, Borrowing $borrowing)
    {
        if ($borrowing->status === 'Void') {
            return response()->json(['message' => 'This borrowing has been voided.'], 422);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::TXN_KINDS)],
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'txn_date' => 'required|date',
            'payment_method' => ['required', Rule::in(self::METHODS)],
            'cash_account_id' => 'nullable|exists:cash_accounts,id|required_if:payment_method,Cash',
            // Bank always names the account; a Cheque paid out must say which account it is drawn on
            // (a Cheque received may stay unbanked and park in 1030 Undeposited Funds).
            'bank_account_id' => [
                'nullable', 'exists:bank_accounts,id',
                Rule::requiredIf(fn () => $request->input('payment_method') === 'Bank'
                    || ($request->input('payment_method') === 'Cheque' && $request->input('kind') !== 'Drawdown')),
            ],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
        ]);

        // On an Overdraft facility the only cash event tracked here is interest
        // paid to the bank — principal draw-downs and repayments move the linked
        // current account directly, through the Bank module.
        if ($borrowing->type === 'Overdraft' && $data['kind'] !== 'InterestPayment') {
            throw ValidationException::withMessages(['kind' => 'For an overdraft, only interest payments are recorded here — draw-downs and repayments are bank withdrawals/deposits on the linked account.']);
        }

        DB::transaction(fn () => $this->recordTransaction($borrowing, $data, (int) $request->user()->id));

        return response()->json($this->fresh($borrowing->refresh()), 201);
    }

    /** Books one transaction against a borrowing: accrue first, clear 2220, move the balance, post the ledger. Call inside a DB transaction. */
    private function recordTransaction(Borrowing $borrowing, array $data, int $userId): BorrowingTransaction
    {
        // Serialise with the month-end accrual run, then book interest up to this date at the balance
        // that was really owed, before the balance changes.
        $borrowing->setRawAttributes(Borrowing::lockForUpdate()->findOrFail($borrowing->id)->getAttributes(), true);
        BorrowingInterestAccrual::accrue($borrowing, $data['txn_date'], $userId);

        // An interest payment clears accrued interest (2220) first; only the excess is new expense.
        $cleared = '0.00';
        if ($data['kind'] === 'InterestPayment') {
            $unpaid = (string) $borrowing->accrued_interest_unpaid;
            $cleared = bccomp((string) $data['amount'], $unpaid, 2) < 0 ? number_format((float) $data['amount'], 2, '.', '') : $unpaid;
        }
        $outstandingBefore = (string) $borrowing->outstanding_amount;

        $number = NumberGenerator::next('borrowing_txn', [
            'branch_id' => $borrowing->branch_id,
            'branch_code' => $borrowing->branch?->code,
            'user_id' => $userId,
        ]);

        $txn = $borrowing->transactions()->create([
            'voucher_no' => $number,
            'kind' => $data['kind'],
            'amount' => $data['amount'],
            'txn_date' => $data['txn_date'],
            'payment_method' => $data['payment_method'],
            'cash_account_id' => $data['cash_account_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'outstanding_before' => $outstandingBefore,
            'accrued_interest_cleared' => $cleared,
            'created_by' => $userId,
        ]);
        NumberGenerator::attach($number, $txn);

        if (bccomp($cleared, '0', 2) > 0) {
            $borrowing->update(['accrued_interest_unpaid' => bcsub((string) $borrowing->accrued_interest_unpaid, $cleared, 2)]);
        }
        $this->applyTransactionToBalance($borrowing, $data['kind'], (string) $data['amount']);

        BorrowingLedgerPoster::postTransaction($txn);

        return $txn;
    }
    public function voidTransaction(BorrowingTransaction $borrowingTransaction)
    {
        $borrowing = $borrowingTransaction->borrowing;

        $latestId = $borrowing->transactions()->max('id');
        if ($borrowingTransaction->id !== $latestId) {
            return response()->json(['message' => 'Only the most recent transaction on a borrowing can be voided.'], 422);
        }

        DB::transaction(function () use ($borrowingTransaction, $borrowing) {
            BorrowingLedgerPoster::reverseTransaction($borrowingTransaction);

            $borrowing->update([
                'outstanding_amount' => $borrowingTransaction->outstanding_before,
                // Give back the accrued interest this payment had cleared (its 2220 debit was just reversed).
                'accrued_interest_unpaid' => bcadd((string) $borrowing->accrued_interest_unpaid, (string) $borrowingTransaction->accrued_interest_cleared, 2),
                'status' => $borrowing->status === 'Settled' ? 'Active' : $borrowing->status,
            ]);
            $borrowingTransaction->delete();
        });

        return response()->json($this->fresh($borrowing->refresh()));
    }

    public function destroy(Borrowing $borrowing)
    {
        if ($borrowing->transactions()->exists()) {
            return response()->json(['message' => 'Void or remove this borrowing\'s transactions before deleting it.'], 422);
        }

        DB::transaction(function () use ($borrowing) {
            BorrowingLedgerPoster::reverseDrawdown($borrowing);
            BorrowingLedgerPoster::reverseAccruals($borrowing);
            $borrowing->delete();
        });

        return response()->noContent();
    }

    /** Drawdown adds to what's owed; a repayment/settlement reduces it; interest leaves it unchanged. */
    private function applyTransactionToBalance(Borrowing $borrowing, string $kind, string $amount): void
    {
        if ($kind === 'InterestPayment') {
            return;
        }
        if ($kind === 'Drawdown') {
            $borrowing->update(['outstanding_amount' => bcadd((string) $borrowing->outstanding_amount, $amount, 2)]);

            return;
        }
        // PrincipalRepayment, Settlement
        $remaining = bcsub((string) $borrowing->outstanding_amount, $amount, 2);
        if (bccomp($remaining, '0', 2) < 0) {
            $remaining = '0.00';
        }
        $borrowing->update([
            'outstanding_amount' => $remaining,
            'status' => $kind === 'Settlement' || bccomp($remaining, '0', 2) === 0 ? 'Settled' : $borrowing->status,
        ]);
    }

    /** Overdraft utilisation isn't stored — it's the linked current account's negative balance right now. */
    private function applyLiveOverdraft(Borrowing $borrowing): void
    {
        if ($borrowing->type !== 'Overdraft' || !$borrowing->bankAccount) {
            return;
        }
        $balance = (string) $borrowing->bankAccount->current_balance;
        $drawn = bccomp($balance, '0', 2) < 0 ? bcmul($balance, '-1', 2) : '0.00';
        $borrowing->outstanding_amount = $drawn;
        $borrowing->setAttribute('overdraft_drawn', $drawn);
    }

    private function fresh(Borrowing $borrowing): Borrowing
    {
        $borrowing->load(array_merge(self::RELATIONS, self::DETAIL_RELATIONS));
        $this->applyLiveOverdraft($borrowing);

        return $borrowing;
    }
}
