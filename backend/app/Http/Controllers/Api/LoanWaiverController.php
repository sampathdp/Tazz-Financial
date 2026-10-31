<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\FacilityWaiver;
use App\Models\Loan;
use App\Support\LedgerPoster;
use App\Support\LoanPaymentAllocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoanWaiverController extends Controller
{
    private const WAIVER_TYPES = ['Interest', 'Penalty', 'Charge', 'Principal'];

    public function index(Loan $loan)
    {
        return $loan->waivers()->with(['approver:id,name', 'creator:id,name'])->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'waiver_type' => ['required', Rule::in(self::WAIVER_TYPES)],
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'reason' => 'nullable|string|max:255',
            'waived_at' => 'nullable|date',
        ]);

        $waiver = DB::transaction(function () use ($data, $loan, $request) {
            // A Principal waiver forgives amounts already carried in 1100 — it is a
            // real loss and must hit the P&L. Interest / Penalty / Charge waivers
            // forgive income this ledger only ever recognises as collected, so
            // there is nothing to reverse for those.
            $postedAmount = null;
            $journalEntryId = null;
            if ($data['waiver_type'] === 'Principal') {
                $relievable = round((float) LoanPaymentAllocator::outstandingByBucket($loan)['capital'], 2);
                $writeOff = min(round((float) $data['amount'], 2), $relievable);
                if ($writeOff > 0) {
                    $entry = LedgerPoster::post([
                        ['account_id' => ChartOfAccount::system('5300')->id, 'debit' => $writeOff, 'credit' => 0],
                        ['account_id' => ChartOfAccount::system('1100')->id, 'debit' => 0, 'credit' => $writeOff],
                    ], ($data['waived_at'] ?? now()), [
                        'source_type' => 'Loan',
                        'source_id' => $loan->id,
                        'description' => "Principal waived - {$loan->code}",
                        'branch_id' => $loan->branch_id,
                        'posted_by' => $request->user()->id,
                    ]);
                    $postedAmount = $writeOff;
                    $journalEntryId = $entry->id;
                }
            }

            return $loan->waivers()->create([
                'waiver_type' => $data['waiver_type'],
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'approved_by' => $request->user()->id,
                'waived_at' => $data['waived_at'] ?? now(),
                'created_by' => $request->user()->id,
                // What was really written off in the ledger (capped at what was relievable) and which entry did it.
                'posted_amount' => $postedAmount,
                'journal_entry_id' => $journalEntryId,
            ]);
        });

        return response()->json($waiver->load(['approver:id,name', 'creator:id,name']), 201);
    }

    public function destroy(Request $request, FacilityWaiver $waiver)
    {
        // A Principal waiver posted a Dr 5300 / Cr 1100 entry - reverse exactly that entry, then keep the waiver as a
        // voided row (history is never deleted). Waivers made before journal_entry_id existed fall back to the
        // old "latest Principal waived entry of this loan" match.
        DB::transaction(function () use ($waiver, $request) {
            $live = FacilityWaiver::lockForUpdate()->find($waiver->id);
            if (!$live) {
                throw \Illuminate\Validation\ValidationException::withMessages(['waiver' => 'This waiver was already removed.']);
            }
            if ($waiver->waiver_type === 'Principal') {
                $loan = Loan::find($waiver->credit_facility_id);
                if ($waiver->journal_entry_id) {
                    $entry = \App\Models\JournalEntry::find($waiver->journal_entry_id);
                    if ($entry && $entry->status === 'Posted') {
                        LedgerPoster::reverse($entry, "Principal waiver removed - {$loan?->code}", $request->user()->id);
                    }
                } elseif ($waiver->posted_amount === null) {
                    \App\Models\JournalEntry::where('source_type', 'Loan')
                        ->where('source_id', $waiver->credit_facility_id)
                        ->where('description', 'like', 'Principal waived%')
                        ->where('status', 'Posted')
                        ->whereNull('reversal_of_id')
                        ->latest('id')
                        ->take(1)
                        ->get()
                        ->each(fn ($entry) => LedgerPoster::reverse($entry, "Principal waiver removed - {$loan?->code}", $request->user()->id));
                }
            }
            $waiver->markVoided($request->user()->id, 'Waiver removed');
        });

        return response()->noContent();
    }
}
