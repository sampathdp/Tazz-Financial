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
        $balances = ChartOfAccount::balancesAsOf($request->query('as_of'), null, \App\Support\CurrentBranch::reportBranchIds());

        // A header (e.g. 1010 Cash in Hand) carries no postings of its own; its roll-up is its own balance plus
        // every descendant's, each oriented to the header's normal side.
        $byParent = $accounts->groupBy('parent_id');
        $rollup = function (ChartOfAccount $account) use (&$rollup, $byParent, $balances): string {
            $total = $balances->get($account->id)->balance ?? '0.00';
            foreach ($byParent->get($account->id, collect()) as $child) {
                $childTotal = $rollup($child);
                $total = bcadd($total, $child->normal_balance === $account->normal_balance ? $childTotal : bcmul($childTotal, '-1', 2), 2);
            }

            return $total;
        };

        return $accounts->map(function (ChartOfAccount $account) use ($balances, $rollup) {
            $b = $balances->get($account->id);
            $account->setAttribute('total_debit', $b->total_debit ?? '0.00');
            $account->setAttribute('total_credit', $b->total_credit ?? '0.00');
            $account->setAttribute('balance', $b->balance ?? '0.00');
            $account->setAttribute('rollup_balance', $rollup($account));

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
        // A system (seeded control/link) account's code, account_type and normal_balance
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

        // Once an account carries postings, its code, type and normal side can no longer change: flipping
        // Expense to Income or Debit to Credit would silently rewrite every past report.
        if ($chartOfAccount->lines()->exists()) {
            $locked = array_filter(['code', 'account_type', 'normal_balance'], fn ($f) => array_key_exists($f, $data) && (string) $data[$f] !== (string) $chartOfAccount->{$f});
            if ($locked) {
                return response()->json(['message' => 'This account already has postings, so its ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $locked)) . ' cannot be changed. Create a new account and move future postings to it instead.'], 422);
            }
        }
        if (($data['status'] ?? null) === 'Inactive' && ($reason = $this->inUseReason($chartOfAccount))) {
            return response()->json(['message' => $reason], 422);
        }
        $chartOfAccount->update($data);

        return $chartOfAccount;
    }

    /** Why an account cannot be deactivated right now, or null: it is still the account an active category, till, bank or petty-cash fund posts to. */
    private function inUseReason(ChartOfAccount $account): ?string
    {
        if (\App\Models\ExpenseCategory::where('chart_of_account_id', $account->id)->exists()) {
            return 'This account is used by an expense category. Point the category at another account first.';
        }
        if (\App\Models\ChargeCategory::where('chart_of_account_id', $account->id)->exists()) {
            return 'This account is used by a charge category. Point the category at another account first.';
        }
        if (\App\Models\CashAccount::where('chart_of_account_id', $account->id)->where('status', 'Active')->exists()
            || \App\Models\BankAccount::where('chart_of_account_id', $account->id)->where('status', 'Active')->exists()) {
            return 'This account belongs to an active till or bank account. Deactivate that account instead.';
        }

        return null;
    }

    private function validated(Request $request, ?ChartOfAccount $account = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('chart_of_accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:128'],
            'account_type' => ['required', Rule::in(['Asset', 'Liability', 'Equity', 'Income', 'Expense'])],
            'normal_balance' => ['required', Rule::in(['Debit', 'Credit'])],
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
