<?php

namespace App\Support;

use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Speed Draft waivers (Interest / Penalty / Charge / Principal), netted the same way loans do it
 * (see LoanPaymentAllocator::waivedByRow): each waiver type is a pool that is spread oldest-first
 * across what is still owed on the schedule rows / charges after real payments, so it is never
 * persisted per row and stays correct as payments land or are voided.
 * Principal waivers are different: they reduce outstanding_balance_cache directly (see SpeedDraftWaiverController).
 */
class SpeedDraftWaivers
{
    /** @return array{interest: float, penalty: float, charge: float, principal: float} */
    public static function pools(SpeedDraft $draft): array
    {
        $by = $draft->waivers()->get()->groupBy('waiver_type');

        return [
            'interest' => (float) ($by->get('Interest')?->sum('amount') ?? 0),
            'penalty' => (float) ($by->get('Penalty')?->sum('amount') ?? 0),
            'charge' => (float) ($by->get('Charge')?->sum('amount') ?? 0),
            'principal' => (float) ($by->get('Principal')?->sum('amount') ?? 0),
        ];
    }

    /**
     * Waived interest / penalty per schedule row id, each capped at what is still owed there.
     *
     * @return array{interest: array<int,float>, penalty: array<int,float>}
     */
    public static function byRow(SpeedDraft $draft, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $pool = self::pools($draft);
        $out = ['interest' => [], 'penalty' => []];
        if ($pool['interest'] <= 0 && $pool['penalty'] <= 0) {
            return $out;
        }

        $type = $draft->speedDraftDetail?->speedDraftType;
        $graceDays = (int) ($type?->grace_period_days ?? 5);
        $chargeRate = (float) ($type?->default_charge_rate_percent ?? 0);

        foreach ($draft->schedules()->with('activeAllocations')->get() as $row) {
            if ($pool['interest'] > 0) {
                $due = max(0, round((float) $row->interest_due - (float) $row->activeAllocations->sum('interest_applied'), 2));
                if ($due > 0) {
                    $take = round(min($pool['interest'], $due), 2);
                    $out['interest'][$row->id] = $take;
                    $pool['interest'] = round($pool['interest'] - $take, 2);
                }
            }
            if ($pool['penalty'] > 0 && SpeedDraftSummary::isOverdue($row, $graceDays, $asOf)) {
                $gross = SpeedDraftSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) - (float) $row->activeAllocations->sum('penalty_applied');
                $gross = round(max(0, $gross), 2);
                if ($gross > 0) {
                    $take = round(min($pool['penalty'], $gross), 2);
                    $out['penalty'][$row->id] = $take;
                    $pool['penalty'] = round($pool['penalty'] - $take, 2);
                }
            }
        }

        return $out;
    }

    /** Waived amount per charge id, each capped at what is still unpaid on that charge. @return array<int,float> */
    public static function byCharge(SpeedDraft $draft): array
    {
        $pool = self::pools($draft)['charge'];
        $out = [];
        if ($pool <= 0) {
            return $out;
        }
        foreach ($draft->charges()->orderBy('charged_at')->orderBy('id')->get() as $charge) {
            $due = max(0, round((float) $charge->due_amount - (float) $charge->paid_amount, 2));
            if ($due > 0 && $pool > 0) {
                $take = round(min($pool, $due), 2);
                $out[$charge->id] = $take;
                $pool = round($pool - $take, 2);
            }
        }

        return $out;
    }

    /**
     * Re-derives every installment's Pending/Partial/Paid from what was paid plus what was waived,
     * so an installment forgiven in full by a waiver stops showing as unpaid/overdue (and one whose
     * waiver was removed comes back). Idempotent; call after any payment, void or waiver change.
     */
    public static function syncRowStatuses(SpeedDraft $draft, ?Carbon $asOf = null): void
    {
        $waived = self::byRow($draft)['interest'];
        foreach ($draft->schedules()->get() as $row) {
            $paid = (float) $row->paid_amount;
            $settled = $paid + (float) ($waived[$row->id] ?? 0);
            $status = $settled >= (float) $row->interest_due - 0.005 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Pending');
            if ($status !== $row->status) {
                $row->update([
                    'status' => $status,
                    'paid_at' => $status === 'Paid' ? ($row->paid_at ?? ($asOf ?? now())) : ($status === 'Pending' ? null : $row->paid_at),
                ]);
            }
        }
    }
}
