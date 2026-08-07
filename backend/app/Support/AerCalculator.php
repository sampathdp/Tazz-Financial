<?php

namespace App\Support;

/**
 * Back-solves the equivalent reducing-balance rate implied by a Flat Rate
 * loan's generated EMI, then expresses it as a monthly and an Annual
 * Effective Rate (AER) — informational only, never fed back into pricing.
 * The annuity formula (installment = P*r*(1+r)^n / ((1+r)^n - 1), same one
 * LoanAmortizer::reducingBalance() prices with) has no closed-form inverse
 * for r, so this solves it numerically via bisection: the function is
 * monotonically increasing in r for r > 0, so a root, once bracketed, is
 * guaranteed to converge without derivative/overshoot risk (unlike
 * Newton-Raphson).
 */
class AerCalculator
{
    /**
     * @param float $principal amount actually amortized (matches what LoanAmortizer priced the schedule against)
     * @param float $periodicRental the flat schedule's per-installment total_due, treated as the EMI
     * @param int $installmentCount number of installments in the generated schedule
     * @param float $periodsPerMonth periods per month for the loan's frequency (1 monthly, 4 weekly, 30 daily)
     * @return array{monthly: float, annual: float}|null percentages, or null if inputs are invalid or no root converges
     */
    public static function calculate(float $principal, float $periodicRental, int $installmentCount, float $periodsPerMonth): ?array
    {
        if ($principal <= 0 || $periodicRental <= 0 || $installmentCount < 1) {
            return null;
        }

        $f = fn (float $r) => $r > 0
            ? $principal * $r * (1 + $r) ** $installmentCount / (((1 + $r) ** $installmentCount) - 1) - $periodicRental
            : $principal / $installmentCount - $periodicRental;

        if ($f(0) >= 0) {
            // Rental doesn't exceed a straight-line principal split — not a valid flat-rate EMI to invert.
            return null;
        }

        $lo = 0.0;
        $hi = 1.0;
        for ($i = 0; $i < 60 && $f($hi) < 0; $i++) {
            $hi *= 2;
        }
        if ($f($hi) < 0) {
            // Failed to bracket a root within a sane range — bail out rather than guess.
            return null;
        }

        $mid = $lo;
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2;
            $fm = $f($mid);
            if (abs($fm) < 0.0000001 || ($hi - $lo) < 0.0000001) {
                break;
            }
            if ($fm > 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        $monthlyRate = (1 + $mid) ** $periodsPerMonth - 1;
        $annualRate = (1 + $monthlyRate) ** 12 - 1;

        return ['monthly' => $monthlyRate * 100, 'annual' => $annualRate * 100];
    }
}
