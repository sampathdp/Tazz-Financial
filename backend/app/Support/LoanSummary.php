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
                'scheduleId' => $s->id,
                'installmentNo' => $s->installment_no,
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
        // Principal and Interest are split into their own rows (rather than a
        // single combined "Rental" figure) so a cashier can see the interest
        // portion on its own — $rentalPaid (combined) is still needed below for
        // the overpayment calc, so it stays alongside the two split figures.
        $principalDue = max(0, round((float) $schedules->sum('principal_due') - $waivedPrincipal, 2));
        $principalPaid = round((float) $rows->sum('paidPrincipal'), 2);
        $interestDue = max(0, round((float) $schedules->sum('interest_due') - $waivedInterest, 2));
        $interestPaid = round((float) $rows->sum('paidInterest'), 2);
        $rentalPaid = round((float) $schedules->sum('paid_amount'), 2);
        // penaltyDueForRow() is LIVE: once an installment is fully settled its
        // overdue balance is 0, so the row's live penalty resets to 0 even though
        // penalty WAS charged and collected while it sat overdue. Summing that
        // live figure as "due" would understate/zero out anything already paid
        // off, so "due" here is built from what's provably happened instead:
        // collected penalty (paidPenalty, a real historical sum) plus whatever
        // penalty is still outstanding right now (remainingPenalty) — this can
        // never fall below paid, unlike summing the live per-row figure.
        $penaltyPaid = round((float) $rows->sum('paidPenalty'), 2);
        $penaltyDue = max(0, round($penaltyPaid + (float) $rows->sum('remainingPenalty') - $waivedPenalty, 2));

        $dueBreakdown = [
            self::breakdownRow('Principal', $principalDue, $principalPaid),
            self::breakdownRow('Interest', $interestDue, $interestPaid),
            self::breakdownRow('Default Charges (OD)', $penaltyDue, $penaltyPaid),
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
        // Fractional, not a row count — a partially paid overdue installment (e.g.
        // 60% collected) contributes 0.4, not a full 1. Same basis as overdueList()
        // below, so Loan Detail and Overdue Loans never disagree.
        $overdueInstallments = round((float) $overdueSchedules->sum(function ($s) {
            $totalDue = (float) $s->total_due;
            return $totalDue > 0 ? max(0, $totalDue - (float) $s->paid_amount) / $totalDue : 0;
        }), 2);

        // --- Recovery details ---
        // Fractional, not row counts — same basis as overdueInstallments above:
        // a rental 60% collected contributes 0.6 settled / 0.4 outstanding, not a
        // whole 1, so these track real recovery progress instead of round numbers.
        $paidFraction = fn ($s) => (float) $s->total_due > 0 ? min(1, (float) $s->paid_amount / (float) $s->total_due) : ($s->status === 'Paid' ? 1 : 0);
        $unpaidFraction = fn ($s) => 1 - $paidFraction($s);

        $lastPayment = $activePayments->sortByDesc('paid_at')->first();
        $fallenDueRentals = round((float) $schedules->filter(fn ($s) => !$s->due_date->greaterThan($today))->sum($unpaidFraction), 2);
        $settledRentals = round((float) $schedules->sum($paidFraction), 2);
        $outstandingRentals = round((float) $schedules->sum($unpaidFraction), 2);
        $futureRentals = round((float) $schedules->filter(fn ($s) => $s->due_date->greaterThan($today))->sum($unpaidFraction), 2);

        // Per-installment penalty (due = collected + still-outstanding, same
        // basis as the Default Charges row above), so the ledger card can post
        // a debit for it — schedules/charges/payments alone carry no penalty
        // figure, since penalty is never persisted (see class docblock).
        $schedulePenalties = $rows->filter(fn ($r) => $r->paidPenalty > 0 || $r->remainingPenalty > 0)
            ->map(fn ($r) => [
                'scheduleId' => $r->scheduleId,
                'installmentNo' => $r->installmentNo,
                'dueDate' => $r->dueDate,
                'due' => round($r->paidPenalty + $r->remainingPenalty, 2),
                'paid' => round($r->paidPenalty, 2),
            ])->values();

        return [
            'dueBreakdown' => $dueBreakdown,
            'schedulePenalties' => $schedulePenalties,
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
     * Active loans with at least one installment past its due date and not
     * fully paid, as of $asOf (days-overdue/penalty computed as of it). If
     * $from is given, only installments due on/after that date count.
     * Shared by LoanController::overdue() and LoanReportController's
     * 'arrears' report so the two can never disagree about who's overdue.
     */
    public static function overdueList(Carbon $asOf, ?Carbon $from = null)
    {
        $scheduleFilter = function ($q) use ($asOf, $from) {
            $q->where('due_date', '<', $asOf)->where('status', '!=', 'Paid');
            if ($from) {
                $q->where('due_date', '>=', $from);
            }
        };

        $loans = Loan::where('status', 'Active')
            ->whereHas('schedules', $scheduleFilter)
            ->with([
                'customer:id,full_name,code,nic_no,phone',
                'coApplicants.coApplicant:id,full_name,code,nic_no,phone',
                'branch:id,name,code',
                'loanDetail.loanType:id,name,code,default_charge_rate_percent,grace_period_days',
                'loanDetail.loanOfficer:id,name',
                'loanDetail.recoveryOfficer:id,name',
                'schedules' => function ($q) use ($scheduleFilter) { $scheduleFilter($q); $q->orderBy('due_date'); },
            ])
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount')
            ->get();

        return $loans->map(function ($loan) use ($asOf) {
            $loanType = $loan->loanDetail?->loanType;
            $graceDays = (int) ($loanType?->grace_period_days ?? 5);
            $chargeRate = (float) ($loanType?->default_charge_rate_percent ?? 0);

            $overdueSchedules = $loan->schedules;
            $oldestDueDate = $overdueSchedules->min('due_date');

            $data = $loan->toArray();
            $data['oldestOverdueDate'] = $oldestDueDate;
            $data['daysOverdue'] = $oldestDueDate ? (int) $asOf->diffInDays($oldestDueDate) : 0;
            // Fractional, not a plain row count: a partially paid installment (e.g.
            // 60% collected) contributes 0.4, not a full 1 — mirrors penaltyDueForRow's
            // exact-balance basis instead of treating every overdue row as whole.
            $data['overdueInstallments'] = round((float) $overdueSchedules->sum(function ($s) {
                $totalDue = (float) $s->total_due;
                return $totalDue > 0 ? max(0, $totalDue - (float) $s->paid_amount) / $totalDue : 0;
            }), 2);
            $data['overdueAmount'] = round((float) $overdueSchedules->sum(
                fn ($s) => max(0, (float) $s->principal_due + (float) $s->interest_due - (float) $s->paid_amount)
            ), 2);
            $data['penaltyDue'] = round((float) $overdueSchedules->sum(
                fn ($s) => self::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf)
            ), 2);

            return $data;
        })->sortByDesc('daysOverdue')->values();
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
     *     rate using the actual number of days in each calendar month the
     *     overdue period passes through (not a flat 30-day assumption), then
     *     applied per exact day elapsed since the grace period ended (simple,
     *     non-compounding day-by-day accrual).
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

        // Same sum as a day-by-day loop (one term per day, at that day's
        // calendar month's daily rate) but grouped into one term per calendar
        // month spanned — a loan overdue 300+ days needs ~10 iterations here
        // instead of 300+, since the per-day rate is constant within a month.
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
