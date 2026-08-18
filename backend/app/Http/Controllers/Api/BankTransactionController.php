<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankTransactionController extends Controller
{
    private const TYPES = ['Deposit', 'Withdrawal', 'TransferIn', 'TransferOut'];

    public function index(Request $request)
    {
        $query = BankTransaction::with(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'creator:id,name'])
            ->orderByDesc('txn_date')->orderByDesc('id');
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', $request->integer('bank_account_id'));
        }

        return $query->get();
    }

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

        $txn = DB::transaction(function () use ($data, $request) {
            $account = BankAccount::lockForUpdate()->findOrFail($data['bank_account_id']);
            $isCredit = in_array($data['transaction_type'], BankTransaction::CREDIT_TYPES, true);
            $newBalance = bcadd((string) $account->current_balance, $isCredit ? $data['amount'] : -$data['amount'], 2);
            $account->update(['current_balance' => $newBalance]);

            [$contraCoaId, $contraLabel] = $this->applyContra($data, $isCredit);

            $bankCoa = ChartOfAccount::forBankAccount($account);
            $entry = LedgerPoster::post([
                ['account_id' => $isCredit ? $bankCoa->id : $contraCoaId, 'debit' => $data['amount'], 'credit' => 0],
                ['account_id' => $isCredit ? $contraCoaId : $bankCoa->id, 'debit' => 0, 'credit' => $data['amount']],
            ], $data['txn_date'], [
                'source_type' => 'BankTransaction',
                'description' => "{$data['transaction_type']} - {$account->name}" . ($contraLabel ? " / {$contraLabel}" : '') . (($data['description'] ?? null) ? " - {$data['description']}" : ''),
                'branch_id' => $account->branch_id,
                'posted_by' => $request->user()->id,
            ]);

            $txn = BankTransaction::create($data + [
                'balance_after' => $newBalance,
                'status' => $data['status'] ?? 'Cleared',
                'created_by' => $request->user()->id,
                'journal_entry_id' => $entry->id,
            ]);
            $entry->update(['source_id' => $txn->id]);

            return $txn;
        });

        return response()->json($txn->load(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'creator:id,name']), 201);
    }

    /**
     * Moves the contra side's own current_balance if it's a real till/bank
     * account (Cash/Bank kind) — a Deposit's money has to come FROM
     * somewhere, and if that "somewhere" is another balance this system
     * tracks, that balance must move too or the two accounts silently drift
     * apart. A Ledger-kind contra (bank charges, interest, an owner
     * injection, ...) has no second balance to touch — just the GL line
     * LedgerPoster::post writes for it.
     *
     * @return array{0:int, 1:?string} the contra account's chart_of_accounts id, and a display label for the journal description
     */
    private function applyContra(array $data, bool $isCredit): array
    {
        // isCredit means the bank account gained money, so the contra side lost
        // it (if it's a real balance) — and vice versa for a debit-type txn.
        $sign = $isCredit ? -1 : 1;

        if ($data['contra_kind'] === 'Cash') {
            $till = CashAccount::lockForUpdate()->findOrFail($data['contra_cash_account_id']);
            $till->update(['current_balance' => bcadd((string) $till->current_balance, (string) ($sign * $data['amount']), 2)]);

            return [ChartOfAccount::forCashTill($till)->id, "Till: {$till->name}"];
        }

        if ($data['contra_kind'] === 'Bank') {
            $other = BankAccount::lockForUpdate()->findOrFail($data['contra_bank_account_id']);
            $other->update(['current_balance' => bcadd((string) $other->current_balance, (string) ($sign * $data['amount']), 2)]);

            return [ChartOfAccount::forBankAccount($other)->id, "Bank: {$other->name}"];
        }

        $account = ChartOfAccount::findOrFail($data['contra_chart_of_account_id']);

        return [$account->id, "{$account->code} {$account->name}"];
    }

    public function destroy(BankTransaction $bankTransaction)
    {
        DB::transaction(function () use ($bankTransaction) {
            $account = BankAccount::lockForUpdate()->findOrFail($bankTransaction->bank_account_id);
            $isCredit = in_array($bankTransaction->transaction_type, BankTransaction::CREDIT_TYPES, true);
            // Reverse the original balance effect before removing the entry.
            $restored = bcadd((string) $account->current_balance, $isCredit ? -$bankTransaction->amount : $bankTransaction->amount, 2);
            $account->update(['current_balance' => $restored]);

            // Reverse the contra side's own balance too, symmetric to applyContra() above.
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
