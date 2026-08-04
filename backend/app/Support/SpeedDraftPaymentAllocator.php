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
    /** Total unpaid interest across every not-fully-paid schedule row. */
    public static function outstandingInterest(SpeedDraft $draft): float
    {
        return round((float) $draft->schedules()->where('status', '!=', 'Paid')->with('allocations')->get()
            ->sum(fn ($s) => (float) $s->interest_due - (float) $s->allocations->sum('interest_applied')), 2);
    }

    /** Everything owed to fully close this draft today: all unpaid interest + outstanding capital. */
    public static function outstandingBalance(SpeedDraft $draft): float
    {
        return round(self::outstandingInterest($draft) + (float) $draft->outstanding_balance_cache, 2);
    }

    /** Pays unpaid interest installments oldest-first, up to $amount — the caller validates $amount against outstandingInterest(). */
    public static function applyInterestPayment(SpeedDraft $draft, FacilityPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $remaining = $amount;
        $rows = $draft->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')->with('allocations')->get();

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $due = max(0, round((float) $row->interest_due - (float) $row->allocations->sum('interest_applied'), 2));
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

        if ((float) $fresh->outstanding_balance_cache <= 0 && self::outstandingInterest($fresh) <= 0) {
            $fresh->update(['status' => 'Closed', 'closed_at' => $asOf ?? now()]);
        }
    }

    /** Pays off every unpaid interest installment, then the full remaining capital — closes the draft. $amount must equal outstandingBalance(). */
    public static function applySettlement(SpeedDraft $draft, FacilityPayment $payment, ?Carbon $asOf = null): void
    {
        $interest = self::outstandingInterest($draft);
        if ($interest > 0) {
            self::applyInterestPayment($draft, $payment, $interest, $asOf);
        }
        $capital = (float) $draft->fresh()->outstanding_balance_cache;
        if ($capital > 0) {
            self::applyCapitalReduction($draft->fresh(), $payment, $capital, $asOf);
        }
    }
}
