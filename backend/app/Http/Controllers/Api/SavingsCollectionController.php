<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SavingsCollectionLine;
use App\Models\SavingsCollectionSheet;
use App\Services\NumberGenerator;
use App\Support\CurrentBranch;
use App\Support\SavingsAccountService;
use App\Support\SavingsLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Field-collection sheets for Daily Savings. A sheet is built up (status Open)
 * then Posted in one action — every line becomes a Cash-method Deposit on its
 * account, and the sheet's cash lands in the branch till.
 */
class SavingsCollectionController extends Controller
{
    private const RELATIONS = ['branch:id,name,code', 'collector:id,name', 'creator:id,name', 'lines.savingsAccount:id,account_number,customer_id,current_balance', 'lines.savingsAccount.customer:id,full_name,code'];

    public function index(Request $request)
    {
        $q = CurrentBranch::applyScope(SavingsCollectionSheet::with(['branch:id,name,code', 'collector:id,name'])->withCount('lines'))->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($date = $request->query('date')) {
            $q->whereDate('collection_date', $date);
        }

        return $q->get();
    }

    public function show(SavingsCollectionSheet $savingsCollectionSheet)
    {
        return $savingsCollectionSheet->load(self::RELATIONS);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'collector_id' => 'required|exists:users,id',
            'collection_date' => 'required|date',
            'notes' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.savings_account_id' => 'required|exists:savings_accounts,id',
            'lines.*.amount' => 'required|numeric|min:0.01',
            'lines.*.reference_no' => 'nullable|string|max:64',
        ]);

        $sheet = DB::transaction(function () use ($data, $request) {
            $branch = Branch::find($data['branch_id']);
            $number = NumberGenerator::next('savings_collection', [
                'branch_id' => $branch->id, 'branch_code' => $branch->code, 'user_id' => $request->user()->id,
            ]);
            $sheet = SavingsCollectionSheet::create([
                'sheet_number' => $number,
                'branch_id' => $data['branch_id'],
                'collector_id' => $data['collector_id'],
                'collection_date' => $data['collection_date'],
                'status' => 'Open',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($number, $sheet);
            $this->replaceLines($sheet, $data['lines'] ?? []);

            return $sheet;
        });

        return response()->json($sheet->load(self::RELATIONS), 201);
    }

    public function update(Request $request, SavingsCollectionSheet $savingsCollectionSheet)
    {
        if ($savingsCollectionSheet->status !== 'Open') {
            return response()->json(['message' => 'Only an Open sheet can be edited.'], 422);
        }
        $data = $request->validate([
            'collector_id' => 'nullable|exists:users,id',
            'collection_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array',
            'lines.*.savings_account_id' => 'required|exists:savings_accounts,id',
            'lines.*.amount' => 'required|numeric|min:0.01',
            'lines.*.reference_no' => 'nullable|string|max:64',
        ]);

        DB::transaction(function () use ($savingsCollectionSheet, $data) {
            $savingsCollectionSheet->update([
                'collector_id' => $data['collector_id'] ?? $savingsCollectionSheet->collector_id,
                'collection_date' => $data['collection_date'] ?? $savingsCollectionSheet->collection_date,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->replaceLines($savingsCollectionSheet, $data['lines']);
        });

        return $savingsCollectionSheet->fresh()->load(self::RELATIONS);
    }

    /** Turns every line into a Cash deposit on its account and lands the cash in the till. */
    public function post(Request $request, SavingsCollectionSheet $savingsCollectionSheet)
    {
        if ($savingsCollectionSheet->status !== 'Open') {
            return response()->json(['message' => 'This sheet has already been posted or cancelled.'], 422);
        }
        $savingsCollectionSheet->load('lines.savingsAccount.savingsProduct', 'lines.savingsAccount.branch');
        if ($savingsCollectionSheet->lines->isEmpty()) {
            return response()->json(['message' => 'Add at least one collection line before posting.'], 422);
        }
        foreach ($savingsCollectionSheet->lines as $line) {
            $acct = $line->savingsAccount;
            if (in_array($acct->status, ['Frozen', 'Closed'], true)) {
                throw ValidationException::withMessages(['lines' => "Account {$acct->account_number} is {$acct->status} and cannot take a deposit — remove that line."]);
            }
        }

        DB::transaction(function () use ($savingsCollectionSheet, $request) {
            if (SavingsCollectionSheet::lockForUpdate()->findOrFail($savingsCollectionSheet->id)->status !== 'Open') {
                throw ValidationException::withMessages(['sheet' => 'This sheet has already been posted or cancelled.']);
            }
            foreach ($savingsCollectionSheet->lines as $line) {
                if (!$line->collected) {
                    continue;
                }
                $acct = $line->savingsAccount;
                $txn = SavingsAccountService::record($acct, [
                    'type' => 'Deposit', 'direction' => 'In', 'amount' => $line->amount,
                    'txn_date' => $savingsCollectionSheet->collection_date->format('Y-m-d'),
                    'payment_method' => 'Cash', 'reference_no' => $line->reference_no,
                    'collection_sheet_id' => $savingsCollectionSheet->id,
                    'notes' => "Field collection {$savingsCollectionSheet->sheet_number}",
                    'created_by' => $request->user()->id,
                ]);
                $txn->setRelation('savingsAccount', $acct);
                SavingsLedgerPoster::postDeposit($txn);
                $line->update(['savings_transaction_id' => $txn->id]);
            }
            $collected = $savingsCollectionSheet->lines->where('collected', true);
            $savingsCollectionSheet->update([
                'status' => 'Posted',
                'posted_at' => now(),
                'total_amount' => $collected->sum('amount'),
                'line_count' => $collected->count(),
            ]);
        });

        return $savingsCollectionSheet->fresh()->load(self::RELATIONS);
    }

    public function cancel(SavingsCollectionSheet $savingsCollectionSheet)
    {
        if ($savingsCollectionSheet->status !== 'Open') {
            return response()->json(['message' => 'Only an Open sheet can be cancelled.'], 422);
        }
        // Conditional update: a concurrent post must win over a cancel, never be overwritten by it.
        $changed = SavingsCollectionSheet::whereKey($savingsCollectionSheet->id)->where('status', 'Open')->update(['status' => 'Cancelled']);
        if (!$changed) {
            return response()->json(['message' => 'Only an Open sheet can be cancelled.'], 422);
        }

        return $savingsCollectionSheet->fresh()->load(self::RELATIONS);
    }

    private function replaceLines(SavingsCollectionSheet $sheet, array $lines): void
    {
        $sheet->lines()->delete();
        $seen = [];
        foreach ($lines as $line) {
            $accountId = (int) $line['savings_account_id'];
            if (isset($seen[$accountId])) {
                throw ValidationException::withMessages(['lines' => 'An account can appear only once per sheet.']);
            }
            $seen[$accountId] = true;
            SavingsCollectionLine::create([
                'savings_collection_sheet_id' => $sheet->id,
                'savings_account_id' => $accountId,
                'amount' => round((float) $line['amount'], 2),
                'collected' => $line['collected'] ?? true,
                'reference_no' => $line['reference_no'] ?? null,
            ]);
        }
        $sheet->update([
            'line_count' => count($lines),
            'total_amount' => collect($lines)->sum(fn ($l) => (float) $l['amount']),
        ]);
    }
}
