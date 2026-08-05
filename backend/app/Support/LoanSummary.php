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

        // Default charges (OD) are computed live via penaltyDueForRow() below,
        // not persisted — recalculated on every view AND every real payment
        // (LoanPaymentController::applyAllocation), so display and actual
        // collection can never disagree about what's owed.
        $loanType = $loan->loanDetail?->loanType;
        $graceDays = (int) ($loanType?->grace_period_days ?? 5);
        $chargeRate = (float) ($loanType?->default_charge_rate_percent ?? 0);

        $rows = $schedules->map(function ($s) use ($today, $graceDays, $chargeRate) {
            $paidPrincipal = (float) $s->allocations->sum('principal_applied');
            $paidInterest = (float) $s->allocations->sum('interest_applied');
            $penaltyDue = self::penaltyDueForRow($s, $graceDays, $chargeRate, $today);
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

        // A waiver is a write-off, not a payment (see FacilityWaiver — it's a lump
        // sum by type, not linked to a specific installment/charge), so it has
        // to reduce what's still owed everywhere that figure is shown: both the
        // Due Types breakdown below and the arrears further down. Each type nets
        // against its own bucket first; anything left over (e.g. waiving interest
        // that isn't overdue yet) rolls forward and reduces the future amount
        // instead, so a waiver can never make the loan appear to owe less than
        // it collected while still leaving room to write off not-yet-due amounts.
        // "Charge" waivers aren't split across Document/Legal/Valuation/Other in
        // the breakdown below — FacilityWaiver has no link to a specific charge, so
        // there's no principled way to attribute one to a particular charge_type;
        // they're only netted into the aggregate otherChargeArrears further down.
        $totalWaived = round((float) $loan->waivers->sum('amount'), 2);
        $waivedByType = $loan->waivers->groupBy('waiver_type');
        $waivedPrincipal = (float) ($waivedByType->get('Principal')?->sum('amount') ?? 0);
        $waivedInterest = (float) ($waivedByType->get('Interest')?->sum('amount') ?? 0);
        $waivedPenalty = (float) ($waivedByType->get('Penalty')?->sum('amount') ?? 0);
        $waivedCharge = (float) ($waivedByType->get('Charge')?->sum('amount') ?? 0);

        // --- Due-type breakdown ---
        $rentalDue = max(0, round((float) $schedules->sum('total_due') - $waivedPrincipal - $waivedInterest, 2));
        $rentalPaid = round((float) $schedules->sum('paid_amount'), 2);
        $penaltyDue = max(0, round((float) $rows->sum('penaltyDue') - $waivedPenalty, 2));
        $penaltyPaid = round((float) $rows->sum('paidPenalty'), 2);

        $dueBreakdown = [
            self::breakdownRow('Rental', $rentalDue, $rentalPaid),
            self::breakdownRow('Default Charges', $penaltyDue, $penaltyPaid),
        ];
        foreach ($charges->groupBy(fn($c) => $c->category?->name ?? 'Unknown') as $type => $group) {
            $dueBreakdown[] = self::breakdownRow($type, round((float) $group->sum('due_amount'), 2), round((float) $group->sum('paid_amount'), 2));
        }

        // --- Arrears / contract status ---
        $rentalPrincipalArrears = round((float) $rows->filter(fn ($r) => !$r->isFuture)->sum('remainingPrincipal'), 2);
        $rentalInterestArrears = round((float) $rows->filter(fn ($r) => !$r->isFuture)->sum('remainingInterest'), 2);
        $otherChargeArrears = round((float) $charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount)), 2);
        $defaultCharges = round((float) $rows->filter(fn ($r) => !$r->isFuture)->sum('remainingPenalty'), 2);
        $futureCapital = round((float) $rows->filter(fn ($r) => $r->isFuture)->sum('remainingPrincipal'), 2);
        $futureInterest = round((float) $rows->filter(fn ($r) => $r->isFuture)->sum('remainingInterest'), 2);
        // Interest remaining across the WHOLE loan (overdue + future combined) —
        // distinct from futureInterest above, which only counts installments not
        // yet due. Waivers screen shows this one: "future interest" there means
        // the full remaining interest, not just the strictly-future slice.
        $totalInterestRemaining = round((float) $rows->sum('remainingInterest'), 2);

        $principalSpillover = max(0, $waivedPrincipal - $rentalPrincipalArrears);
        $interestSpillover = max(0, $waivedInterest - $rentalInterestArrears);

        $rentalArrears = round(max(0, $rentalPrincipalArrears - $waivedPrincipal) + max(0, $rentalInterestArrears - $waivedInterest), 2);
        $otherChargeArrears = max(0, round($otherChargeArrears - $waivedCharge, 2));
        $defaultCharges = max(0, round($defaultCharges - $waivedPenalty, 2));
        $futureCapital = max(0, round($futureCapital - $principalSpillover, 2));
        $futureInterest = max(0, round($futureInterest - $interestSpillover, 2));
        $totalInterestRemaining = max(0, round($totalInterestRemaining - $waivedInterest, 2));

        $dueToDate = round((float) $schedules->filter(fn ($s) => !$s->due_date->greaterThan($today))->sum('total_due'), 2);
        $overPayment = round(max(0, $rentalPaid - $dueToDate), 2);

        $totalArrears = round($rentalArrears + $otherChargeArrears + $defaultCharges, 2);
        $balancePrePayment = round(max(0, $totalArrears - $overPayment), 2);
        $amountToBeCollected = round($balancePrePayment + $futureCapital + $futureInterest, 2);

        // Same "past due date, not fully paid" definition LoanController::overdue()
        // uses for the Overdue Loans list, so the two screens never disagree.
        $overdueSchedules = $schedules->filter(fn ($s) => $s->due_date->lessThan($today) && $s->status !== 'Paid');
        $oldestOverdueDate = $overdueSchedules->min('due_date');
        $daysOverdue = $oldestOverdueDate ? (int) $today->diffInDays($oldestOverdueDate) : 0;
        $overdueInstallments = $overdueSchedules->count();

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
                'totalInterestRemaining' => $totalInterestRemaining,
                'balancePrePayment' => $balancePrePayment,
                'amountToBeCollected' => $amountToBeCollected,
                'totalWaived' => $totalWaived,
                'daysOverdue' => $daysOverdue,
                'overdueInstallments' => $overdueInstallments,
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
     * Live-estimated default charge ("OD") owed on one installment as of
     * $asOf — the single source of truth for this figure, used both for
     * display here and for real payment allocation in LoanPaymentAllocator,
     * so the two can never disagree. Not persisted: recalculated fresh every
     * time.
     *   - grace_period_days (default 5) after due_date before it starts.
     *   - Base is that installment's own unpaid principal+interest balance
     *     (its arrears), not the full outstanding loan principal.
     *   - default_charge_rate_percent is a MONTHLY rate, prorated to a daily
     *     rate by dividing by 30, then applied per day elapsed since the
     *     grace period ended (simple, non-compounding day-by-day accrual).
     */
    public static function penaltyDueForRow($schedule, int $graceDays, float $chargeRate, ?Carbon $asOf = null): float
    {
        $today = $asOf ?? Carbon::today();
        $overdueBalance = max(0, (float) $schedule->principal_due + (float) $schedule->interest_due - (float) $schedule->paid_amount);
        if ($overdueBalance <= 0 || $chargeRate <= 0 || !$schedule->due_date->lessThan($today)) {
            return 0.0;
        }
        $chargeStart = $schedule->due_date->copy()->addDays($graceDays);
        $daysLate = $today->greaterThan($chargeStart) ? $chargeStart->diffInDays($today) : 0;
        if ($daysLate <= 0) {
            return 0.0;
        }

        return round($overdueBalance * ($chargeRate / 100 / 30) * $daysLate, 2);
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

    /**
     * Sum of interest_applied across this loan's schedule allocations, excluding
     * voided or still-pending-approval payments — same "active" definition as
     * activePenaltyPaid, used to price the sales executive's commission against
     * interest actually collected (see SalesExecutiveCommissionController) rather
     * than the loan's disbursed capital. $sinceInstallment restricts this to
     * installments after that number, so a later commission cycle only counts
     * interest not already priced into an earlier cycle. Expects the loan's
     * schedules loaded with allocations.payment (id, status, approval_status).
     */
    public static function collectedInterest(Loan $loan, int $sinceInstallment = 0): float
    {
        return (float) $loan->schedules
            ->filter(fn ($s) => $s->installment_no > $sinceInstallment)
            ->sum(fn ($s) => $s->allocations
                ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
                ->sum('interest_applied'));
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
