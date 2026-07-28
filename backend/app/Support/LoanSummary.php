<?php

namespace App\Support;

use App\Models\Loan;
use Carbon\Carbon;

/**
 * Computes the read-only "contract status" figures shown on the Loan Details
 * page from data that already exists (schedules, allocations, charges) —
 * nothing here is persisted, so it can never drift out of sync with the
 * ledger it summarizes. Expects $loan eager-loaded with
 * schedules.allocations, payments, charges (see LoanController::DETAIL_RELATIONS).
 */
class LoanSummary
{
    public static function build(Loan $loan): array
    {
        $today = Carbon::today();
        $schedules = $loan->schedules;
        $charges = $loan->charges;
        // A pending settlement reserves an amount for review but hasn't been
        // allocated yet — it must not count as "received" until approved.
        $activePayments = $loan->payments->where('status', '!=', 'Voided')->where('approval_status', '!=', 'PendingApproval');

        // Default (penalty/OD) interest is computed live via penaltyDueForRow()
        // below, not persisted — recalculated on every view AND every real
        // payment (LoanPaymentController::applyAllocation), so display and
        // actual collection can never disagree about what's owed.
        $graceDays = (int) ($loan->loanType?->grace_period_days ?? 5);
        $penaltyRate = (float) ($loan->loanType?->penalty_rate_percent ?? 0);
        $penaltyMethod = $loan->loanType?->penalty_method ?? 'flat';

        $rows = $schedules->map(function ($s) use ($today, $graceDays, $penaltyRate, $penaltyMethod) {
            $paidPrincipal = (float) $s->allocations->sum('principal_applied');
            $paidInterest = (float) $s->allocations->sum('interest_applied');
            $penaltyDue = self::penaltyDueForRow($s, $graceDays, $penaltyRate, $penaltyMethod, $today);
            // A real payment can earmark part of itself against penalty — see
            // LoanPaymentController::applyAllocation() — so net that off here.
            $paidPenalty = self::activePenaltyPaid($s);

            return (object) [
                'dueDate' => $s->due_date,
                'isFuture' => $s->due_date->greaterThan(Carbon::today()),
                'status' => $s->status,
                'principalDue' => (float) $s->principal_due,
                'interestDue' => (float) $s->interest_due,
                'penaltyDue' => $penaltyDue,
                'remainingPrincipal' => max(0, (float) $s->principal_due - $paidPrincipal),
                'remainingInterest' => max(0, (float) $s->interest_due - $paidInterest),
                'remainingPenalty' => max(0, $penaltyDue - $paidPenalty),
                'paidPrincipal' => $paidPrincipal,
                'paidInterest' => $paidInterest,
                'paidPenalty' => $paidPenalty,
            ];
        });

        // --- Due-type breakdown ---
        $rentalDue = round((float) $schedules->sum('total_due'), 2);
        $rentalPaid = round((float) $schedules->sum('paid_amount'), 2);
        $penaltyDue = round((float) $rows->sum('penaltyDue'), 2);
        $penaltyPaid = round((float) $rows->sum('paidPenalty'), 2);

        $dueBreakdown = [
            self::breakdownRow('Rental', $rentalDue, $rentalPaid),
            self::breakdownRow('Default Interest', $penaltyDue, $penaltyPaid),
        ];
        foreach ($charges->groupBy('charge_type') as $type => $group) {
            $dueBreakdown[] = self::breakdownRow($type, round((float) $group->sum('due_amount'), 2), round((float) $group->sum('paid_amount'), 2));
        }

        // --- Arrears / contract status ---
        $rentalArrears = round((float) $rows->filter(fn ($r) => !$r->isFuture)->sum(fn ($r) => $r->remainingPrincipal + $r->remainingInterest), 2);
        $otherChargeArrears = round((float) $charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount)), 2);
        $defaultCharges = round((float) $rows->filter(fn ($r) => !$r->isFuture)->sum('remainingPenalty'), 2);
        $futureCapital = round((float) $rows->filter(fn ($r) => $r->isFuture)->sum('remainingPrincipal'), 2);
        $futureInterest = round((float) $rows->filter(fn ($r) => $r->isFuture)->sum('remainingInterest'), 2);

        $dueToDate = round((float) $schedules->filter(fn ($s) => !$s->due_date->greaterThan($today))->sum('total_due'), 2);
        $overPayment = round(max(0, $rentalPaid - $dueToDate), 2);

        $totalArrears = round($rentalArrears + $otherChargeArrears + $defaultCharges, 2);
        $balancePrePayment = round(max(0, $totalArrears - $overPayment), 2);
        $amountToBeCollected = round($balancePrePayment + $futureCapital + $futureInterest, 2);

        // --- Recovery details ---
        $lastPayment = $activePayments->sortByDesc('paid_at')->first();
        $fallenDueRentals = $schedules->filter(fn ($s) => !$s->due_date->greaterThan($today) && $s->status !== 'Paid')->count();
        $settledRentals = $schedules->where('status', 'Paid')->count();
        $outstandingRentals = $schedules->where('status', '!=', 'Paid')->count();
        $futureRentals = $schedules->filter(fn ($s) => $s->due_date->greaterThan($today) && $s->status !== 'Paid')->count();

        return [
            'dueBreakdown' => $dueBreakdown,
            'arrears' => [
                'rentalArrears' => $rentalArrears,
                'otherChargeArrears' => $otherChargeArrears,
                'defaultCharges' => $defaultCharges,
                'totalArrears' => $totalArrears,
                'overPayment' => $overPayment,
                'futureCapital' => $futureCapital,
                'futureInterest' => $futureInterest,
                'balancePrePayment' => $balancePrePayment,
                'amountToBeCollected' => $amountToBeCollected,
            ],
            'recovery' => [
                'lastPaymentDate' => $lastPayment?->paid_at,
                'lastPaymentAmount' => $lastPayment ? (float) $lastPayment->amount : null,
                'fallenDueRentals' => $fallenDueRentals,
                'settledRentals' => $settledRentals,
                'outstandingRentals' => $outstandingRentals,
                'futureRentals' => $futureRentals,
            ],
        ];
    }

    /**
     * Live-estimated penalty ("OD") owed on one installment as of $asOf — the
     * single source of truth for this figure, used both for display here and
     * for real payment allocation in LoanPaymentController, so the two can
     * never disagree. Not persisted: recalculated fresh every time.
     *   - grace_period_days (default 5) after due_date before it starts.
     *   - Base is that installment's own unpaid principal+interest balance,
     *     not the full outstanding loan principal.
     *   - 'daily' method: penalty_rate_percent applied per day elapsed since
     *     the grace period ended (simple, non-compounding).
     *   - 'flat' method: penalty_rate_percent applied once, as soon as the
     *     installment is past its grace period.
     */
    public static function penaltyDueForRow($schedule, int $graceDays, float $penaltyRate, string $penaltyMethod, ?Carbon $asOf = null): float
    {
        $today = $asOf ?? Carbon::today();
        $overdueBalance = max(0, (float) $schedule->principal_due + (float) $schedule->interest_due - (float) $schedule->paid_amount);
        if ($overdueBalance <= 0 || $penaltyRate <= 0 || !$schedule->due_date->lessThan($today)) {
            return 0.0;
        }
        $penaltyStart = $schedule->due_date->copy()->addDays($graceDays);
        $daysLate = $today->greaterThan($penaltyStart) ? $penaltyStart->diffInDays($today) : 0;
        if ($daysLate <= 0) {
            return 0.0;
        }

        return $penaltyMethod === 'daily'
            ? round($overdueBalance * ($penaltyRate / 100) * $daysLate, 2)
            : round($overdueBalance * ($penaltyRate / 100), 2);
    }

    /**
     * Sum of penalty_applied across this schedule's allocations, excluding
     * voided or still-pending-approval payments. Unlike principal/interest
     * (netted against paid_amount, a persisted running total that's already
     * correctly reversed on void — see LoanPaymentController::destroy()),
     * penalty has no such column: it's summed straight off allocation rows,
     * which a void doesn't delete, so a voided payment's contribution has to
     * be excluded explicitly here instead. Expects the schedule's
     * `allocations.payment` relation eager-loaded (id, status, approval_status).
     */
    public static function activePenaltyPaid($schedule): float
    {
        return (float) $schedule->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum('penalty_applied');
    }

    private static function breakdownRow(string $type, float $due, float $paid): array
    {
        $paid = min($paid, $due) < 0 ? 0 : $paid;
        $balance = round($due - $paid, 2);
        $recoveryPct = $due > 0 ? round(($paid / $due) * 100, 2) : ($paid > 0 ? 100.0 : 0.0);

        return [
            'type' => $type,
            'due' => $due,
            'paid' => $paid,
            'balance' => $balance,
            'recoveryPct' => $recoveryPct,
        ];
    }
}
