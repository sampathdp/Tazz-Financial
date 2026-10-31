<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\ChartOfAccount;
use App\Models\ManualJournalEntry;
use App\Services\NumberGenerator;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * In-app authoring of adjusting / opening-balance / correcting journal
 * entries, with a maker-checker gate (approval_types code
 * 'manual_journal_entry', mirrors ExpenseController). The entry sits in the
 * manual_journal_entries staging pair until approved; on approval its lines
 * are fed through LedgerPoster::post() so the real ledger still has exactly
 * one writer. Nothing here can post an unbalanced entry.
 */
class ManualJournalEntryController extends Controller
{
    public function index(Request $request)
    {
        return \App\Support\CurrentBranch::applyScope(ManualJournalEntry::with(['lines.account:id,code,name', 'requester:id,name', 'branch:id,name', 'journalEntry:id,code']), 'manual_journal_entries.branch_id')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'reason' => 'nullable|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($data['lines'] as $i => $line) {
            $d = number_format((float) $line['debit'], 2, '.', '');
            $c = number_format((float) $line['credit'], 2, '.', '');
            if (bccomp($d, '0.00', 2) > 0 && bccomp($c, '0.00', 2) > 0) {
                throw ValidationException::withMessages(["lines.$i" => 'A line is either a debit or a credit, not both.']);
            }
            if (bccomp($d, '0.00', 2) === 0 && bccomp($c, '0.00', 2) === 0) {
                throw ValidationException::withMessages(["lines.$i" => 'A line needs a debit or a credit amount.']);
            }
            $totalDebit = bcadd($totalDebit, $d, 2);
            $totalCredit = bcadd($totalCredit, $c, 2);
        }
        if (bccomp($totalDebit, '0.00', 2) === 0) {
            throw ValidationException::withMessages(['lines' => 'The entry has no amounts.']);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw ValidationException::withMessages(['lines' => "The entry does not balance: debit {$totalDebit} vs credit {$totalCredit}."]);
        }

        $entry = DB::transaction(function () use ($data, $request) {
            $code = NumberGenerator::next('manual_journal');
            $entry = ManualJournalEntry::create([
                'code' => $code,
                'entry_date' => $data['entry_date'],
                'description' => $data['description'] ?? null,
                'branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
                'status' => 'PendingApproval',
                'requested_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
            ]);
            NumberGenerator::attach($code, $entry);

            foreach ($data['lines'] as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => number_format((float) $line['debit'], 2, '.', ''),
                    'credit' => number_format((float) $line['credit'], 2, '.', ''),
                    'description' => $line['description'] ?? null,
                ]);
            }

            $type = ApprovalType::where('code', 'manual_journal_entry')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $entry->id,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'reason' => $data['reason'] ?? $data['description'] ?? null,
                'required_approvals' => $type->required_approvals,
            ]);

            return $entry;
        });

        return response()->json($entry->load('lines.account:id,code,name'), 201);
    }

    public function approve(Request $request, ManualJournalEntry $manualJournalEntry)
    {
        if ($manualJournalEntry->status !== 'PendingApproval') {
            return response()->json(['message' => 'Only a pending manual journal entry can be approved.'], 422);
        }
        $req = $manualJournalEntry->pendingApprovalRequest;
        if (!$req) {
            return response()->json(['message' => 'No pending approval request found for this entry.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'manual_journal_entry')) {
            return response()->json(['message' => 'You are not an approver for manual journal entries.'], 403);
        }
        if ($req->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own journal entry.'], 422);
        }
        if ($req->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this entry.'], 422);
        }

        DB::transaction(function () use ($manualJournalEntry, $req, $request) {
            // Lock + re-check: two approvers acting together must post the journal once.
            $req->lockPending();
            if (ManualJournalEntry::lockForUpdate()->findOrFail($manualJournalEntry->id)->status !== 'PendingApproval') {
                throw \Illuminate\Validation\ValidationException::withMessages(['entry' => 'Only a pending manual journal entry can be approved.']);
            }
            if (!$req->first_approved_by) {
                $req->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $req->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }
            if ($req->fresh()->approvalsCount() < $req->required_approvals) {
                return;
            }

            $lines = $manualJournalEntry->lines()->get()->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])->all();

            $posted = LedgerPoster::post($lines, $manualJournalEntry->entry_date->toDateString(), [
                'source_type' => 'ManualAdjustment',
                'source_id' => $manualJournalEntry->id,
                'description' => $manualJournalEntry->description ?? "Manual journal entry {$manualJournalEntry->code}",
                'branch_id' => $manualJournalEntry->branch_id,
                'posted_by' => $request->user()->id,
            ]);

            $manualJournalEntry->update(['status' => 'Approved', 'journal_entry_id' => $posted->id]);
            $req->update(['status' => 'Approved']);
        });

        return $manualJournalEntry->fresh()->load('lines.account:id,code,name', 'journalEntry:id,code');
    }

    public function reject(Request $request, ManualJournalEntry $manualJournalEntry)
    {
        if ($manualJournalEntry->status !== 'PendingApproval') {
            return response()->json(['message' => 'Only a pending manual journal entry can be rejected.'], 422);
        }
        $req = $manualJournalEntry->pendingApprovalRequest;
        if (!ApprovalType::isAuthorized($request->user(), 'manual_journal_entry')) {
            return response()->json(['message' => 'You are not an approver for manual journal entries.'], 403);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($manualJournalEntry, $data, $req, $request) {
            // A reject racing an approve must not overwrite an entry that was already posted to the ledger.
            if (ManualJournalEntry::lockForUpdate()->findOrFail($manualJournalEntry->id)->status !== 'PendingApproval') {
                throw \Illuminate\Validation\ValidationException::withMessages(['entry' => 'Only a pending manual journal entry can be rejected.']);
            }
            $manualJournalEntry->update(['status' => 'Rejected', 'reject_reason' => $data['reject_reason'] ?? null]);
            if ($req) {
                $req->update([
                    'status' => 'Rejected',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'reject_reason' => $data['reject_reason'] ?? null,
                ]);
            }
        });

        return $manualJournalEntry->fresh();
    }

    /** Chart-of-accounts options for the line editor — active accounts only, code order. */
    public function accountOptions()
    {
        return ChartOfAccount::where('status', 'Active')->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance']);
    }
}
