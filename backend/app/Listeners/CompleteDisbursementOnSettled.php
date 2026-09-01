<?php

namespace App\Listeners;

use App\Events\FinanceRequestSettled;
use App\Models\Loan;
use App\Models\SpeedDraft;
use App\Support\LoanDisbursementCompleter;
use App\Support\SpeedDraftDisbursementCompleter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a LoanDisbursement finance request is paid (Finance released the
 * money), finish the loan / speed-draft side: generate the schedule, flip it
 * to Active. The money and the GL entry already moved inside
 * FinanceRequestService::execute(); this only does the book-keeping, from the
 * params snapshotted onto the request meta by
 * LoanController::requestDisbursement() / SpeedDraftController::requestDisbursement().
 *
 * Rejected / cancelled requests are a no-op — the facility stays Approved and
 * the officer can raise a fresh request.
 */
class CompleteDisbursementOnSettled
{
    public function handle(FinanceRequestSettled $event): void
    {
        $request = $event->request;
        if ($request->request_type !== 'LoanDisbursement' || $event->outcome !== 'paid') {
            return;
        }

        $meta = $request->meta ?? [];

        try {
            if ($request->source_module === 'Loan') {
                $this->finishLoan((int) $request->source_id, $meta, $request);
            } elseif ($request->source_module === 'SpeedDraft') {
                $this->finishSpeedDraft((int) $request->source_id, $meta, $request);
            }
        } catch (\Throwable $e) {
            // The money already moved successfully — don't fail the request.
            // Leave it at 'Paid' (visibly not 'Completed') so the stuck
            // facility side is obvious and can be finished manually.
            Log::channel('errors')->error('Disbursement completion failed after payment', [
                'finance_request_id' => $request->id,
                'source' => $request->source_module . '#' . $request->source_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function finishLoan(int $loanId, array $meta, $request): void
    {
        $loan = Loan::find($loanId);
        if (!$loan || $loan->status !== 'Approved') {
            return;
        }
        DB::transaction(function () use ($loan, $meta, $request) {
            LoanDisbursementCompleter::complete($loan, [
                'first_installment_date' => $meta['first_installment_date'],
                'disbursed_amount' => $meta['disbursed_amount'] ?? null,
                'disbursed_at' => $meta['disbursed_at'] ?? null,
                'disbursement_method' => $meta['disbursement_method'],
                'disbursement_bank_account_id' => $meta['disbursement_bank_account_id'] ?? null,
            ]);
            $request->update(['status' => 'Completed', 'closed_at' => now()]);
        });
    }

    private function finishSpeedDraft(int $draftId, array $meta, $request): void
    {
        $draft = SpeedDraft::find($draftId);
        if (!$draft || $draft->status !== 'Approved') {
            return;
        }
        DB::transaction(function () use ($draft, $meta, $request) {
            SpeedDraftDisbursementCompleter::complete($draft, [
                'start_date' => $meta['start_date'],
                'disbursed_amount' => $meta['disbursed_amount'] ?? null,
                'disbursement_method' => $meta['disbursement_method'],
                'disbursement_bank_account_id' => $meta['disbursement_bank_account_id'] ?? null,
            ]);
            $request->update(['status' => 'Completed', 'closed_at' => now()]);
        });
    }
}
