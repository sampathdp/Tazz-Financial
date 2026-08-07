<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanFacilityDetail;
use App\Models\LoanType;

/**
 * Single reusable finance-quote engine — used by the standalone Leasing/Loan
 * Calculator (and, by construction, safe to reuse from anywhere else that
 * needs a preview) without ever touching the database. Delegates the actual
 * amortization to LoanAmortizer/AerCalculator, the same classes
 * LoanController::disburse() calls at real disbursement time, via transient
 * (unsaved) Loan/LoanFacilityDetail instances — this is what guarantees a
 * quote and the eventual disbursed loan can never drift apart.
 *
 * Down payment and "finance this fee" charges are folded into principal_amount
 * up front (with down_payment passed to LoanAmortizer as 0) rather than left
 * for LoanAmortizer to net out itself, so the finance amount this service
 * reports is exactly what gets amortized — matching the spec's own example
 * (loan amount + financed fees = the amount rental is calculated on).
 */
class FinanceCalculationService
{
    /** @param array<string, mixed> $data */
    public static function quote(array $data): array
    {
        $loanAmount = (float) $data['loan_amount'];
        $downPayment = (float) ($data['down_payment'] ?? 0);
        $termMonths = (int) $data['term_months'];
        $interestMethod = $data['interest_method'];
        $interestRatePercent = (float) $data['interest_rate_percent'];
        $ratePeriod = $data['rate_period'] ?? 'monthly';
        $installmentFrequency = $data['installment_frequency'] ?? 'monthly';
        $firstInstallmentDate = $data['first_installment_date'] ?? now()->toDateString();
        $charges = $data['charges'] ?? [];

        $financedTotal = 0.0;
        $separateTotal = 0.0;
        foreach ($charges as $charge) {
            $amount = (float) ($charge['amount'] ?? 0);
            if (!empty($charge['financed'])) {
                $financedTotal += $amount;
            } else {
                $separateTotal += $amount;
            }
        }

        $financeAmount = round($loanAmount - $downPayment + $financedTotal, 2);

        $loan = new Loan([
            'principal_amount' => $financeAmount,
            'interest_rate_percent' => $interestRatePercent,
            'rate_period' => $ratePeriod,
            'term_months' => $termMonths,
        ]);
        $detail = new LoanFacilityDetail([
            // Set even though $detail is never saved: LoanAmortizer::generate() does
            // $detail->loadMissing('loanType') to read collection_days (Mon-Fri-only
            // due dates for Daily products) — that only resolves if loan_type_id is
            // present, since Eloquent relations key off the attribute, not persistence.
            'loan_type_id' => $data['loan_type_id'] ?? null,
            'down_payment' => 0,
            'interest_method' => $interestMethod,
            'installment_frequency' => $installmentFrequency,
        ]);

        $rows = LoanAmortizer::generate($loan, $detail, $firstInstallmentDate);

        $monthlyRental = $rows[0]['total_due'] ?? 0.0;
        $totalInterest = round(array_sum(array_column($rows, 'interest_due')), 2);
        $totalPayable = round($financeAmount + $totalInterest, 2);

        $balance = $financeAmount;
        $schedule = [];
        foreach ($rows as $row) {
            $balance = round($balance - $row['principal_due'], 2);
            $schedule[] = [
                'installment_no' => $row['installment_no'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'balance' => max($balance, 0),
            ];
        }

        $effectiveMonthlyRate = null;
        $effectiveAnnualRate = null;
        if ($interestMethod === 'flat') {
            $weekdaysOnly = $installmentFrequency === 'daily'
                && ($data['loan_type_id'] ?? null)
                && LoanType::whereKey($data['loan_type_id'])->value('collection_days') === 'week_days';
            $periodsPerMonth = LoanAmortizer::periodsPerMonth($installmentFrequency, $weekdaysOnly);
            $aer = AerCalculator::calculate($financeAmount, $monthlyRental, count($rows), $periodsPerMonth);
            if ($aer) {
                $effectiveMonthlyRate = round($aer['monthly'], 4);
                $effectiveAnnualRate = round($aer['annual'], 4);
            }
        }

        return [
            'loan_amount' => $loanAmount,
            'down_payment' => $downPayment,
            'financed_charges_total' => round($financedTotal, 2),
            'separate_charges_total' => round($separateTotal, 2),
            'finance_amount' => $financeAmount,
            'customer_upfront_payment' => round($downPayment + $separateTotal, 2),
            'interest_method' => $interestMethod,
            'rate_period' => $ratePeriod,
            'entered_rate' => $interestRatePercent,
            'effective_monthly_rate' => $effectiveMonthlyRate,
            'effective_annual_rate' => $effectiveAnnualRate,
            'term_months' => $termMonths,
            'installment_frequency' => $installmentFrequency,
            'monthly_rental' => $monthlyRental,
            'total_interest' => $totalInterest,
            'total_payable' => $totalPayable,
            'schedule' => $schedule,
        ];
    }
}
