<?php

namespace App\Support;

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
 */
class AccrualInterestCalculator
{
    /** @param string $facilityType 'Loan' or 'SpeedDraft' (matches credit_facilities.facility_type) */
    public static function incomeForPeriod(string $facilityType, ?string $from, ?string $to): string
    {
        $facilityIds = DB::table('credit_facilities')->where('facility_type', $facilityType)->pluck('id');
        if ($facilityIds->isEmpty()) {
            return '0.00';
        }

        $total = '0.00';
        foreach ($facilityIds->chunk(200) as $chunk) {
            $rowsByFacility = DB::table('facility_schedules')
                ->whereIn('credit_facility_id', $chunk)
                ->orderBy('due_date')->orderBy('installment_no')
                ->get(['credit_facility_id', 'due_date', 'interest_due'])
                ->groupBy('credit_facility_id');
            if ($rowsByFacility->isEmpty()) {
                continue;
            }

            // Netted oldest-installment-first against each facility's Interest-type
            // waivers, same convention LoanPaymentAllocator/LoanSummary already use
            // elsewhere — a waiver isn't tied to one specific installment row, so it's
            // consumed against the facility's own rows in due-date order.
            $waiverByFacility = DB::table('facility_waivers')
                ->whereIn('credit_facility_id', $chunk)
                ->where('waiver_type', 'Interest')
                ->selectRaw('credit_facility_id, SUM(amount) as total')
                ->groupBy('credit_facility_id')
                ->pluck('total', 'credit_facility_id');

            foreach ($rowsByFacility as $facilityId => $rows) {
                $waiverBudget = (float) ($waiverByFacility[$facilityId] ?? 0);
                foreach ($rows as $row) {
                    $net = (float) $row->interest_due;
                    if ($waiverBudget > 0 && $net > 0) {
                        $consumed = min($waiverBudget, $net);
                        $net -= $consumed;
                        $waiverBudget -= $consumed;
                    }
                    if ($net <= 0) {
                        continue;
                    }
                    $dueDate = (string) $row->due_date;
                    if ($from && $dueDate < $from) {
                        continue;
                    }
                    if ($to && $dueDate > $to) {
                        continue;
                    }
                    $total = bcadd($total, (string) round($net, 2), 2);
                }
            }
        }

        return $total;
    }
}
