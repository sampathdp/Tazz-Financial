<?php

namespace App\Support;

use App\Models\FacilitySchedule;
use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Turns an Approved Speed Draft into an Active one: writes the disbursement
 * fields, sets the term dates and generates the interest-only schedule. Does
 * NOT move money / post the GL — that is SpeedDraftLedgerPoster::
 * postDisbursement() / FinancePostingService.
 *
 * Shared by SpeedDraftController::disburse() (direct) and the
 * FinanceRequestSettled listener (request path). Call inside a DB transaction.
 */
class SpeedDraftDisbursementCompleter
{
    /**
     * @param array{
     *   start_date:string,
     *   disbursed_amount?:float|string|null,
     *   disbursement_method:string,
     *   disbursement_bank_account_id?:int|null
     * } $params
     */
    public static function complete(SpeedDraft $draft, array $params): void
    {
        $startDate = Carbon::parse($params['start_date']);

        $draft->update([
            'disbursed_amount' => $params['disbursed_amount'] ?? $draft->principal_amount,
            'disbursed_at' => now(),
            'start_date' => $startDate->format('Y-m-d'),
            'maturity_date' => $startDate->copy()->addMonthsNoOverflow($draft->term_months)->format('Y-m-d'),
            'status' => 'Active',
        ]);
        $draft->speedDraftDetail()->update([
            'disbursement_method' => $params['disbursement_method'],
            'disbursement_bank_account_id' => $params['disbursement_method'] === 'Cash'
                ? null
                : ($params['disbursement_bank_account_id'] ?? null),
        ]);

        FacilitySchedule::insert(SpeedDraftScheduler::generate($draft->fresh()));
    }
}
