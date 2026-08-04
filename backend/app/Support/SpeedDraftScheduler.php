<?php

namespace App\Support;

use App\Models\SpeedDraft;
use Carbon\Carbon;

/**
 * Builds and re-prices a Speed Draft's interest-only schedule. Every
 * installment collects interest on the outstanding capital only — capital is
 * never amortized into installments (see SpeedDraftPaymentAllocator for how
 * capital is actually reduced/settled). `principal_due` (facility_schedules'
 * shared column for a loan's amortized principal — see that migration) is
 * populated only on the final (maturity) row here, purely as a live preview
 * of what would be owed if nothing further is paid before then — it never
 * feeds into a row's paid_amount/status, which track the interest side only
 * (see FacilitySchedule / SpeedDraftPaymentAllocator::applyInterestPayment).
 */
class SpeedDraftScheduler
{
    /** @return array<int, array<string, mixed>> rows ready for FacilitySchedule::insert() */
    public static function generate(SpeedDraft $draft): array
    {
        $n = $draft->term_months;
        $monthlyRate = self::monthlyRate($draft);
        $capital = (float) $draft->outstanding_balance_cache;

        $date = Carbon::parse($draft->start_date);
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $date = $date->copy()->addMonthNoOverflow();
            $interest = round($capital * ($monthlyRate / 100), 2);
            $capitalDue = $i === $n ? $capital : 0.0;
            $rows[] = [
                'credit_facility_id' => $draft->id,
                'installment_no' => $i,
                'due_date' => $date->format('Y-m-d'),
                'interest_due' => $interest,
                'principal_due' => $capitalDue,
                'total_due' => round($interest + $capitalDue, 2),
                'paid_amount' => 0,
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * Re-prices every not-yet-touched installment's interest against the
     * current outstanding_balance_cache, and refreshes the final row's
     * principal_due preview — called after every capital reduction (see
     * SpeedDraftPaymentAllocator::applyCapitalReduction()) so future interest
     * always reflects what's actually still owed. Rows already Paid or
     * Partial are historical fact and left untouched.
     */
    public static function recalculateOpenRows(SpeedDraft $draft): void
    {
        $monthlyRate = self::monthlyRate($draft);
        $capital = (float) $draft->outstanding_balance_cache;
        $lastInstallmentNo = (int) $draft->schedules()->max('installment_no');

        $draft->schedules()->where('status', 'Pending')->get()
            ->each(function ($row) use ($monthlyRate, $capital, $lastInstallmentNo) {
                $interest = round($capital * ($monthlyRate / 100), 2);
                $capitalDue = $row->installment_no === $lastInstallmentNo ? $capital : 0.0;
                $row->update([
                    'interest_due' => $interest,
                    'principal_due' => $capitalDue,
                    'total_due' => round($interest + $capitalDue, 2),
                ]);
            });
    }

    private static function monthlyRate(SpeedDraft $draft): float
    {
        return $draft->rate_period === 'annual'
            ? ((float) $draft->interest_rate_percent) / 12
            : (float) $draft->interest_rate_percent;
    }
}
