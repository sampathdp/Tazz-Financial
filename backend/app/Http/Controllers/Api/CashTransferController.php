<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\CashTransfer;
use App\Models\ChartOfAccount;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransferController extends Controller
{
    private const WITH = ['fromAccount:id,name', 'toAccount:id,name', 'requester:id,name', 'approver:id,name', 'voider:id,name'];

    public function index(Request $request)
    {
        $query = CashTransfer::with(self::WITH)->orderByDesc('transfer_date')->orderByDesc('id');
        if ($request->filled('cash_account_id')) {
            $accountId = (int) $request->query('cash_account_id');
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

        $transfer = DB::transaction(function () use ($data, $request) {
            $transfer = CashTransfer::create($data + [
                'status' => 'Pending',
                'requested_by' => $request->user()->id,
            ]);
            $type = ApprovalType::where('code', 'cash_transfer')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $transfer->id,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'reason' => $data['description'] ?? null,
                'required_approvals' => $type->required_approvals,
            ]);

            return $transfer;
        });

        return response()->json($transfer->load(self::WITH), 201);
    }

    /** Maker-checker via the shared approval_requests table (code
     *  'cash_transfer') — mirrors LoanController::approveCancelRequest(). The
     *  till-balance move + GL entry only run once enough distinct approvals are
     *  in; the CashTransfer keeps its own status for the UI. */
    public function approve(Request $request, CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Pending') {
            return response()->json(['message' => 'Only pending transfers can be approved.'], 422);
        }
        $req = $cashTransfer->pendingApprovalRequest;
        if (!$req) {
            return response()->json(['message' => 'No pending approval request found for this transfer.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'cash_transfer')) {
            return response()->json(['message' => 'You are not an approver for cash transfers.'], 403);
        }
        if ($req->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own transfer request.'], 422);
        }
        if ($req->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }

        DB::transaction(function () use ($cashTransfer, $req, $request) {
            if (!$req->first_approved_by) {
                $req->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $req->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($req->fresh()->approvalsCount() < $req->required_approvals) {
                return;
            }

            // Lock in a stable id order to avoid deadlocks against opposite-direction transfers.
            $ids = [$cashTransfer->from_cash_account_id, $cashTransfer->to_cash_account_id];
            sort($ids);
            $accounts = CashAccount::whereIn('id', $ids)->with('chartOfAccount')->lockForUpdate()->get()->keyBy('id');
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

            $req->update(['status' => 'Approved']);
        });

        return $cashTransfer->load(self::WITH);
    }

    public function reject(Request $request, CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Pending') {
            return response()->json(['message' => 'Only pending transfers can be rejected.'], 422);
        }
        $req = $cashTransfer->pendingApprovalRequest;
        if ($req && !ApprovalType::isAuthorized($request->user(), 'cash_transfer')) {
            return response()->json(['message' => 'You are not an approver for cash transfers.'], 403);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($cashTransfer, $data, $req, $request) {
            $cashTransfer->update([
                'status' => 'Rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            if ($req) {
                $req->update([
                    'status' => 'Rejected',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'reject_reason' => $data['reject_reason'] ?? null,
                ]);
            }
        });

        return $cashTransfer->load(self::WITH);
    }

    /**
     * Undoes a mistakenly-approved transfer — moves both till balances back
     * and reverses the GL entry. Completed-only (a Pending transfer is
     * deleted outright via destroy(), never approved in the first place).
     */
    public function void(Request $request, CashTransfer $cashTransfer)
    {
        if ($cashTransfer->status !== 'Completed') {
            return response()->json(['message' => 'Only a completed transfer can be voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($cashTransfer, $data, $request) {
            $ids = [$cashTransfer->from_cash_account_id, $cashTransfer->to_cash_account_id];
            sort($ids);
            $accounts = CashAccount::whereIn('id', $ids)->with('chartOfAccount')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts[$cashTransfer->from_cash_account_id];
            $to = $accounts[$cashTransfer->to_cash_account_id];

            $from->update(['current_balance' => bcadd((string) $from->current_balance, $cashTransfer->amount, 2)]);
            $to->update(['current_balance' => bcsub((string) $to->current_balance, $cashTransfer->amount, 2)]);

            $cashTransfer->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);

            $entry = \App\Models\JournalEntry::where('source_type', 'CashTransfer')
                ->where('source_id', $cashTransfer->id)
                ->where('status', 'Posted')
                ->whereNull('reversal_of_id')
                ->first();
            if ($entry) {
                LedgerPoster::reverse($entry, "Voided transfer #{$cashTransfer->id}", $request->user()->id);
            }
        });

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
