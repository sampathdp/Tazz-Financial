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
        $facilities = DB::table('credit_facilities')
            ->where('facility_type', $facilityType)
            ->pluck('disbursed_at', 'id');
        if ($facilities->isEmpty()) {
            return '0.00';
        }

        $total = '0.00';
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
            $waiverByFacility = DB::table('facility_waivers')
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
                    if ((float) $row->interest_due > 0 && $end->greaterThan($start)) {
                        array_push($slices, ...InterestAccrualSchedule::monthlySlices($start, $end, (float) $row->interest_due));
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
                    $total = bcadd($total, (string) round($net, 2), 2);
                }
            }
        }

        return $total;
    }
}
