<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankTransactionController extends Controller
{
    private const TYPES = ['Deposit', 'Withdrawal', 'TransferIn', 'TransferOut'];

    /** transaction_type => the finance_transactions txn_type it books as. */
    private const TXN_TYPE = [
        'Deposit' => 'BANK_DEPOSIT',
        'Withdrawal' => 'BANK_WITHDRAWAL',
        'TransferIn' => 'TRANSFER',
        'TransferOut' => 'TRANSFER',
    ];

    public function index(Request $request)
    {
        $query = BankTransaction::with(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'creator:id,name'])
            ->orderByDesc('txn_date')->orderByDesc('id');
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->query('bank_account_id'));
        }

        return $query->get();
    }

    /**
     * A manual bank movement. Since Phase 9 this is booked through
     * FinancePostingService (the one money writer): it moves the bank
     * account's current_balance — and the contra account's, when the other
     * side is a real till or bank account — posts the balanced GL entry and
     * records the immutable finance_transactions row. The bank_transactions
     * row is then written alongside it, carrying the bank-only fields the
     * ledger doesn't model (reference_no, contra kind, Cleared/Pending), and
     * linked back by finance_transaction_id.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'txn_date' => 'required|date',
            'transaction_type' => ['required', Rule::in(self::TYPES)],
            'reference_no' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'status' => ['nullable', Rule::in(['Cleared', 'Pending'])],
            'contra_kind' => ['required', Rule::in(['Cash', 'Bank', 'Ledger'])],
            'contra_cash_account_id' => 'required_if:contra_kind,Cash|nullable|exists:cash_accounts,id',
            'contra_bank_account_id' => ['required_if:contra_kind,Bank', 'nullable', 'exists:bank_accounts,id', 'different:bank_account_id'],
            'contra_chart_of_account_id' => 'required_if:contra_kind,Ledger|nullable|exists:chart_of_accounts,id',
        ]);

        $account = BankAccount::findOrFail($data['bank_account_id']);
        $isCredit = in_array($data['transaction_type'], BankTransaction::CREDIT_TYPES, true);
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $bankCoa = ChartOfAccount::forBankAccount($account);

        // The primary bank leg moves +amount on a credit-type, -amount otherwise.
        $movements = [['account_type' => 'bank', 'account_id' => (int) $account->id, 'delta' => ($isCredit ? '' : '-') . $amount]];

        // The contra side: a real till/bank moves the opposite way and also
        // gets a movement; a Ledger contra is just the GL line.
        [$contraCoaId, $contraLabel] = $this->contraAccount($data, $movements, $isCredit, $amount);

        $glLines = [
            ['account_id' => $isCredit ? $bankCoa->id : $contraCoaId, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $isCredit ? $contraCoaId : $bankCoa->id, 'debit' => 0, 'credit' => $amount],
        ];

        $description = "{$data['transaction_type']} - {$account->name}"
            . ($contraLabel ? " / {$contraLabel}" : '')
            . (($data['description'] ?? null) ? " - {$data['description']}" : '');

        $status = $data['status'] ?? 'Cleared';

        $txn = DB::transaction(function () use ($data, $account, $amount, $movements, $glLines, $description, $request, $status) {
            // A Pending transaction is recorded but posts nothing — an uncleared
            // deposit / withdrawal must not move current_balance or the GL until
            // it clears (see clear()). Only a Cleared one is posted now.
            if ($status === 'Pending') {
                return BankTransaction::create($data + [
                    'status' => 'Pending',
                    // Nothing has moved yet — snapshot the balance as-is; clear() overwrites it.
                    'balance_after' => $account->current_balance,
                    'created_by' => $request->user()->id,
                ]);
            }

            $ftx = FinancePostingService::execute([
                'txn_type' => self::TXN_TYPE[$data['transaction_type']],
                'amount' => $amount,
                'entry_date' => $data['txn_date'],
                'branch_id' => $account->branch_id,
                'movements' => $movements,
                'gl_lines' => $glLines,
                'description' => $description,
                'gl_description' => $description,
                'source_module' => 'BankTransaction',
                'executed_by' => $request->user()->id,
            ]);

            $bt = BankTransaction::create($data + [
                'journal_entry_id' => $ftx->journal_entry_id,
                'finance_transaction_id' => $ftx->id,
                'balance_after' => $account->fresh()->current_balance,
                'status' => 'Cleared',
                'created_by' => $request->user()->id,
            ]);
            // Keep the ledger entry pointed at the bank_transactions row, as it always was.
            $ftx->journalEntry?->update(['source_type' => 'BankTransaction', 'source_id' => $bt->id]);

            return $bt;
        });

        return response()->json($txn->load(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'creator:id,name']), 201);
    }

    /** Marks a Pending transaction Cleared and posts it — this is the point the
     *  money actually moves and the GL entry is written. */
    public function clear(Request $request, BankTransaction $bankTransaction)
    {
        if ($bankTransaction->status !== 'Pending') {
            return response()->json(['message' => 'Only a pending bank transaction can be cleared.'], 422);
        }
        $data = $request->validate(['txn_date' => 'nullable|date']);

        $account = BankAccount::findOrFail($bankTransaction->bank_account_id);
        $isCredit = in_array($bankTransaction->transaction_type, BankTransaction::CREDIT_TYPES, true);
        $amount = number_format((float) $bankTransaction->amount, 2, '.', '');
        $bankCoa = ChartOfAccount::forBankAccount($account);

        $movements = [['account_type' => 'bank', 'account_id' => (int) $account->id, 'delta' => ($isCredit ? '' : '-') . $amount]];
        [$contraCoaId, $contraLabel] = $this->contraAccount($bankTransaction->only([
            'contra_kind', 'contra_cash_account_id', 'contra_bank_account_id', 'contra_chart_of_account_id',
        ]), $movements, $isCredit, $amount);
        $glLines = [
            ['account_id' => $isCredit ? $bankCoa->id : $contraCoaId, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $isCredit ? $contraCoaId : $bankCoa->id, 'debit' => 0, 'credit' => $amount],
        ];
        $description = "{$bankTransaction->transaction_type} - {$account->name}" . ($contraLabel ? " / {$contraLabel}" : '');

        DB::transaction(function () use ($bankTransaction, $account, $amount, $movements, $glLines, $description, $request, $data) {
            $ftx = FinancePostingService::execute([
                'txn_type' => self::TXN_TYPE[$bankTransaction->transaction_type],
                'amount' => $amount,
                'entry_date' => $data['txn_date'] ?? $bankTransaction->txn_date,
                'branch_id' => $account->branch_id,
                'movements' => $movements,
                'gl_lines' => $glLines,
                'description' => $description,
                'gl_description' => $description,
                'source_module' => 'BankTransaction',
                'executed_by' => $request->user()->id,
            ]);
            $bankTransaction->update([
                'status' => 'Cleared',
                'journal_entry_id' => $ftx->journal_entry_id,
                'finance_transaction_id' => $ftx->id,
                'balance_after' => $account->fresh()->current_balance,
                'txn_date' => $data['txn_date'] ?? $bankTransaction->txn_date,
            ]);
            $ftx->journalEntry?->update(['source_type' => 'BankTransaction', 'source_id' => $bankTransaction->id]);
        });

        return $bankTransaction->fresh()->load(['bankAccount:id,name', 'creator:id,name']);
    }

    /**
     * Resolves the contra account's chart_of_accounts id + a display label,
     * and — for a Cash or Bank contra — appends its balance movement (the
     * opposite direction to the primary bank leg) to $movements.
     *
     * @return array{0:int, 1:?string}
     */
    private function contraAccount(array $data, array &$movements, bool $isCredit, string $amount): array
    {
        // The bank gained money on a credit-type, so the contra lost it (and vice versa).
        $contraDelta = ($isCredit ? '-' : '') . $amount;

        if ($data['contra_kind'] === 'Cash') {
            $till = CashAccount::findOrFail($data['contra_cash_account_id']);
            $movements[] = ['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => $contraDelta];

            return [ChartOfAccount::forCashTill($till)->id, "Till: {$till->name}"];
        }

        if ($data['contra_kind'] === 'Bank') {
            $other = BankAccount::findOrFail($data['contra_bank_account_id']);
            $movements[] = ['account_type' => 'bank', 'account_id' => (int) $other->id, 'delta' => $contraDelta];

            return [ChartOfAccount::forBankAccount($other)->id, "Bank: {$other->name}"];
        }

        $account = ChartOfAccount::findOrFail($data['contra_chart_of_account_id']);

        return [$account->id, "{$account->code} {$account->name}"];
    }

    /**
     * Undoes a manual bank movement. A Phase-9 row is reversed through
     * FinancePostingService (restores every balance it moved, posts the
     * mirrored GL reversal, leaves a REVERSAL finance_transactions row) then
     * the bank_transactions row is removed. A legacy pre-Phase-9 row keeps
     * the old hand-rolled walk-back.
     */
    public function destroy(BankTransaction $bankTransaction)
    {
        // A Pending transaction was never posted — just remove the row.
        if ($bankTransaction->status === 'Pending') {
            $bankTransaction->delete();

            return response()->noContent();
        }

        if ($bankTransaction->finance_transaction_id) {
            DB::transaction(function () use ($bankTransaction) {
                $ftx = FinanceTransaction::find($bankTransaction->finance_transaction_id);
                if ($ftx && $ftx->status !== 'Reversed' && !$ftx->reversal_of_id) {
                    FinancePostingService::reverse($ftx, "Deleted: bank transaction #{$bankTransaction->id}", request()->user()?->id);
                }
                $bankTransaction->delete();
            });

            return response()->noContent();
        }

        DB::transaction(function () use ($bankTransaction) {
            $account = BankAccount::lockForUpdate()->findOrFail($bankTransaction->bank_account_id);
            $isCredit = in_array($bankTransaction->transaction_type, BankTransaction::CREDIT_TYPES, true);
            $restored = bcadd((string) $account->current_balance, $isCredit ? -$bankTransaction->amount : $bankTransaction->amount, 2);
            $account->update(['current_balance' => $restored]);

            $sign = $isCredit ? 1 : -1;
            if ($bankTransaction->contra_kind === 'Cash' && $bankTransaction->contra_cash_account_id) {
                $till = CashAccount::lockForUpdate()->find($bankTransaction->contra_cash_account_id);
                $till?->update(['current_balance' => bcadd((string) $till->current_balance, (string) ($sign * $bankTransaction->amount), 2)]);
            } elseif ($bankTransaction->contra_kind === 'Bank' && $bankTransaction->contra_bank_account_id) {
                $other = BankAccount::lockForUpdate()->find($bankTransaction->contra_bank_account_id);
                $other?->update(['current_balance' => bcadd((string) $other->current_balance, (string) ($sign * $bankTransaction->amount), 2)]);
            }

            if ($bankTransaction->journal_entry_id) {
                $entry = $bankTransaction->journalEntry;
                if ($entry && $entry->status === 'Posted') {
                    LedgerPoster::reverse($entry, "Deleted: bank transaction #{$bankTransaction->id}");
                }
            }

            $bankTransaction->delete();
        });

        return response()->noContent();
    }
}
