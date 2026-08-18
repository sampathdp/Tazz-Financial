<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransfer;
use App\Models\ChartOfAccount;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransferController extends Controller
{
    private const WITH = ['fromAccount:id,name', 'toAccount:id,name', 'requester:id,name', 'approver:id,name'];

    public function index(Request $request)
    {
        $query = CashTransfer::with(self::WITH)->orderByDesc('transfer_date')->orderByDesc('id');
        if ($request->filled('cash_account_id')) {
            $accountId = $request->integer('cash_account_id');
            $query->where(function ($q) use ($accountId) {
                $q->where('from_cash_account_id', $accountId)->orWhere('to_cash_account_id', $accountId);
            });
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_cash_account_id' => 'required|exists:cash_accounts,id|different:to_cash_account_id',
            'to_cash_account_id' => 'required|exists:cash_accounts,id',
            'transfer_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $transfer = CashTransfer::create($data + [
            'status' => 'Pending',
            'requested_by' => $request->user()->id,
        ]);

        return response()->json($transfer->load(self::WITH), 201);
    }

    public function approve(Request $request, CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Pending') {
            return response()->json(['message' => 'Only pending transfers can be approved.'], 422);
        }

        DB::transaction(function () use ($cashTransfer, $request) {
            // Lock in a stable id order to avoid deadlocks against opposite-direction transfers.
            $ids = [$cashTransfer->from_cash_account_id, $cashTransfer->to_cash_account_id];
            sort($ids);
            $accounts = CashAccount::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $from = $accounts[$cashTransfer->from_cash_account_id];
            $to = $accounts[$cashTransfer->to_cash_account_id];

            $from->update(['current_balance' => bcsub((string) $from->current_balance, $cashTransfer->amount, 2)]);
            $to->update(['current_balance' => bcadd((string) $to->current_balance, $cashTransfer->amount, 2)]);

            $cashTransfer->update([
                'status' => 'Completed',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            // Each till is its own GL leaf account (ChartOfAccount::forCashTill) —
            // moving current_balance between two tills without a matching journal
            // entry would silently desync Trial Balance from the physical tills.
            LedgerPoster::post([
                ['account_id' => ChartOfAccount::forCashTill($to)->id, 'debit' => $cashTransfer->amount, 'credit' => 0],
                ['account_id' => ChartOfAccount::forCashTill($from)->id, 'debit' => 0, 'credit' => $cashTransfer->amount],
            ], $cashTransfer->transfer_date->toDateString(), [
                'source_type' => 'CashTransfer',
                'source_id' => $cashTransfer->id,
                'description' => "Cash transfer {$from->name} -> {$to->name}" . ($cashTransfer->description ? " - {$cashTransfer->description}" : ''),
                'branch_id' => $from->branch_id,
                'posted_by' => $request->user()->id,
            ]);
        });

        return $cashTransfer->load(self::WITH);
    }

    public function reject(Request $request, CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Pending') {
            return response()->json(['message' => 'Only pending transfers can be rejected.'], 422);
        }

        $cashTransfer->update([
            'status' => 'Rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return $cashTransfer->load(self::WITH);
    }

    public function destroy(CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Pending') {
            return response()->json(['message' => 'Only pending transfers can be deleted.'], 422);
        }
        $cashTransfer->delete();

        return response()->noContent();
    }
}
