<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanTypeController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    private const INTEREST_METHODS = ['flat', 'reducing_balance'];
    private const RATE_PERIODS = ['monthly', 'annual'];
    private const COLLECTION_DAYS = ['all_days', 'week_days'];
    private const COMMISSION_TYPES = ['fixed', 'percentage'];

    public function index()
    {
        // Flat list, tree assembled client-side — small table, and the UI needs
        // to render/reorder the hierarchy interactively anyway.
        return LoanType::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $loanType = LoanType::create($data);

        return response()->json($loanType, 201);
    }

    public function update(Request $request, LoanType $loanType)
    {
        $data = $this->validated($request, $loanType);
        if (isset($data['parent_id']) && $data['parent_id'] === $loanType->id) {
            return response()->json(['message' => 'A loan type cannot be its own parent.'], 422);
        }
        $loanType->update($data);

        return $loanType;
    }

    public function destroy(LoanType $loanType)
    {
        if ($loanType->children()->exists()) {
            return response()->json(['message' => 'This loan type has sub-items and cannot be deleted. Delete or move them first.'], 422);
        }
        $loanType->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?LoanType $loanType = null): array
    {
        $isProduct = $request->boolean('is_product', true);

        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:loan_types,id'],
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:32', Rule::unique('loan_types', 'code')->ignore($loanType?->id)],
            'reference_format' => 'nullable|string|max:191',
            'is_product' => 'boolean',
            'frequency' => ['nullable', Rule::in(self::FREQUENCIES)],
            'collection_days' => ['nullable', Rule::in(self::COLLECTION_DAYS)],
            'sort_order' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'interest_method' => [$isProduct ? 'required' : 'nullable', Rule::in(self::INTEREST_METHODS)],
            'interest_rate_percent' => [$isProduct ? 'required' : 'nullable', 'numeric', 'min:0'],
            'rate_period' => ['nullable', Rule::in(self::RATE_PERIODS)],
            'min_amount' => [$isProduct ? 'required' : 'nullable', 'numeric', 'min:0'],
            'max_amount' => [$isProduct ? 'required' : 'nullable', 'numeric', 'gte:min_amount'],
            'min_term_months' => [$isProduct ? 'required' : 'nullable', 'integer', 'min:1'],
            'max_term_months' => [$isProduct ? 'required' : 'nullable', 'integer', 'gte:min_term_months'],
            'grace_period_days' => 'nullable|integer|min:0',
            'default_charge_rate_percent' => 'nullable|numeric|min:0',
            'processing_fee_percent' => 'nullable|numeric|min:0',
            'commission_type' => ['nullable', Rule::in(self::COMMISSION_TYPES)],
            'commission_value' => 'nullable|numeric|min:0',
            'min_paid_installments' => 'nullable|integer|min:0',
        ]);

        if (!empty($data['reference_format']) && !str_contains($data['reference_format'], '{SEQ')) {
            throw ValidationException::withMessages([
                'reference_format' => 'The reference format must include a sequence placeholder, e.g. {SEQ:6}.',
            ]);
        }

        // Non-product (category) nodes carry no terms of their own.
        if (!$isProduct) {
            foreach (['frequency', 'interest_method', 'interest_rate_percent', 'min_amount', 'max_amount', 'min_term_months', 'max_term_months'] as $field) {
                $data[$field] = null;
            }
            $data['collection_days'] = 'all_days';
        } else {
            // Only meaningful for daily products — non-daily frequencies always collect on their normal cadence.
            $data['collection_days'] = ($data['frequency'] ?? null) === 'daily' ? ($data['collection_days'] ?? 'all_days') : 'all_days';
        }

        return $data;
    }
}
