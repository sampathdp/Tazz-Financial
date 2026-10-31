<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Support\OpeningBalanceLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    private const TYPES = ['Savings', 'Current', 'Fixed Deposit'];

    public function index()
    {
        return BankAccount::with('branch:id,name,code')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['current_balance'] = $data['opening_balance'] ?? 0;

        $account = DB::transaction(function () use ($data) {
            $account = BankAccount::create($data);
            OpeningBalanceLedgerPoster::postForBankAccount($account);

            return $account;
        });

        return response()->json($account->load('branch:id,name,code'), 201);
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        $data = $this->validated($request, $bankAccount);

        if ($bankAccount->transactions()->exists() || \App\Models\JournalLine::where('account_id', $bankAccount->chart_of_account_id)->exists()) {
            if (array_key_exists('branch_id', $data) && (int) ($data['branch_id'] ?? 0) !== (int) ($bankAccount->branch_id ?? 0)) {
                return response()->json(['message' => 'This bank account already has transactions, so it cannot be moved to another branch.'], 422);
            }
            if (array_key_exists('opening_balance', $data) && $data['opening_balance'] !== null && bccomp((string) $data['opening_balance'], (string) $bankAccount->opening_balance, 2) !== 0) {
                return response()->json(['message' => 'This bank account already has transactions, so its opening balance cannot be changed. Post an adjustment instead.'], 422);
            }
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($bankAccount, $data) {
            $bankAccount->update($data);
            if ($bankAccount->chart_of_account_id) {
                \App\Models\ChartOfAccount::whereKey($bankAccount->chart_of_account_id)->update(['name' => "Bank - {$bankAccount->name}", 'status' => $bankAccount->status]);
            }
        });

        return $bankAccount->load('branch:id,name,code');
    }

    public function destroy(BankAccount $bankAccount)
    {
        if ($bankAccount->transactions()->exists()) {
            return response()->json(['message' => 'This account has transactions recorded against it and cannot be deleted.'], 422);
        }
        if ($bankAccount->reconciliations()->exists()) {
            return response()->json(['message' => 'This account has reconciliation records and cannot be deleted.'], 422);
        }
        $leafId = $bankAccount->chart_of_account_id;
        if ($leafId && \App\Models\JournalLine::where('account_id', $leafId)->exists()) {
            return response()->json(['message' => 'This account has ledger postings and cannot be deleted. Mark it Inactive instead.'], 422);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($bankAccount, $leafId) {
            $bankAccount->delete();
            // Its ledger account (1020-n) goes with it, so the chart does not keep a bank account that no longer exists.
            if ($leafId) {
                \App\Models\ChartOfAccount::whereKey($leafId)->where('is_system', false)->delete();
            }
        });

        return response()->noContent();
    }

    private function validated(Request $request, ?BankAccount $bankAccount = null): array
    {
        return $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'name' => 'required|string|max:128',
            'bank_id' => 'required|exists:banks,id',
            'bank_branch_name' => 'nullable|string|max:128',
            'account_number' => ['required', 'string', 'max:34', Rule::unique('bank_accounts', 'account_number')->ignore($bankAccount?->id)],
            'account_type' => [Rule::in(self::TYPES)],
            'currency' => 'nullable|string|size:3',
            'opening_balance' => ['nullable', 'numeric', new \App\Rules\Money],
            // A sanctioned overdraft limit — set it to let this account's
            // balance go negative (down to -overdraft_limit); leave it null and
            // the account can never go below zero. See BankTransactionController.
            'overdraft_limit' => 'nullable|numeric|min:0',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'notes' => 'nullable|string|max:255',
        ]);
    }
}
