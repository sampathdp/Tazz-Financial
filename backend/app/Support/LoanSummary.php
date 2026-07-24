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
        $activePayments = $loan->payments->where('status', '!=', 'Voided');

        $rows = $schedules->map(function ($s) {
            $paidPrincipal = (float) $s->allocations->sum('principal_applied');
            $paidInterest = (float) $s->allocations->sum('interest_applied');
            $paidPenalty = (float) $s->allocations->sum('penalty_applied');

            return (object) [
                'dueDate' => $s->due_date,
                'isFuture' => $s->due_date->greaterThan(Carbon::today()),
                'status' => $s->status,
                'principalDue' => (float) $s->principal_due,
                'interestDue' => (float) $s->interest_due,
                'penaltyDue' => (float) $s->penalty_due,
                'remainingPrincipal' => max(0, (float) $s->principal_due - $paidPrincipal),
                'remainingInterest' => max(0, (float) $s->interest_due - $paidInterest),
                'remainingPenalty' => max(0, (float) $s->penalty_due - $paidPenalty),
                'paidPrincipal' => $paidPrincipal,
                'paidInterest' => $paidInterest,
                'paidPenalty' => $paidPenalty,
            ];
        });

        // --- Due-type breakdown ---
        $rentalDue = round((float) $schedules->sum('total_due'), 2);
        $rentalPaid = round((float) $schedules->sum('paid_amount'), 2);
        $penaltyDue = round((float) $schedules->sum('penalty_due'), 2);
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
