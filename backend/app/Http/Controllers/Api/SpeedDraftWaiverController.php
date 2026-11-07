<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\FacilityWaiver;
use App\Models\JournalEntry;
use App\Models\SpeedDraft;
use App\Support\LedgerPoster;
use App\Support\SpeedDraftPaymentAllocator;
use App\Support\SpeedDraftScheduler;
use App\Support\SpeedDraftWaivers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Waivers on a Speed Draft — same four types as loans. Interest / Penalty / Charge waivers are
 * pools netted off what is owed (see SpeedDraftWaivers); a Principal waiver forgives capital that is
 * already on the books, so it is a real loss (Dr 5300 / Cr 1110) and lowers outstanding capital,
 * which re-prices the remaining interest.
 */
class SpeedDraftWaiverController extends Controller
{
    private const WAIVER_TYPES = ['Interest', 'Penalty', 'Charge', 'Principal'];

    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->waivers()->with(['approver:id,name', 'creator:id,name'])->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate([
            'waiver_type' => ['required', Rule::in(self::WAIVER_TYPES)],
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'reason' => 'nullable|string|max:255',
            'waived_at' => 'nullable|date',
        ]);

        $waiver = DB::transaction(function () use ($data, $speedDraft, $request) {
            $draft = SpeedDraft::whereKey($speedDraft->id)->lockForUpdate()->firstOrFail();
            if (!in_array($draft->status, ['Active', 'Approved'], true) || ($draft->status === 'Approved' && $data['waiver_type'] !== 'Charge')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['waiver' => 'Waivers can only be applied to an active Speed Draft (charge waivers also to an approved one).']);
            }

            $amount = round((float) $data['amount'], 2);
            $owed = match ($data['waiver_type']) {
                'Interest' => SpeedDraftPaymentAllocator::outstandingInterest($draft),
                'Penalty' => SpeedDraftPaymentAllocator::outstandingPenalty($draft),
                'Charge' => SpeedDraftPaymentAllocator::outstandingCharges($draft),
                'Principal' => (float) $draft->outstanding_balance_cache,
            };
            if ($amount > round($owed, 2) + 0.005) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'The waiver (' . number_format($amount, 2) . ') is more than what is currently owed (' . number_format($owed, 2) . ').']);
            }

            $postedAmount = null;
            $journalEntryId = null;
            if ($data['waiver_type'] === 'Principal') {
                $entry = LedgerPoster::post([
                    ['account_id' => ChartOfAccount::system('5300')->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => ChartOfAccount::system('1110')->id, 'debit' => 0, 'credit' => $amount],
                ], ($data['waived_at'] ?? now()), [
                    'source_type' => 'SpeedDraft',
                    'source_id' => $draft->id,
                    'description' => "Principal waived - {$draft->code}",
                    'branch_id' => $draft->branch_id,
                    'posted_by' => $request->user()->id,
                ]);
                $postedAmount = $amount;
                $journalEntryId = $entry->id;
                $draft->update(['outstanding_balance_cache' => round((float) $draft->outstanding_balance_cache - $amount, 2)]);
                SpeedDraftScheduler::recalculateOpenRows($draft->fresh());
            }

            $waiver = $draft->waivers()->create([
                'waiver_type' => $data['waiver_type'],
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'approved_by' => $request->user()->id,
                'waived_at' => $data['waived_at'] ?? now(),
                'created_by' => $request->user()->id,
                'posted_amount' => $postedAmount,
                'journal_entry_id' => $journalEntryId,
            ]);

            $this->settle($draft->fresh());

            return $waiver;
        });

        return response()->json($waiver->load(['approver:id,name', 'creator:id,name']), 201);
    }

    public function destroy(Request $request, FacilityWaiver $waiver)
    {
        DB::transaction(function () use ($waiver, $request) {
            $live = FacilityWaiver::lockForUpdate()->find($waiver->id);
            if (!$live) {
                throw \Illuminate\Validation\ValidationException::withMessages(['waiver' => 'This waiver was already removed.']);
            }
            $draft = SpeedDraft::whereKey($waiver->credit_facility_id)->lockForUpdate()->firstOrFail();

            if ($waiver->waiver_type === 'Principal') {
                if ($waiver->journal_entry_id) {
                    $entry = JournalEntry::find($waiver->journal_entry_id);
                    if ($entry && $entry->status === 'Posted') {
                        LedgerPoster::reverse($entry, "Principal waiver removed - {$draft->code}", $request->user()->id);
                    }
                }
                $draft->update(['outstanding_balance_cache' => round((float) $draft->outstanding_balance_cache + (float) ($waiver->posted_amount ?? $waiver->amount), 2)]);
                SpeedDraftScheduler::recalculateOpenRows($draft->fresh());
            }
            $waiver->markVoided($request->user()->id, 'Waiver removed');

            $this->settle($draft->fresh());
        });

        return response()->noContent();
    }

    /** After any waiver change: re-derive installment statuses, and close/reopen the draft to match what is left owed. */
    private function settle(SpeedDraft $draft): void
    {
        SpeedDraftWaivers::syncRowStatuses($draft);
        $draft = $draft->fresh();
        $owed = SpeedDraftPaymentAllocator::outstandingBalance($draft);
        if ($draft->status === 'Active' && $owed <= 0 && (float) $draft->outstanding_balance_cache <= 0) {
            $draft->update(['status' => 'Closed', 'closed_at' => now()]);
        } elseif ($draft->status === 'Closed' && $owed > 0) {
            $draft->update(['status' => 'Active', 'closed_at' => null]);
        }
    }
}
