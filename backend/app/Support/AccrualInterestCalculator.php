<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Answers "how much interest was a facility (Loan or Speed Draft) *supposed*
 * to earn in a given period" — the Accrual Basis view on the Profit & Loss
 * report (see AccountingReportController::plFigures()). Purely a read over
 * facility_schedules; nothing is stored or posted, so this can never drift
 * from the schedule and costs nothing to recompute on every request.
 *
 * Loan and Speed Draft schedule rows share one facility_schedules table
 * (due_date, interest_due always populated for both — see that table's
 * migration docblock), so one implementation covers both facility types.
 *
 * Deliberately queries the underlying tables directly rather than through
 * the Loan/SpeedDraft Eloquent relations — both FacilitySchedule::facility()
 * and FacilityWaiver::facility() are declared against Loan::class, which
 * carries a global scope filtering facility_type='Loan' (see Loan.php) and
 * would silently drop every Speed Draft row.
 *
 * Each installment's interest is apportioned by calendar day across the
 * months its period spans (see InterestAccrualSchedule::monthlySlices()) —
 * a loan disbursed July 10th recognizes only its July-10-to-July-31 share
 * of the first installment's interest in July, with the remainder landing
 * in August — rather than crediting the whole installment to its due date.
 */
class AccrualInterestCalculator
{
    /** @param string $facilityType 'Loan' or 'SpeedDraft' (matches credit_facilities.facility_type) */
    public static function incomeForPeriod(string $facilityType, ?string $from, ?string $to): string
    {
        return array_reduce(self::perFacilityIncome($facilityType, $from, $to), fn ($sum, $amount) => bcadd($sum, $amount, 2), '0.00');
    }

    /**
     * Same figure as incomeForPeriod('Loan', ...), split by each facility's
     * loan_types.name (from loan_facility_details — the join path
     * LoanFacilityDetail::loanType() uses) instead of summed to one total —
     * for the Income by Loan Product table on the P&L (see
     * AccountingReportController::incomeByLoanProduct()). Speed Drafts don't
     * carry a loan_type_id (they have their own SpeedDraftType), so this is
     * Loan-only; SpeedDraft interest keeps showing as its own 4015 line
     * instead of forcing it into this grouping.
     *
     * @return array<string, string> loan type name (or 'Unclassified') => amount
     */
    public static function incomeForPeriodByLoanType(?string $from, ?string $to): array
    {
        $perFacility = self::perFacilityIncome('Loan', $from, $to);
        if (empty($perFacility)) {
            return [];
        }

        $loanTypeByFacility = DB::table('loan_facility_details')
            ->leftJoin('loan_types', 'loan_types.id', '=', 'loan_facility_details.loan_type_id')
            ->whereIn('loan_facility_details.credit_facility_id', array_keys($perFacility))
            ->pluck('loan_types.name', 'loan_facility_details.credit_facility_id');

        $totals = [];
        foreach ($perFacility as $facilityId => $amount) {
            $name = $loanTypeByFacility[$facilityId] ?? 'Unclassified';
            $totals[$name] = bcadd($totals[$name] ?? '0.00', $amount, 2);
        }

        return $totals;
    }

    /** @return array<int, string> credit_facility_id => amount earned in the period, for both incomeForPeriod() (summed) and incomeForPeriodByLoanType() (grouped) to share. */
    private static function perFacilityIncome(string $facilityType, ?string $from, ?string $to): array
    {
        $facilities = DB::table('credit_facilities')
            ->where('facility_type', $facilityType)
            ->pluck('disbursed_at', 'id');
        if ($facilities->isEmpty()) {
            return [];
        }

        $asOf = $to ? null : Carbon::today();

        $totals = [];
        foreach ($facilities->keys()->chunk(200) as $chunk) {
            $rowsByFacility = DB::table('facility_schedules')
                ->whereIn('credit_facility_id', $chunk)
                ->orderBy('installment_no')
                ->get(['credit_facility_id', 'due_date', 'interest_due', 'waived_interest', 'paid_at'])
                ->groupBy('credit_facility_id');
            if ($rowsByFacility->isEmpty()) {
                continue;
            }

            // Netted oldest-slice-first against each facility's Interest-type
            // waivers, same convention LoanPaymentAllocator/LoanSummary already use
            // elsewhere — a manual waiver isn't tied to one specific installment
            // row, so it's consumed against the facility's own slices in
            // chronological order.
            $waiverByFacility = DB::table('facility_waivers')->whereNull('voided_at')
                ->whereIn('credit_facility_id', $chunk)
                ->where('waiver_type', 'Interest')
                ->selectRaw('credit_facility_id, SUM(amount) as total')
                ->groupBy('credit_facility_id')
                ->pluck('total', 'credit_facility_id');

            foreach ($rowsByFacility as $facilityId => $rows) {
                $periodStart = $facilities[$facilityId] ? Carbon::parse($facilities[$facilityId]) : null;
                $slices = [];
                foreach ($rows as $row) {
                    $dueDate = Carbon::parse($row->due_date);
                    $start = $periodStart ?? $dueDate->copy();
                    // Settlement waives the un-accrued remainder of a row and
                    // pays it off immediately (see LoanPaymentAllocator::
                    // applySettlementWaiver()) — for that row only, recognize
                    // up to when it actually closed (paid_at), not its
                    // original due date.
                    $end = ((float) $row->waived_interest > 0 && $row->paid_at)
                        ? Carbon::parse($row->paid_at)
                        : $dueDate;
                    $interest = (float) $row->interest_due;
                    // Open-ended report ("All Dates", no To): only what has accrued
                    // through today, never installments still in the future.
                    if ($asOf && $interest > 0) {
                        $interest = InterestAccrualSchedule::accruedThrough($start, $end, $interest, $asOf);
                        if ($asOf->lessThan($end)) {
                            $end = $asOf->copy();
                        }
                    }
                    if ($interest > 0 && $end->greaterThan($start)) {
                        array_push($slices, ...InterestAccrualSchedule::monthlySlices($start, $end, $interest));
                    }
                    $periodStart = $dueDate;
                }

                $waiverBudget = (float) ($waiverByFacility[$facilityId] ?? 0);
                foreach ($slices as $slice) {
                    $net = $slice['amount'];
                    if ($waiverBudget > 0 && $net > 0) {
                        $consumed = min($waiverBudget, $net);
                        $net -= $consumed;
                        $waiverBudget -= $consumed;
                    }
                    if ($net <= 0) {
                        continue;
                    }
                    $sliceDate = $slice['date']->toDateString();
                    if ($from && $sliceDate < $from) {
                        continue;
                    }
                    if ($to && $sliceDate > $to) {
                        continue;
                    }
                    $totals[$facilityId] = bcadd($totals[$facilityId] ?? '0.00', (string) round($net, 2), 2);
                }
            }
        }

        return $totals;
    }
}
