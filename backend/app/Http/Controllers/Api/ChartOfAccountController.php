<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    /** Every account with its live balance (see ChartOfAccount::balancesAsOf) — the Chart of Accounts screen's main list. */
    public function index(Request $request)
    {
        $accounts = ChartOfAccount::with('parent:id,code,name')->orderBy('code')->get();
        $balances = ChartOfAccount::balancesAsOf($request->query('as_of'));

        return $accounts->map(function (ChartOfAccount $account) use ($balances) {
            $b = $balances->get($account->id);
            $account->setAttribute('total_debit', $b->total_debit ?? '0.00');
            $account->setAttribute('total_credit', $b->total_credit ?? '0.00');
            $account->setAttribute('balance', $b->balance ?? '0.00');

            return $account;
        })->values();
    }

    /** Custom (non-system) accounts only — seeded control accounts and cash/bank leaves are managed elsewhere. */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['is_system'] = false;
        $account = ChartOfAccount::create($data);

        return response()->json($account, 201);
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        // A system (seeded control/link) account's code, type and normal_balance
        // are load-bearing — LedgerPoster and the report queries look them up by
        // code — so only its display name and active/inactive status can change.
        if ($chartOfAccount->is_system) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:128'],
                'status' => [Rule::in(['Active', 'Inactive'])],
            ]);
            $chartOfAccount->update($data);

            return $chartOfAccount;
        }

        $data = $this->validated($request, $chartOfAccount);
        $chartOfAccount->update($data);

        return $chartOfAccount;
    }

    private function validated(Request $request, ?ChartOfAccount $account = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('chart_of_accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Income', 'Expense'])],
            'normal_balance' => ['required', Rule::in(['Debit', 'Credit'])],
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
