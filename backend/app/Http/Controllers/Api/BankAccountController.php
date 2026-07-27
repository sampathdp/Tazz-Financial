<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\Request;
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
        $account = BankAccount::create($data);

        return response()->json($account->load('branch:id,name,code'), 201);
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        $data = $this->validated($request, $bankAccount);
        $bankAccount->update($data);

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
        $bankAccount->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?BankAccount $bankAccount = null): array
    {
        return $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'name' => 'required|string|max:128',
            'bank_name' => 'required|string|max:128',
            'branch_name' => 'nullable|string|max:128',
            'account_number' => ['required', 'string', 'max:64', Rule::unique('bank_accounts', 'account_number')->ignore($bankAccount?->id)],
            'account_type' => [Rule::in(self::TYPES)],
            'currency' => 'nullable|string|max:8',
            'opening_balance' => 'nullable|numeric',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'notes' => 'nullable|string|max:255',
        ]);
    }
}
