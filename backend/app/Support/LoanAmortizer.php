<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanFacilityDetail;
use Carbon\Carbon;

/**
 * Builds the installment schedule for a loan at disbursement time. Kept
 * intentionally simple: daily/weekly frequencies use calendar-day/7-day steps
 * (no holiday calendar), and reducing-balance uses a standard annuity formula
 * with the loan's monthly rate divided evenly across the periods in a month.
 * $detail is the loan's loanDetail row, passed explicitly by the caller
 * (down_payment/interest_method/installment_frequency live there, not on
 * Loan itself) rather than lazy-loaded here.
 */
class LoanAmortizer
{
    /** @return array<int, array<string, mixed>> rows ready for FacilitySchedule::insert() */
    public static function generate(Loan $loan, LoanFacilityDetail $detail, string $firstInstallmentDate): array
    {
        $detail->loadMissing('loanType');
        $frequency = $detail->installment_frequency;
        // Daily products can be set to collect Mon-Fri only — 5 installments/week,
        // ~20/month rather than 30, so periodsPerMonth (and therefore both the
        // installment count and the per-period rate/amount) reflects business days.
        $weekdaysOnly = $frequency === 'daily' && $detail->loanType?->collection_days === 'week_days';
        $periodsPerMonth = self::periodsPerMonth($frequency, $weekdaysOnly);
        $n = max(1, (int) round($loan->term_months * $periodsPerMonth));

        $monthlyRate = $loan->rate_period === 'annual'
            ? ((float) $loan->interest_rate_percent) / 12
            : (float) $loan->interest_rate_percent;
        $periodRate = ($monthlyRate / 100) / $periodsPerMonth;

        // The schedule amortizes what's actually financed — the down payment
        // is settled upfront and never part of the installment plan.
        $principal = (float) $loan->principal_amount - (float) $detail->down_payment;
        $rows = $detail->interest_method === 'reducing_balance'
            ? self::reducingBalance($principal, $periodRate, $n)
            : self::flat($principal, $monthlyRate, $loan->term_months, $n);

        $date = self::skipWeekend(Carbon::parse($firstInstallmentDate), $weekdaysOnly);
        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = [
                'credit_facility_id' => $loan->id,
                'installment_no' => $i + 1,
                'due_date' => $date->copy()->format('Y-m-d'),
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => round($row['principal'] + $row['interest'], 2),
                'paid_amount' => 0,
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $date = self::skipWeekend(self::step($date, $frequency), $weekdaysOnly);
        }

        return $out;
    }

    /** Advances `$date` by one installment period — used to push a skipped
     *  installment to the back of the queue (LoanController::skipInstallment()). */
    public static function nextDueDate(Carbon $date, string $frequency): Carbon
    {
        return self::step($date, $frequency);
    }

    private static function skipWeekend(Carbon $date, bool $weekdaysOnly): Carbon
    {
        while ($weekdaysOnly && $date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    /** Public so LoanController::applyEffectiveRate() and FinanceCalculationService
     *  can convert a period rate/count to a monthly basis using the exact same
     *  business-days assumption this class amortizes with — one source of truth. */
    public static function periodsPerMonth(string $frequency, bool $weekdaysOnly = false): float
    {
        return match ($frequency) {
            'daily' => $weekdaysOnly ? 20.0 : 30.0,
            'weekly' => 4.0,
            default => 1.0,
        };
    }

    private static function step(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    /** Equal principal per installment; total interest spread evenly. Last row absorbs rounding drift. */
    private static function flat(float $principal, float $monthlyRate, int $termMonths, int $n): array
    {
        $totalInterest = $principal * ($monthlyRate / 100) * $termMonths;
        $principalPer = round($principal / $n, 2);
        $interestPer = round($totalInterest / $n, 2);

        $rows = [];
        $principalPaid = 0.0;
        $interestPaid = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $isLast = $i === $n - 1;
            $p = $isLast ? round($principal - $principalPaid, 2) : $principalPer;
            $int = $isLast ? round($totalInterest - $interestPaid, 2) : $interestPer;
            $rows[] = ['principal' => $p, 'interest' => $int];
            $principalPaid += $p;
            $interestPaid += $int;
        }

        return $rows;
    }

    /** Standard amortizing annuity: equal total installment, interest on the declining balance. */
    private static function reducingBalance(float $principal, float $periodRate, int $n): array
    {
        $installment = $periodRate > 0
            ? $principal * $periodRate * (1 + $periodRate) ** $n / (((1 + $periodRate) ** $n) - 1)
            : $principal / $n;

        $rows = [];
        $balance = $principal;
        for ($i = 0; $i < $n; $i++) {
            $isLast = $i === $n - 1;
            $interest = round($balance * $periodRate, 2);
            $principalPortion = $isLast ? round($balance, 2) : round($installment - $interest, 2);
            $rows[] = ['principal' => $principalPortion, 'interest' => $interest];
            $balance = round($balance - $principalPortion, 2);
        }

        return $rows;
    }
}
