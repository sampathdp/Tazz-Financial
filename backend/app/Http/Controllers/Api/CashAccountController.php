<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Support\OpeningBalanceLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $account = DB::transaction(function () use ($data) {
            $account = CashAccount::create($data + ['code' => 'NEW-' . \Illuminate\Support\Str::random(10)]); // replaced below once the id is known
            // A unique, readable code, e.g. HB-CASH-17 (the id makes it unique by construction).
            $branchCode = optional(\App\Models\Branch::find($account->branch_id))->code ?? 'BR';
            $account->update(['code' => substr("{$branchCode}-CASH-{$account->id}", 0, 24)]);
            OpeningBalanceLedgerPoster::postForCashAccount($account);

            return $account;
        });

        return response()->json($account->load('branch:id,name,code'), 201);
    }

    public function update(Request $request, CashAccount $cashAccount)
    {
        $data = $this->validated($request, $cashAccount);

        // Branch and opening balance are fixed once the till has any history: changing them would move past
        // cash to another branch, or change the opening figure, without a journal entry.
        if ($cashAccount->entries()->exists() || \App\Models\JournalLine::where('account_id', $cashAccount->chart_of_account_id)->exists()) {
            if (array_key_exists('branch_id', $data) && (int) $data['branch_id'] !== (int) $cashAccount->branch_id) {
                return response()->json(['message' => 'This till already has transactions, so it cannot be moved to another branch.'], 422);
            }
            if (array_key_exists('opening_balance', $data) && $data['opening_balance'] !== null && bccomp((string) $data['opening_balance'], (string) $cashAccount->opening_balance, 2) !== 0) {
                return response()->json(['message' => 'This till already has transactions, so its opening balance cannot be changed. Post an adjustment instead.'], 422);
            }
        }
        $cashAccount->update($data);
        // Keep the ledger account's name and status in step with the till.
        if ($cashAccount->chart_of_account_id) {
            \App\Models\ChartOfAccount::whereKey($cashAccount->chart_of_account_id)->update(['name' => "Cash in Hand - {$cashAccount->name}", 'status' => $cashAccount->status]);
        }

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
            'purpose' => ['nullable', Rule::in(CashAccount::PURPOSES)],
            'opening_balance' => ['nullable', 'numeric', new \App\Rules\Money],
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
