<?php

namespace App\Support;

use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Read-only figures for the Speed Draft detail page, derived entirely from
 * already-persisted schedule rows/allocations/outstanding_capital — nothing
 * here is stored, so it can never drift from the ledger it summarizes.
 * Expects $draft eager-loaded with schedules.activeAllocations, speedDraftType
 * (see SpeedDraftController::DETAIL_RELATIONS). Uses activeAllocations, not
 * allocations, everywhere a balance is netted against what's been collected —
 * a voided payment's allocation row is kept for history but must stop
 * counting as collected (see FacilitySchedule::activeAllocations).
 */
class SpeedDraftSummary
{
    public static function build(SpeedDraft $draft): array
    {
        $today = Carbon::today();
        $schedules = $draft->schedules;
        $type = $draft->speedDraftDetail?->speedDraftType;
        $graceDays = (int) ($type?->grace_period_days ?? 5);
        $chargeRate = (float) ($type?->default_charge_rate_percent ?? 0);

        $waived = SpeedDraftWaivers::byRow($draft, $today);
        $totalInterestDue = round((float) $schedules->sum('interest_due'), 2);
        $totalInterestPaid = round((float) $schedules->sum('paid_amount'), 2);
        $totalInterestWaived = round((float) array_sum($waived['interest']), 2);
        $outstandingInterest = max(0, round($totalInterestDue - $totalInterestPaid - $totalInterestWaived, 2));
        $outstandingCharges = SpeedDraftPaymentAllocator::outstandingCharges($draft);

        $outstandingCapital = (float) $draft->outstanding_balance_cache;
        $totalCapitalPaid = round((float) $draft->principal_amount - $outstandingCapital, 2);

        $overdueRows = $schedules->filter(fn ($s) => self::isOverdue($s, $graceDays, $today));
        $oldestOverdueDate = $overdueRows->min('due_date');
        $daysOverdue = $oldestOverdueDate ? (int) $today->diffInDays($oldestOverdueDate) : 0;
        // Net off penalty already collected on each row (see FacilityPaymentAllocation.penalty_applied) —
        // penaltyDueForRow() alone is the gross theoretical figure, same distinction Loan's paidPenalty draws.
        $penaltyDue = round((float) $overdueRows->sum(
            fn ($s) => max(0, self::penaltyDueForRow($s, $graceDays, $chargeRate, $today) - (float) $s->activeAllocations->sum('penalty_applied') - (float) ($waived['penalty'][$s->id] ?? 0))
        ), 2);

        $nextDue = $schedules->where('status', '!=', 'Paid')->sortBy('due_date')->first();

        return [
            'principalAmount' => (float) $draft->principal_amount,
            'outstandingCapital' => $outstandingCapital,
            'totalCapitalPaid' => $totalCapitalPaid,
            'totalInterestDue' => $totalInterestDue,
            'totalInterestPaid' => $totalInterestPaid,
            'totalInterestWaived' => $totalInterestWaived,
            'outstandingInterest' => $outstandingInterest,
            'penaltyDue' => $penaltyDue,
            'outstandingCharges' => $outstandingCharges,
            'amountToSettleToday' => round($outstandingInterest + $penaltyDue + $outstandingCharges + $outstandingCapital, 2),
            'nextDueDate' => $nextDue?->due_date,
            'nextDueAmount' => $nextDue ? max(0, round((float) $nextDue->interest_due - (float) $nextDue->activeAllocations->sum('interest_applied') - (float) ($waived['interest'][$nextDue->id] ?? 0), 2)) : null,
            'daysOverdue' => $daysOverdue,
            'overdueInstallments' => $overdueRows->count(),
            'totalInstallments' => $schedules->count(),
            'paidInstallments' => $schedules->where('status', 'Paid')->count(),
        ];
    }

    /** True once a not-fully-paid installment is past its grace period — display-only, never persisted (see the speed_draft_schedules migration). */
    public static function isOverdue($schedule, int $graceDays, ?Carbon $asOf = null): bool
    {
        $today = $asOf ?? Carbon::today();

        return $schedule->status !== 'Paid' && $schedule->due_date->copy()->addDays($graceDays)->lessThan($today);
    }

    /**
     * Live-estimated penalty owed on one overdue installment as of $asOf —
     * direct adaptation of LoanSummary::penaltyDueForRow for facility_schedules.
     * Never persisted to facility_schedules.penalty_due (see that migration's
     * docblock) — recomputed fresh everywhere it's needed (display here, real
     * payment allocation in SpeedDraftPaymentAllocator) so the two can never
     * disagree. default_charge_rate_percent is a MONTHLY rate, prorated to a
     * daily rate using each calendar month's actual day count, applied per
     * exact day elapsed since the grace period ended.
     */
    public static function penaltyDueForRow($schedule, int $graceDays, float $chargeRate, ?Carbon $asOf = null): float
    {
        $today = $asOf ?? Carbon::today();
        $overdueBalance = max(0, (float) $schedule->principal_due + (float) $schedule->interest_due - (float) $schedule->paid_amount);
        if ($overdueBalance <= 0 || $chargeRate <= 0 || !$schedule->due_date->lessThan($today)) {
            return 0.0;
        }
        $chargeStart = $schedule->due_date->copy()->addDays($graceDays);
        if (!$today->greaterThan($chargeStart)) {
            return 0.0;
        }

        $dailyBase = $overdueBalance * ($chargeRate / 100);
        $penalty = 0.0;
        $cursor = $chargeStart->copy();
        while ($cursor->lessThan($today)) {
            $segmentEnd = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
            if ($segmentEnd->greaterThan($today)) {
                $segmentEnd = $today->copy();
            }
            $days = $cursor->diffInDays($segmentEnd);
            $penalty += $dailyBase / $cursor->daysInMonth * $days;
            $cursor = $segmentEnd;
        }

        return round($penalty, 2);
    }
}
