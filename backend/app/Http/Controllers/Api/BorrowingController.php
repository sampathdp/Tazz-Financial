<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Borrowing;
use App\Models\BorrowingTransaction;
use App\Models\Branch;
use App\Services\NumberGenerator;
use App\Support\BorrowingCashbookPoster;
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
            'principal_amount' => 'required|numeric|min:0.01',
            'interest_rate_percent' => 'nullable|numeric|min:0|max:999',
            'borrowing_date' => 'required|date',
            'reference_no' => 'nullable|string|max:64',
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
                'borrowing_date' => $data['borrowing_date'],
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
                BorrowingCashbookPoster::postDrawdown($borrowing);
            }

            return $borrowing;
        });

        return response()->json($this->fresh($borrowing), 201);
    }

    public function addTransaction(Request $request, Borrowing $borrowing)
    {
        if ($borrowing->status === 'Void') {
            return response()->json(['message' => 'This borrowing has been voided.'], 422);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::TXN_KINDS)],
            'amount' => 'required|numeric|min:0.01',
            'txn_date' => 'required|date',
            'payment_method' => ['required', Rule::in(self::METHODS)],
            'cash_account_id' => 'nullable|exists:cash_accounts,id|required_if:payment_method,Cash',
            'bank_account_id' => 'nullable|exists:bank_accounts,id|required_if:payment_method,Bank',
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
        ]);

        // On an Overdraft facility the only cash event tracked here is interest
        // paid to the bank — principal draw-downs and repayments move the linked
        // current account directly, through the Bank module.
        if ($borrowing->type === 'Overdraft' && $data['kind'] !== 'InterestPayment') {
            throw ValidationException::withMessages(['kind' => 'For an overdraft, only interest payments are recorded here — draw-downs and repayments are bank withdrawals/deposits on the linked account.']);
        }

        $txn = DB::transaction(function () use ($data, $request, $borrowing) {
            $outstandingBefore = (string) $borrowing->outstanding_amount;

            $number = NumberGenerator::next('borrowing_txn', [
                'branch_id' => $borrowing->branch_id,
                'branch_code' => $borrowing->branch?->code,
                'user_id' => $request->user()->id,
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
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($number, $txn);

            $this->applyTransactionToBalance($borrowing, $data['kind'], (string) $data['amount']);

            BorrowingLedgerPoster::postTransaction($txn);
            BorrowingCashbookPoster::postTransaction($txn);

            return $txn;
        });

        return response()->json($this->fresh($borrowing->refresh()), 201);
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
            BorrowingCashbookPoster::reverse('BorrowingTransaction', $borrowingTransaction->id);

            $borrowing->update([
                'outstanding_amount' => $borrowingTransaction->outstanding_before,
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
            BorrowingCashbookPoster::reverse('Borrowing', $borrowing->id);
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
