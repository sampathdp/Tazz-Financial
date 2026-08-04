<?php

namespace App\Support;

use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Read-only figures for the Speed Draft detail page, derived entirely from
 * already-persisted schedule rows/allocations/outstanding_capital — nothing
 * here is stored, so it can never drift from the ledger it summarizes.
 * Expects $draft eager-loaded with schedules.allocations, speedDraftType
 * (see SpeedDraftController::DETAIL_RELATIONS).
 */
class SpeedDraftSummary
{
    public static function build(SpeedDraft $draft): array
    {
        $today = Carbon::today();
        $schedules = $draft->schedules;
        $graceDays = (int) ($draft->speedDraftDetail?->speedDraftType?->grace_period_days ?? 5);

        $totalInterestDue = round((float) $schedules->sum('interest_due'), 2);
        $totalInterestPaid = round((float) $schedules->sum('paid_amount'), 2);
        $outstandingInterest = max(0, round($totalInterestDue - $totalInterestPaid, 2));

        $outstandingCapital = (float) $draft->outstanding_balance_cache;
        $totalCapitalPaid = round((float) $draft->principal_amount - $outstandingCapital, 2);

        $overdueRows = $schedules->filter(fn ($s) => self::isOverdue($s, $graceDays, $today));
        $oldestOverdueDate = $overdueRows->min('due_date');
        $daysOverdue = $oldestOverdueDate ? (int) $today->diffInDays($oldestOverdueDate) : 0;

        $nextDue = $schedules->where('status', '!=', 'Paid')->sortBy('due_date')->first();

        return [
            'principalAmount' => (float) $draft->principal_amount,
            'outstandingCapital' => $outstandingCapital,
            'totalCapitalPaid' => $totalCapitalPaid,
            'totalInterestDue' => $totalInterestDue,
            'totalInterestPaid' => $totalInterestPaid,
            'outstandingInterest' => $outstandingInterest,
            'amountToSettleToday' => round($outstandingInterest + $outstandingCapital, 2),
            'nextDueDate' => $nextDue?->due_date,
            'nextDueAmount' => $nextDue ? max(0, round((float) $nextDue->interest_due - (float) $nextDue->allocations->sum('interest_applied'), 2)) : null,
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
}
