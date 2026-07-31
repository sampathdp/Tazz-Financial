<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanChargeAllocation;
use App\Models\LoanPayment;
use App\Models\LoanPaymentAllocation;
use Carbon\Carbon;

/**
 * Applies a payment amount to a loan's schedule/charges. Shared by an
 * immediate Cash/Bank payment, an approved settlement, a realized cheque
 * payment, and a realized post-dated cheque — so all four price OD/penalty
 * and settle installments identically.
 */
class LoanPaymentAllocator
{
    /** Standard-practice loan servicing figures for this loan's penalty config
     *  (see LoanSummary::penaltyDueForRow) — shared by outstandingBalance() and
     *  applyAllocation() so both price "OD" identically. */
    public static function penaltyTerms(Loan $loan): array
    {
        return [
            (int) ($loan->loanType?->grace_period_days ?? 5),
            (float) ($loan->loanType?->penalty_rate_percent ?? 0),
            $loan->loanType?->penalty_method ?? 'flat',
        ];
    }

    /** Everything this loan owes as of $asOf (defaults to today): unpaid
     *  principal+interest, unpaid penalty/OD (live estimate as of that date,
     *  same formula as the Contract Status tab), and unpaid Document/Legal/
     *  Valuation/Other charges. */
    public static function outstandingBalance(Loan $loan, ?Carbon $asOf = null): float
    {
        $schedules = $loan->schedules()->with('allocations.payment:id,status,approval_status')->get();
        $scheduleBalance = $schedules->where('status', '!=', 'Paid')
            ->sum(fn ($s) => (float) $s->total_due - (float) $s->paid_amount);

        [$graceDays, $penaltyRate, $penaltyMethod] = self::penaltyTerms($loan);
        $asOf ??= Carbon::today();
        $penaltyBalance = $penaltyRate > 0
            ? $schedules->sum(function ($s) use ($graceDays, $penaltyRate, $penaltyMethod, $asOf) {
                $due = LoanSummary::penaltyDueForRow($s, $graceDays, $penaltyRate, $penaltyMethod, $asOf);
                $paid = LoanSummary::activePenaltyPaid($s);

                return max(0, round($due - $paid, 2));
            })
            : 0.0;

        $chargesBalance = (float) $loan->charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount));

        return round($scheduleBalance + $penaltyBalance + $chargesBalance, 2);
    }

    /**
     * Applies `$amount` in standard loan-servicing order: Interest, then OD
     * (penalty), then other charges (Document/Legal/Valuation/Other), then
     * Principal — each bucket oldest-first across the loan's unpaid
     * installments/charges — then closes the loan once nothing is owed.
     *
     * $asOf is the date the amount is considered to have landed in the loan
     * (e.g. a cheque's realization/check-in date, not the date it was
     * recorded or the date staff happened to click "Realize") — it drives
     * both the OD/penalty calculation and the settled installment's paid_at,
     * defaulting to today for payment types that don't defer settlement.
     */
    public static function applyAllocation(Loan $loan, LoanPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $penaltyRate, $penaltyMethod] = self::penaltyTerms($loan);

        // A void doesn't delete allocation rows (kept for audit trail), so every
        // "how much of this row is already paid" lookup below must exclude a
        // voided (or still-pending-approval) payment's rows explicitly — the
        // authoritative-and-already-reversed paid_amount only covers principal+
        // interest combined, not the per-bucket split these passes need.
        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);

        // Accumulate this payment's contribution per schedule row across all
        // three buckets that touch schedules, so exactly one allocation row is
        // written per installment (mirrors the pre-existing convention).
        $applied = [];
        $touch = function (int $scheduleId, string $bucket, float $amt) use (&$applied) {
            if ($amt <= 0) {
                return;
            }
            $applied[$scheduleId] ??= ['interest' => 0.0, 'penalty' => 0.0, 'principal' => 0.0];
            $applied[$scheduleId][$bucket] += $amt;
        };

        $remaining = $amount;

        // 1) Interest — oldest installment first.
        foreach ($schedules as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied'), 2));
            if ($due <= 0) {
                continue;
            }
            $pay = min($remaining, $due);
            $touch($row->id, 'interest', $pay);
            $remaining = round($remaining - $pay, 2);
        }

        // 2) OD / penalty — live estimate as of $asOf per installment, oldest first.
        if ($remaining > 0 && $penaltyRate > 0) {
            foreach ($schedules as $row) {
                if ($remaining <= 0) {
                    break;
                }
                $penaltyDue = LoanSummary::penaltyDueForRow($row, $graceDays, $penaltyRate, $penaltyMethod, $asOf);
                $due = max(0, round($penaltyDue - $activeApplied($row, 'penalty_applied'), 2));
                if ($due <= 0) {
                    continue;
                }
                $pay = min($remaining, $due);
                $touch($row->id, 'penalty', $pay);
                $remaining = round($remaining - $pay, 2);
            }
        }

        // 3) Other charges (Document/Legal/Valuation/Other) — oldest first.
        if ($remaining > 0) {
            $charges = $loan->charges()->orderBy('charged_at')->get();
            foreach ($charges as $charge) {
                if ($remaining <= 0) {
                    break;
                }
                $due = max(0, round((float) $charge->due_amount - (float) $charge->paid_amount, 2));
                if ($due <= 0) {
                    continue;
                }
                $pay = min($remaining, $due);
                $charge->update(['paid_amount' => round((float) $charge->paid_amount + $pay, 2)]);
                LoanChargeAllocation::create(['loan_payment_id' => $payment->id, 'loan_charge_id' => $charge->id, 'amount_applied' => $pay]);
                $remaining = round($remaining - $pay, 2);
            }
        }

        // 4) Principal — oldest installment first, whatever's left.
        foreach ($schedules as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied'), 2));
            if ($due <= 0) {
                continue;
            }
            $pay = min($remaining, $due);
            $touch($row->id, 'principal', $pay);
            $remaining = round($remaining - $pay, 2);
        }

        foreach ($applied as $scheduleId => $buckets) {
            LoanPaymentAllocation::create([
                'loan_payment_id' => $payment->id,
                'loan_schedule_id' => $scheduleId,
                'principal_applied' => round($buckets['principal'], 2),
                'interest_applied' => round($buckets['interest'], 2),
                'penalty_applied' => round($buckets['penalty'], 2),
            ]);

            $row = $schedules->firstWhere('id', $scheduleId);
            // Only principal+interest count toward an installment's own total_due/
            // status — penalty is priced and collected separately (see above) and
            // never was, and isn't now, part of that figure (see LoanAmortizer).
            $newPaid = round((float) $row->paid_amount + $buckets['interest'] + $buckets['principal'], 2);
            $row->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid >= (float) $row->total_due ? 'Paid' : 'Partial',
                'paid_at' => $newPaid >= (float) $row->total_due ? $asOf : $row->paid_at,
            ]);
        }

        // Only an already-active loan closes on full payoff — an Approved loan
        // paying off a pre-disbursement charge (see LoanController::disburse())
        // has no schedule yet and must stay Approved, ready to disburse.
        if ($loan->status === 'Active' && self::outstandingBalance($loan) <= 0) {
            $loan->update(['status' => 'Closed', 'closed_at' => $asOf]);
        }
    }
}
