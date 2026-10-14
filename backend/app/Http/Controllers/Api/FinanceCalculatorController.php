<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FinanceCalculationService;
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
}
