<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanFacilityDetail;
use App\Models\LoanType;
use App\Models\SalesExecutiveCommission;
use Illuminate\Support\Facades\Log;

/**
 * Turns an Approved loan into an Active one: writes the disbursement fields,
 * generates the repayment schedule, back-fills the effective (AER) rate and
 * opens the sales-executive commission cycle.
 *
 * It does NOT move any money or post the general ledger — that is
 * LoanLedgerPoster::postDisbursement() / FinancePostingService. Shared by two
 * callers:
 *   - LoanController::disburse()  — the direct path (Finance / admin);
 *   - the FinanceRequestSettled listener — the request path (a loan officer
 *     raises a LoanDisbursement finance request, Finance approves + executes,
 *     the money moves there, then this runs to finish the loan side).
 *
 * Must be called from inside a DB transaction.
 */
class LoanDisbursementCompleter
{
    /**
     * @param array{
     *   first_installment_date:string,
     *   disbursed_amount?:float|string|null,
     *   disbursed_at?:string|null,
     *   disbursement_method:string,
     *   disbursement_bank_account_id?:int|null
     * } $params
     */
    public static function complete(Loan $loan, array $params): void
    {
        $detail = $loan->loanDetail()->with('loanType')->firstOrFail();

        $financed = $params['disbursed_amount']
            ?? ((float) $loan->principal_amount - (float) $detail->down_payment);

        $loan->update([
            'disbursed_amount' => $financed,
            'disbursed_at' => $params['disbursed_at'] ?? now(),
            'outstanding_balance_cache' => $financed,
            'status' => 'Active',
        ]);
        $detail->update([
            'first_installment_date' => $params['first_installment_date'],
            'disbursement_method' => $params['disbursement_method'],
            'disbursement_bank_account_id' => $params['disbursement_method'] === 'Cash'
                ? null
                : ($params['disbursement_bank_account_id'] ?? null),
        ]);

        $rows = LoanAmortizer::generate($loan->fresh(), $detail->fresh(), $params['first_installment_date'], (float) $financed);
        $loan->schedules()->insert($rows);
        // The cache was seeded with bare capital above; once the schedule exists it must
        // carry the full amount owed (principal + interest), same as after any payment.
        $loan->update(['outstanding_balance_cache' => LoanPaymentAllocator::outstandingBalance($loan->fresh())]);
        self::applyEffectiveRate($detail->fresh(), $rows);
        self::generateSalesExecutiveCommission($loan->fresh(), $detail);
    }

    /** Back-fills loan_facility_details.effective_monthly_rate / _annual_rate for a flat-rate loan — the true reducing-balance-equivalent rate its schedule implies (see AerCalculator). */
    public static function applyEffectiveRate(LoanFacilityDetail $detail, array $rows): void
    {
        if ($detail->interest_method !== 'flat' || empty($rows)) {
            return;
        }
        try {
            $principal = (float) ($detail->loan->disbursed_amount ?? ((float) $detail->loan->principal_amount - (float) $detail->down_payment));
            $rental = (float) $rows[0]['total_due'];
            $weekdaysOnly = $detail->installment_frequency === 'daily' && $detail->loanType?->collection_days === 'week_days';
            $periodsPerMonth = LoanAmortizer::periodsPerMonth($detail->installment_frequency, $weekdaysOnly);
            $result = AerCalculator::calculate($principal, $rental, count($rows), $periodsPerMonth);
            if ($result) {
                $detail->update([
                    'effective_monthly_rate' => round($result['monthly'], 8),
                    'effective_annual_rate' => round($result['annual'], 8),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AER calculation skipped for loan ' . $detail->credit_facility_id . ': ' . $e->getMessage());
        }
    }

    /** Creates the first commission cycle for the loan's sales executive, if its loan type has a commission configured. Only runs at disbursal time. */
    public static function generateSalesExecutiveCommission(Loan $loan, LoanFacilityDetail $detail): void
    {
        $loanType = $detail->loanType ?? LoanType::find($detail->loan_type_id);
        if (!$detail->loan_officer_id || !$loanType || (float) $loanType->commission_value <= 0) {
            return;
        }

        $commissionAmount = $loanType->commission_type === 'percentage'
            ? 0.0
            : (float) $loanType->commission_value;

        SalesExecutiveCommission::create([
            'credit_facility_id' => $loan->id,
            'sales_executive_id' => $detail->loan_officer_id,
            'loan_type_id' => $loanType->id,
            'cycle_no' => 1,
            'from_installment' => 0,
            'commission_type' => $loanType->commission_type,
            'commission_value' => $loanType->commission_value,
            'commission_amount' => $commissionAmount,
            'required_installments' => $loanType->min_paid_installments,
        ]);
    }
}
