<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashbookEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashbookEntryController extends Controller
{
    public function index(Request $request)
    {
        $query = CashbookEntry::with(['cashAccount:id,name,branch_id', 'creator:id,name'])
            ->orderByDesc('entry_date')->orderByDesc('id');
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->integer('cash_account_id'));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'entry_date' => 'required|date',
            'type' => ['required', Rule::in(CashbookEntry::TYPES)],
            'category' => ['required', Rule::in(CashbookEntry::CATEGORIES)],
            'description' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $entry = DB::transaction(function () use ($data, $request) {
            $account = CashAccount::lockForUpdate()->findOrFail($data['cash_account_id']);
            $isIn = $data['type'] === 'In';
            $newBalance = bcadd((string) $account->current_balance, $isIn ? $data['amount'] : -$data['amount'], 2);

            $account->update(['current_balance' => $newBalance]);

            return CashbookEntry::create($data + [
                'branch_id' => $account->branch_id,
                'balance_after' => $newBalance,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json($entry->load(['cashAccount:id,name,branch_id', 'creator:id,name']), 201);
    }

    public function destroy(CashbookEntry $cashbookEntry)
    {
        DB::transaction(function () use ($cashbookEntry) {
            $account = CashAccount::lockForUpdate()->findOrFail($cashbookEntry->cash_account_id);
            $isIn = $cashbookEntry->type === 'In';
            // Reverse the original balance effect before removing the entry.
            $restored = bcadd((string) $account->current_balance, $isIn ? -$cashbookEntry->amount : $cashbookEntry->amount, 2);
            $account->update(['current_balance' => $restored]);
            $cashbookEntry->delete();
        });

        return response()->noContent();
    }
}
