<?php

namespace App\Support;

use App\Models\FixedDeposit;
use Carbon\Carbon;

/**
 * Simple-interest math for Fixed Deposits — no compounding. Mirrors
 * LoanAmortizer's role for loans: computes the interest figures at FD
 * creation/renewal time, and builds the monthly interest-payout schedule.
 *
 * Monthly Interest: the customer is paid every month; only the principal
 * comes back at maturity. Interest at Maturity: interest accrues silently
 * and is paid once, together with the principal, at maturity.
 */
class FdInterestCalculator
{
    /** @return array{monthlyInterestAmount: ?float, totalInterestAmount: float, maturityAmount: float} */
    public static function calculate(float $principal, float $annualRatePercent, int $termMonths, string $interestPaymentMethod): array
    {
        $monthlyRate = $annualRatePercent / 12;
        $monthlyInterest = round($principal * ($monthlyRate / 100), 2);

        if ($interestPaymentMethod === 'monthly') {
            return [
                'monthlyInterestAmount' => $monthlyInterest,
                'totalInterestAmount' => round($monthlyInterest * $termMonths, 2),
                // Interest already paid out monthly — only the principal returns at maturity.
                'maturityAmount' => $principal,
            ];
        }

        $totalInterest = round($principal * ($annualRatePercent / 100) * ($termMonths / 12), 2);

        return [
            'monthlyInterestAmount' => null,
            'totalInterestAmount' => $totalInterest,
            'maturityAmount' => round($principal + $totalInterest, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> rows ready for FdInterestPayment::insert() — monthly method only. */
    public static function generateSchedule(FixedDeposit $fd): array
    {
        $rows = [];
        $date = Carbon::parse($fd->start_date);
        for ($i = 1; $i <= $fd->term_months; $i++) {
            $date = $date->copy()->addMonthNoOverflow();
            $rows[] = [
                'fixed_deposit_id' => $fd->id,
                'installment_no' => $i,
                'due_date' => $date->format('Y-m-d'),
                'amount' => $fd->monthly_interest_amount,
                'paid_amount' => 0,
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
}
