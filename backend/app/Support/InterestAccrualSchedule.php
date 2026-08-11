<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Day-count math shared by AccrualInterestCalculator (splitting one
 * installment period's interest across the calendar months it spans) and
 * LoanPaymentAllocator::settlementQuote() (how much of a period's interest
 * has accrued by a given date, for early-settlement waiving). Pure
 * functions, no DB access, so both callers stay in exact agreement.
 *
 * Elapsed-day counting (Carbon::diffInDays, not an inclusive calendar count)
 * throughout — a loan disbursed July 10th accrues 21 elapsed days in July
 * (July 10 -> July 31) and 9 in August (July 31 -> Aug 9), matching the
 * client's own worked example for a 30-day cycle.
 */
class InterestAccrualSchedule
{
    /**
     * Splits $amount proportionally across the calendar days of
     * [$start, $end), one slice per calendar month it touches. A slice's
     * date is the earlier of $end and the last day of its month — so
     * July 10 -> Aug 9 yields a July 31 slice and an Aug 9 slice, matching
     * the spec's "recognize at end of each respective month".
     *
     * @return array<int, array{date: Carbon, amount: float}>
     */
    public static function monthlySlices(Carbon $start, Carbon $end, float $amount): array
    {
        if ($amount == 0.0) {
            return [];
        }
        [$start, $end] = self::normalize($start, $end);
        $totalDays = $start->diffInDays($end);

        $raw = [];
        $cursor = $start->copy();
        while ($cursor->lessThan($end)) {
            // Measured from a point just past $cursor so a cursor sitting
            // exactly on a month's last day (the end of the previous slice)
            // resolves to the *next* month's end, not a zero-day slice.
            $monthEnd = $cursor->copy()->addDay()->endOfMonth()->startOfDay();
            $sliceEnd = $monthEnd->lessThan($end) ? $monthEnd : $end;
            $raw[] = ['date' => $sliceEnd->copy(), 'days' => $cursor->diffInDays($sliceEnd)];
            $cursor = $sliceEnd;
        }

        $out = [];
        $allocated = 0.0;
        foreach ($raw as $i => $slice) {
            // Last slice absorbs rounding drift so slices always sum to $amount exactly.
            $amt = $i === count($raw) - 1
                ? round($amount - $allocated, 2)
                : round($amount * ($slice['days'] / $totalDays), 2);
            $allocated = round($allocated + $amt, 2);
            $out[] = ['date' => $slice['date'], 'amount' => $amt];
        }

        return $out;
    }

    /** Portion of $amount accrued by $asOf within period [$start, $end). */
    public static function accruedThrough(Carbon $start, Carbon $end, float $amount, Carbon $asOf): float
    {
        [$start, $end] = self::normalize($start, $end);
        $asOf = $asOf->copy()->startOfDay();
        if (!$asOf->greaterThan($start)) {
            return 0.0;
        }
        if (!$asOf->lessThan($end)) {
            return round($amount, 2);
        }

        return round($amount * ($start->diffInDays($asOf) / $start->diffInDays($end)), 2);
    }

    /** A period that starts and ends the same calendar day (daily-frequency
     *  loans, whose installments are normally exactly one day apart, or a
     *  first installment dated the same day as disbursement) has no days to
     *  prorate by — treat it as a single whole day so the division above
     *  never hits zero. */
    private static function normalize(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($start->equalTo($end)) {
            $end = $end->copy()->addDay();
        }

        return [$start, $end];
    }
}
