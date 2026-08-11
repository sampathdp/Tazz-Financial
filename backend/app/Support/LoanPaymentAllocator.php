<?php

namespace App\Support;

use App\Models\FacilityChargeAllocation;
use App\Models\FacilityPayment;
use App\Models\FacilityPaymentAllocation;
use App\Models\Loan;
use Carbon\Carbon;

/**
 * Applies a payment amount to a loan's schedule/charges. Shared by an
 * immediate Cash/Bank payment, an approved settlement, a realized cheque
 * payment, and a realized post-dated cheque — so all four price OD/penalty
 * and settle installments identically.
 */
class LoanPaymentAllocator
{
    /** Standard-practice loan servicing figures for this loan's default charge
     *  config (see LoanSummary::penaltyDueForRow) — shared by outstandingBalance()
     *  and applyAllocation() so both price "OD" identically. */
    public static function penaltyTerms(Loan $loan): array
    {
        $loanType = $loan->loanDetail?->loanType;

        return [
            (int) ($loanType?->grace_period_days ?? 5),
            (float) ($loanType?->default_charge_rate_percent ?? 0),
        ];
    }

    /** Everything this loan owes as of $asOf (defaults to today): unpaid
     *  principal+interest, unpaid penalty/OD (live estimate as of that date,
     *  same formula as the Contract Status tab), and unpaid Document/Legal/
     *  Valuation/Other charges — net of any waivers (write-offs; see
     *  FacilityWaiver), so a loan waived down to zero can still close on its own,
     *  the same way the Contract Status tab's Amount To Be Collected already
     *  nets waivers off (see LoanSummary::build). */
    public static function outstandingBalance(Loan $loan, ?Carbon $asOf = null): float
    {
        $schedules = $loan->schedules()->with('allocations.payment:id,status,approval_status')->get();
        $scheduleBalance = $schedules->where('status', '!=', 'Paid')
            ->sum(fn ($s) => (float) $s->total_due - (float) $s->paid_amount);

        [$graceDays, $chargeRate] = self::penaltyTerms($loan);
        $asOf ??= Carbon::today();
        $penaltyBalance = $chargeRate > 0
            ? $schedules->sum(function ($s) use ($graceDays, $chargeRate, $asOf) {
                $due = LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf);
                $paid = LoanSummary::activePenaltyPaid($s);

                return max(0, round($due - $paid, 2));
            })
            : 0.0;

        // Queried fresh every time (not the cached $loan->charges/$loan->waivers
        // property) — this method runs more than once against the same $loan
        // instance within a single request (e.g. a pre-check in
        // LoanPaymentController::approveSettlement() followed by this same
        // method's own post-allocation call inside applyAllocation() below), and
        // a cached collection from the first call would silently ignore charge
        // payments the second call needs to see.
        $chargesBalance = (float) $loan->charges()->get()->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount));
        $waived = (float) $loan->waivers()->sum('amount');

        return max(0, round($scheduleBalance + $penaltyBalance + $chargesBalance - $waived, 2));
    }

    /**
     * Same shape as outstandingBalance(), but for an early full settlement:
     * each unpaid installment's interest is capped at what's actually
     * accrued by $asOf (see InterestAccrualSchedule::accruedThrough()) —
     * the rest is waived rather than collected, matching the client's early-
     * settlement rule (accrued-to-date interest only, remaining future
     * interest forgiven). Read-only; see applySettlementWaiver() for the
     * write side, applied only once the settlement is actually approved.
     *
     * @return array{outstanding: float, waivedInterest: array<int, float>} waivedInterest is keyed by facility_schedules.id
     */
    public static function settlementQuote(Loan $loan, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();

        $activeInterestPaid = fn ($row) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum('interest_applied');

        $periodStart = $loan->disbursed_at ? Carbon::parse($loan->disbursed_at) : null;
        $waivedInterest = [];
        $scheduleBalance = 0.0;
        foreach ($schedules as $row) {
            $dueDate = $row->due_date instanceof Carbon ? $row->due_date : Carbon::parse($row->due_date);
            $start = $periodStart ?? $dueDate->copy();
            $periodStart = $dueDate;

            if ($row->status === 'Paid') {
                continue;
            }

            $interestDue = (float) $row->interest_due;
            $accrued = InterestAccrualSchedule::accruedThrough($start, $dueDate, $interestDue, $asOf);
            // Never waive interest that's already been collected on this row.
            $floor = max($accrued, $activeInterestPaid($row));
            $waive = max(0, round($interestDue - $floor, 2));
            if ($waive > 0) {
                $waivedInterest[$row->id] = $waive;
            }

            $scheduleBalance += (float) $row->total_due - $waive - (float) $row->paid_amount;
        }

        [$graceDays, $chargeRate] = self::penaltyTerms($loan);
        $penaltyBalance = $chargeRate > 0
            ? $schedules->sum(function ($s) use ($graceDays, $chargeRate, $asOf) {
                $due = LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf);
                $paid = LoanSummary::activePenaltyPaid($s);

                return max(0, round($due - $paid, 2));
            })
            : 0.0;

        $chargesBalance = (float) $loan->charges()->get()->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount));
        $waived = (float) $loan->waivers()->sum('amount');

        $outstanding = max(0, round($scheduleBalance + $penaltyBalance + $chargesBalance - $waived, 2));

        return ['outstanding' => $outstanding, 'waivedInterest' => $waivedInterest];
    }

    /**
     * Writes the waiver settlementQuote() only quoted — reduces each affected
     * row's interest_due/total_due by its waived amount and records it in
     * waived_interest (audit trail + what LoanPaymentController::destroy()
     * adds back if an approved settlement is later voided). Called once,
     * inside approveSettlement()'s transaction, immediately before
     * applyAllocation() — never against a still-pending request, so a
     * rejected settlement never needed to touch the schedule at all.
     */
    public static function applySettlementWaiver(Loan $loan, array $waivedInterest): void
    {
        if (empty($waivedInterest)) {
            return;
        }
        $rows = $loan->schedules()->whereIn('id', array_keys($waivedInterest))->get();
        foreach ($rows as $row) {
            $waive = $waivedInterest[$row->id];
            $row->update([
                'interest_due' => round((float) $row->interest_due - $waive, 2),
                'total_due' => round((float) $row->total_due - $waive, 2),
                'waived_interest' => round($waive, 2),
            ]);
        }
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
    public static function applyAllocation(Loan $loan, FacilityPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);

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
        $charges = $loan->charges()->orderBy('charged_at')->get();

        $payCharges = function () use (&$remaining, $charges, $payment) {
            if ($remaining <= 0) {
                return;
            }
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
                FacilityChargeAllocation::create(['facility_payment_id' => $payment->id, 'facility_charge_id' => $charge->id, 'amount_applied' => $pay]);
                $remaining = round($remaining - $pay, 2);
            }
        };

        // Apply to oldest installment first, fully settling each before moving to the next.
        foreach ($schedules as $row) {
            if ($remaining <= 0) {
                break;
            }
            
            // 1) Interest
            $due = max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied'), 2));
            if ($due > 0) {
                $pay = min($remaining, $due);
                $touch($row->id, 'interest', $pay);
                $remaining = round($remaining - $pay, 2);
            }

            // 2) OD / penalty
            if ($remaining > 0 && $chargeRate > 0) {
                $penaltyDue = LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf);
                $due = max(0, round($penaltyDue - $activeApplied($row, 'penalty_applied'), 2));
                if ($due > 0) {
                    $pay = min($remaining, $due);
                    $touch($row->id, 'penalty', $pay);
                    $remaining = round($remaining - $pay, 2);
                }
            }

            // 3) Other charges (Document/Legal/Valuation/Other)
            $payCharges();

            // 4) Principal
            if ($remaining > 0) {
                $due = max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied'), 2));
                if ($due > 0) {
                    $pay = min($remaining, $due);
                    $touch($row->id, 'principal', $pay);
                    $remaining = round($remaining - $pay, 2);
                }
            }
        }

        // Pay any remaining charges if there were no schedules or schedules were fully paid
        $payCharges();

        foreach ($applied as $scheduleId => $buckets) {
            FacilityPaymentAllocation::create([
                'facility_payment_id' => $payment->id,
                'facility_schedule_id' => $scheduleId,
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

        // Keeps credit_facilities.outstanding_balance_cache in step with this
        // method's own result — every caller that allocates a payment gets a
        // fresh cache for free, rather than each one refreshing it separately.
        $outstanding = self::outstandingBalance($loan);
        $loan->update(['outstanding_balance_cache' => $outstanding]);

        // Only an already-active loan closes on full payoff — an Approved loan
        // paying off a pre-disbursement charge (see LoanController::disburse())
        // has no schedule yet and must stay Approved, ready to disburse.
        if ($loan->status === 'Active' && $outstanding <= 0) {
            $loan->update(['status' => 'Closed', 'closed_at' => $asOf]);
        }
    }
}
