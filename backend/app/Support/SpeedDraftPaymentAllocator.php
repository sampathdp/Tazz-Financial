<?php

namespace App\Support;

use App\Models\FacilityPayment;
use App\Models\FacilityPaymentAllocation;
use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Applies a payment to a Speed Draft. Unlike Loan (one blended payment covers
 * principal+interest+penalty together), a Speed Draft payment is always one
 * of three distinct, teller-chosen actions (see FacilityPaymentController::store()
 * and FacilityPayment.payment_type):
 *   - Interest: pays down unpaid interest installments, oldest first. Never
 *     touches outstanding_balance_cache.
 *   - CapitalReduction: reduces outstanding_balance_cache directly — not tied
 *     to any schedule row (see SpeedDraftScheduler's docblock for why) — then
 *     re-prices every future installment's interest.
 *   - Settlement: pays every unpaid interest installment and then the full
 *     remaining capital in one transaction, closing the draft.
 * This is what keeps interest payments and capital reductions distinguishable
 * in the payment history without a separate "capital movements" table — the
 * type tags intent, and each allocation's interest_applied/principal_applied
 * columns give the exact breakdown.
 */
class SpeedDraftPaymentAllocator
{
    /**
     * Total unpaid interest across every not-fully-paid schedule row. Nets
     * against activeAllocations (not allocations) so a voided interest
     * payment's allocation row — kept for history, never deleted — doesn't
     * keep counting as collected.
     */
    public static function outstandingInterest(SpeedDraft $draft): float
    {
        return round((float) $draft->schedules()->where('status', '!=', 'Paid')->with('activeAllocations')->get()
            ->sum(fn ($s) => (float) $s->interest_due - (float) $s->activeAllocations->sum('interest_applied')), 2);
    }

    /**
     * Total live-estimated penalty owed across every overdue schedule row,
     * net of penalty already collected on it — see SpeedDraftSummary::penaltyDueForRow,
     * the single source of truth this also uses for display.
     */
    public static function outstandingPenalty(SpeedDraft $draft, ?Carbon $asOf = null): float
    {
        $asOf ??= Carbon::today();
        $type = $draft->speedDraftDetail?->speedDraftType;
        $graceDays = (int) ($type?->grace_period_days ?? 5);
        $chargeRate = (float) ($type?->default_charge_rate_percent ?? 0);

        return round((float) $draft->schedules()->with('activeAllocations')->get()
            ->filter(fn ($s) => SpeedDraftSummary::isOverdue($s, $graceDays, $asOf))
            ->sum(function ($s) use ($graceDays, $chargeRate, $asOf) {
                $due = SpeedDraftSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf);
                $paid = (float) $s->activeAllocations->sum('penalty_applied');

                return max(0, $due - $paid);
            }), 2);
    }

    /** Everything owed to fully close this draft today: all unpaid interest + penalty + outstanding capital. */
    public static function outstandingBalance(SpeedDraft $draft, ?Carbon $asOf = null): float
    {
        return round(self::outstandingInterest($draft) + self::outstandingPenalty($draft, $asOf) + (float) $draft->outstanding_balance_cache, 2);
    }

    /**
     * Pays down penalty first (oldest overdue row first), then unpaid interest
     * installments oldest-first, up to $amount — the caller validates $amount
     * against outstandingInterest()+outstandingPenalty(). Penalty allocations
     * don't touch a schedule row's own paid_amount/status (those track the
     * interest side only, same as before) — penalty stays live-computed
     * everywhere, so paying it down is reflected purely via the
     * FacilityPaymentAllocation.penalty_applied rows written here.
     */
    public static function applyInterestPayment(SpeedDraft $draft, FacilityPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $remaining = $amount;

        $type = $draft->speedDraftDetail?->speedDraftType;
        $graceDays = (int) ($type?->grace_period_days ?? 5);
        $chargeRate = (float) ($type?->default_charge_rate_percent ?? 0);
        $overdueRows = $draft->schedules()->with('activeAllocations')->orderBy('installment_no')->get()
            ->filter(fn ($s) => SpeedDraftSummary::isOverdue($s, $graceDays, $asOf));

        foreach ($overdueRows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = SpeedDraftSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) - (float) $row->activeAllocations->sum('penalty_applied');
            $due = round(max(0, $due), 2);
            if ($due <= 0) {
                continue;
            }
            $pay = min($remaining, $due);
            FacilityPaymentAllocation::create([
                'facility_payment_id' => $payment->id,
                'facility_schedule_id' => $row->id,
                'penalty_applied' => $pay,
            ]);
            $remaining = round($remaining - $pay, 2);
        }

        $rows = $draft->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')->with('activeAllocations')->get();
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = max(0, round((float) $row->interest_due - (float) $row->activeAllocations->sum('interest_applied'), 2));
            if ($due <= 0) {
                continue;
            }
            $pay = min($remaining, $due);
            FacilityPaymentAllocation::create([
                'facility_payment_id' => $payment->id,
                'facility_schedule_id' => $row->id,
                'interest_applied' => $pay,
                'principal_applied' => 0,
            ]);
            $newPaid = round((float) $row->paid_amount + $pay, 2);
            $row->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid >= (float) $row->interest_due ? 'Paid' : 'Partial',
                'paid_at' => $newPaid >= (float) $row->interest_due ? $asOf : $row->paid_at,
            ]);
            $remaining = round($remaining - $pay, 2);
        }
    }

    /** Reduces outstanding_balance_cache by $amount (the caller validates $amount against it), re-prices future interest, and closes the draft if nothing is left owed. */
    public static function applyCapitalReduction(SpeedDraft $draft, FacilityPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        FacilityPaymentAllocation::create([
            'facility_payment_id' => $payment->id,
            'facility_schedule_id' => null,
            'interest_applied' => 0,
            'principal_applied' => $amount,
        ]);
        $draft->update(['outstanding_balance_cache' => round((float) $draft->outstanding_balance_cache - $amount, 2)]);

        $fresh = $draft->fresh();
        SpeedDraftScheduler::recalculateOpenRows($fresh);

        if ((float) $fresh->outstanding_balance_cache <= 0 && self::outstandingInterest($fresh) <= 0 && self::outstandingPenalty($fresh, $asOf) <= 0) {
            $fresh->update(['status' => 'Closed', 'closed_at' => $asOf ?? now()]);
        }
    }

    /** Pays off penalty, then every unpaid interest installment, then the full remaining capital — closes the draft. $amount must equal outstandingBalance(). */
    public static function applySettlement(SpeedDraft $draft, FacilityPayment $payment, ?Carbon $asOf = null): void
    {
        $interestAndPenalty = round(self::outstandingInterest($draft) + self::outstandingPenalty($draft, $asOf), 2);
        if ($interestAndPenalty > 0) {
            self::applyInterestPayment($draft, $payment, $interestAndPenalty, $asOf);
        }
        $capital = (float) $draft->fresh()->outstanding_balance_cache;
        if ($capital > 0) {
            self::applyCapitalReduction($draft->fresh(), $payment, $capital, $asOf);
        }
    }
}
