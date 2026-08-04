<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
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
        ]);

        $txn = DB::transaction(function () use ($data) {
            $account = BankAccount::lockForUpdate()->findOrFail($data['bank_account_id']);
            $isCredit = in_array($data['transaction_type'], BankTransaction::CREDIT_TYPES, true);
            $newBalance = bcadd((string) $account->current_balance, $isCredit ? $data['amount'] : -$data['amount'], 2);

            $account->update(['current_balance' => $newBalance]);

            return BankTransaction::create($data + [
                'balance_after' => $newBalance,
                'status' => $data['status'] ?? 'Cleared',
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json($txn->load(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'creator:id,name']), 201);
    }

    public function destroy(BankTransaction $bankTransaction)
    {
        DB::transaction(function () use ($bankTransaction) {
            $account = BankAccount::lockForUpdate()->findOrFail($bankTransaction->bank_account_id);
            $isCredit = in_array($bankTransaction->transaction_type, BankTransaction::CREDIT_TYPES, true);
            // Reverse the original balance effect before removing the entry.
            $restored = bcadd((string) $account->current_balance, $isCredit ? -$bankTransaction->amount : $bankTransaction->amount, 2);
            $account->update(['current_balance' => $restored]);
            $bankTransaction->delete();
        });

        return response()->noContent();
    }
}
