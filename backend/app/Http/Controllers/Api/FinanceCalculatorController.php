<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpeedDraft;
use App\Support\FinanceCalculationService;
use App\Support\SpeedDraftScheduler;
use Illuminate\Http\Request;

/**
 * Standalone loan/leasing quote endpoint — never writes to the database.
 * Delegates every figure to FinanceCalculationService, which reuses the same
 * LoanAmortizer/AerCalculator classes LoanController::disburse() calls, so a
 * quote here always matches the real disbursed schedule.
 */
class FinanceCalculatorController extends Controller
{
    public function calculate(Request $request)
    {
        $data = $request->validate([
            'loan_type_id' => 'nullable|integer|exists:loan_types,id',
            'loan_amount' => 'required|numeric|min:0.01',
            'down_payment' => ['nullable', 'numeric', 'min:0', 'lte:loan_amount', new \App\Rules\Money],
            'term_months' => 'required|integer|min:1',
            'interest_method' => 'required|in:flat,reducing_balance',
            'interest_rate_percent' => 'required|numeric|min:0.01',
            'rate_period' => 'nullable|in:monthly,annual',
            'installment_frequency' => 'nullable|in:daily,weekly,monthly',
            'first_installment_date' => 'nullable|date',
            'charges' => 'nullable|array',
            'charges.*.label' => 'nullable|string|max:255',
            'charges.*.amount' => 'required_with:charges.*.label|numeric|min:0',
            'charges.*.financed' => 'nullable|boolean',
        ], [
            'down_payment.lte' => 'Down payment cannot exceed the loan amount.',
        ]);

        return response()->json(FinanceCalculationService::quote($data));
    }

    /**
     * Speed Draft quote: interest-only schedule built by the same
     * SpeedDraftScheduler a real draft uses, on a transient (unsaved) model.
     */
    public function speedDraft(Request $request)
    {
        $data = $request->validate([
            'principal_amount' => 'required|numeric|min:0.01',
            'term_months' => 'required|integer|min:1|max:600',
            'interest_rate_percent' => 'required|numeric|min:0.01',
            'rate_period' => 'nullable|in:monthly,annual,daily',
            'start_date' => 'nullable|date',
        ]);

        $principal = round((float) $data['principal_amount'], 2);
        $start = $data['start_date'] ?? now()->toDateString();
        $draft = (new SpeedDraft)->forceFill([
            'term_months' => (int) $data['term_months'],
            'outstanding_balance_cache' => $principal,
            'start_date' => $start,
            'rate_period' => $data['rate_period'] ?? 'monthly',
            'interest_rate_percent' => (float) $data['interest_rate_percent'],
        ]);

        $rows = SpeedDraftScheduler::generate($draft);
        $schedule = array_map(fn ($r) => [
            'installment_no' => $r['installment_no'],
            'due_date' => $r['due_date'],
            'interest_due' => $r['interest_due'],
            'principal_due' => $r['principal_due'],
            'total_due' => $r['total_due'],
        ], $rows);
        $totalInterest = round(array_sum(array_column($rows, 'interest_due')), 2);

        return response()->json([
            'principal_amount' => $principal,
            'term_months' => (int) $data['term_months'],
            'rate_period' => $draft->rate_period,
            'entered_rate' => (float) $data['interest_rate_percent'],
            'start_date' => $start,
            'first_installment_interest' => $rows[0]['interest_due'] ?? 0,
            'total_interest' => $totalInterest,
            'maturity_payable' => round($principal + $totalInterest, 2),
            'schedule' => $schedule,
        ]);
    }
}
