<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Rules\Money;
use Illuminate\Http\Request;

/**
 * 1030 Cheques / Undeposited Funds holds bank-transfer and cheque receipts (loans, speed drafts, pawning, FD,
 * savings) until they are banked. This gives that balance a visible clearing step: the amount is moved into a
 * chosen bank account as an ordinary Bank Deposit with contra = ledger account 1030, so it reuses the one
 * bank-transaction writer (balance, ledger entry, finance transaction, bank statement row).
 */
class UndepositedFundsController extends Controller
{
    public function show()
    {
        ChartOfAccount::flushBalancesCache();
        $account = ChartOfAccount::system('1030');

        return ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => ChartOfAccount::balancesAsOf()->get($account->id)->balance ?? '0.00'];
    }

    /** The movements on 1030 (receipts in, deposits out), newest first, so the user can match them to the bank statement. */
    public function entries()
    {
        $account = ChartOfAccount::system('1030');

        return \Illuminate\Support\Facades\DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('branches as b', 'b.id', '=', 'je.branch_id')
            ->where('jl.account_id', $account->id)
            ->orderByDesc('jl.entry_date')->orderByDesc('jl.id')
            ->limit(500)
            ->get(['jl.id', 'jl.entry_date', 'je.code', 'je.source_type', 'je.description', 'jl.debit', 'jl.credit', 'b.name as branch_name']);
    }

    public function deposit(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'amount' => ['required', 'numeric', 'min:0.01', new Money],
            'txn_date' => 'required|date',
            'reference_no' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:255',
        ]);

        // One transaction with the 1030 account row locked: two simultaneous deposits cannot both pass the cap.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request) {
            ChartOfAccount::whereKey(ChartOfAccount::system('1030')->id)->lockForUpdate()->first();
            $balance = (string) $this->show()['balance'];
            if (bccomp(number_format((float) $data['amount'], 2, '.', ''), $balance, 2) > 0) {
                return response()->json(['message' => 'Only ' . number_format((float) $balance, 2) . ' is waiting in Undeposited Funds; you cannot deposit more than that.'], 422);
            }

            $sub = Request::create('/api/bank-transactions', 'POST', [
                'bank_account_id' => $data['bank_account_id'], 'txn_date' => $data['txn_date'], 'transaction_type' => 'Deposit',
                'amount' => $data['amount'], 'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? 'Banked from Undeposited Funds',
                'status' => 'Cleared', 'contra_kind' => 'Ledger', 'contra_chart_of_account_id' => ChartOfAccount::system('1030')->id,
            ]);
            $sub->setUserResolver(fn () => $request->user());

            return app(BankTransactionController::class)->store($sub);
        });
    }
}
