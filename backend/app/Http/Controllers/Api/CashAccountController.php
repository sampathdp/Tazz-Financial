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
        $data['purpose'] = $data['purpose'] ?? CashAccount::PURPOSE_GENERAL;
        if ($error = $this->purposeTaken($data['branch_id'], $data['purpose'], null)) {
            return response()->json(['message' => $error], 422);
        }
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
        $data['purpose'] = $data['purpose'] ?? $cashAccount->purpose ?? CashAccount::PURPOSE_GENERAL;
        $isPrimary = in_array($cashAccount->account_type, CashAccount::PRIMARY_TYPES, true);
        if ($data['purpose'] !== CashAccount::PURPOSE_GENERAL && !$isPrimary) {
            return response()->json(['message' => 'Only a main till can be dedicated to a purpose.'], 422);
        }
        if ($error = $this->purposeTaken($data['branch_id'], $data['purpose'], $cashAccount->id)) {
            return response()->json(['message' => $error], 422);
        }

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
        \Illuminate\Support\Facades\DB::transaction(function () use ($cashAccount, $data) {
            $cashAccount->update($data);
            // Keep the ledger account's name and status in step with the till.
            if ($cashAccount->chart_of_account_id) {
                \App\Models\ChartOfAccount::whereKey($cashAccount->chart_of_account_id)->update(['name' => "Cash in Hand - {$cashAccount->name}", 'status' => $cashAccount->status]);
            }
        });

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

    /** A dedicated purpose has one main till per branch; says which till already holds it. */
    private function purposeTaken(int|string $branchId, string $purpose, ?int $exceptId): ?string
    {
        if ($purpose === CashAccount::PURPOSE_GENERAL) {
            return null;
        }
        $other = CashAccount::withoutGlobalScopes()->where('branch_id', $branchId)->whereIn('account_type', CashAccount::PRIMARY_TYPES)
            ->where('purpose', $purpose)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();

        return $other ? "This branch already has a " . CashAccount::purposeLabel($purpose) . " cash account (\"{$other->name}\"). Each purpose has one till per branch." : null;
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
