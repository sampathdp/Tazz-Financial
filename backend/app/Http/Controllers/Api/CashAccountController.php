<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashAccountController extends Controller
{
    public function index()
    {
        return CashAccount::with('branch:id,name,code')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['current_balance'] = $data['opening_balance'] ?? 0;
        $account = CashAccount::create($data);

        return response()->json($account->load('branch:id,name,code'), 201);
    }

    public function update(Request $request, CashAccount $cashAccount)
    {
        $data = $this->validated($request, $cashAccount);
        $cashAccount->update($data);

        return $cashAccount->load('branch:id,name,code');
    }

    public function destroy(CashAccount $cashAccount)
    {
        if ($cashAccount->entries()->exists()) {
            return response()->json(['message' => 'This account has cashbook entries recorded against it and cannot be deleted.'], 422);
        }
        if ($cashAccount->transfersOut()->exists() || $cashAccount->transfersIn()->exists()) {
            return response()->json(['message' => 'This account has transfer records and cannot be deleted.'], 422);
        }
        if ($cashAccount->reconciliations()->exists()) {
            return response()->json(['message' => 'This account has reconciliation records and cannot be deleted.'], 422);
        }
        $cashAccount->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?CashAccount $cashAccount = null): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => ['required', 'string', 'max:128'],
            'opening_balance' => 'nullable|numeric',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
