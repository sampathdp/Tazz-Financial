<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashbookEntry;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Support\FinancePostingService;
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
            $query->where('cash_account_id', (int) $request->query('cash_account_id'));
        }

        return $query->get();
    }

    /**
     * A manual cashbook adjustment. Since Phase 9 this goes through
     * FinancePostingService (txn_type ADJUSTMENT): it moves the till's
     * current_balance, posts a balanced GL entry against the chosen offset
     * account (the other side of the adjustment — a bank charge, an owner
     * top-up, a rounding write-off, ...) and records an immutable
     * finance_transactions row. The cashbook_entries row is the mirror FPS
     * writes. This closes the old gap where a manual entry moved the till but
     * posted no GL at all.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'entry_date' => 'required|date',
            'direction' => ['required', Rule::in(CashbookEntry::DIRECTIONS)],
            'category' => ['required', Rule::in(CashbookEntry::CATEGORIES)],
            'description' => 'nullable|string|max:255',
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'offset_account_id' => 'required|exists:chart_of_accounts,id',
        ]);

        $account = CashAccount::findOrFail($data['cash_account_id']);
        $tillLeaf = ChartOfAccount::forCashTill($account);
        $isIn = $data['direction'] === 'In';
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $description = $data['description'] ?: "Manual cashbook {$data['direction']} — {$data['category']}";

        $glLines = $isIn
            ? [
                ['account_id' => $tillLeaf->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => (int) $data['offset_account_id'], 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_id' => (int) $data['offset_account_id'], 'debit' => $amount, 'credit' => 0],
                ['account_id' => $tillLeaf->id, 'debit' => 0, 'credit' => $amount],
            ];

        $txn = FinancePostingService::execute([
            'txn_type' => 'ADJUSTMENT',
            'amount' => $amount,
            'entry_date' => $data['entry_date'],
            'branch_id' => $account->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $account->id, 'delta' => ($isIn ? '' : '-') . $amount]],
            'gl_lines' => $glLines,
            'description' => $description,
            'gl_description' => $description,
            'source_module' => 'ManualCashbook',
            'meta' => ['cashbook_category' => $data['category']],
            'executed_by' => $request->user()->id,
        ]);

        $entry = CashbookEntry::where('source_type', 'FinanceTransaction')->where('source_id', $txn->id)
            ->latest('id')->first();

        return response()->json($entry?->load(['cashAccount:id,name,branch_id', 'creator:id,name']), 201);
    }

    public function destroy(CashbookEntry $cashbookEntry)
    {
        // A Phase 9 entry is the mirror of a finance transaction — undo it the
        // immutable way (a REVERSAL row), never a hard delete.
        if ($cashbookEntry->source_type === 'FinanceTransaction' && $cashbookEntry->source_id) {
            $txn = FinanceTransaction::find($cashbookEntry->source_id);
            if ($txn && $txn->status !== 'Reversed' && !$txn->reversal_of_id) {
                FinancePostingService::assertDirectReversalAllowed();
                FinancePostingService::reverse($txn, 'Manual cashbook entry reversed', request()->user()?->id);
            }

            return response()->noContent();
        }

        // Legacy manual entry from before Phase 9 (till moved, no GL) — walk
        // the balance back and remove the row, as this endpoint always did.
        DB::transaction(function () use ($cashbookEntry) {
            $account = CashAccount::lockForUpdate()->findOrFail($cashbookEntry->cash_account_id);
            $isIn = $cashbookEntry->direction === 'In';
            $restored = bcadd((string) $account->current_balance, $isIn ? -$cashbookEntry->amount : $cashbookEntry->amount, 2);
            $account->update(['current_balance' => $restored]);
            $cashbookEntry->delete();
        });

        return response()->noContent();
    }
}
