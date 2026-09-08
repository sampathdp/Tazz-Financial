<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavingsProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavingsProductController extends Controller
{
    private const CALC_METHODS = ['min_monthly_balance', 'daily_balance', 'average_balance'];

    private const FREQUENCIES = ['monthly', 'quarterly', 'annually'];

    public function index()
    {
        return SavingsProduct::withCount('accounts')->orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $product = SavingsProduct::create($this->validated($request));

        return response()->json($product, 201);
    }

    public function update(Request $request, SavingsProduct $savingsProduct)
    {
        $savingsProduct->update($this->validated($request, $savingsProduct));

        return $savingsProduct;
    }

    public function destroy(SavingsProduct $savingsProduct)
    {
        if ($savingsProduct->accounts()->exists()) {
            return response()->json(['message' => 'This product has savings accounts opened against it and cannot be deleted.'], 422);
        }
        $savingsProduct->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?SavingsProduct $product = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:24', Rule::unique('savings_products', 'code')->ignore($product?->id)],
            'interest_rate_percent' => 'required|numeric|min:0|max:999',
            'interest_calc_method' => ['required', Rule::in(self::CALC_METHODS)],
            'interest_posting_frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'min_opening_deposit' => 'nullable|numeric|min:0',
            'min_balance' => 'nullable|numeric|min:0',
            'withdrawal_fee' => 'nullable|numeric|min:0',
            'ledger_fee' => 'nullable|numeric|min:0',
            'dormancy_days' => 'nullable|integer|min:0|max:3650',
            'reference_format' => 'nullable|string|max:64',
            'sort_order' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);

        if (!empty($data['reference_format']) && !str_contains($data['reference_format'], '{SEQ')) {
            throw ValidationException::withMessages([
                'reference_format' => 'The reference format must include a sequence placeholder, e.g. {SEQ:6}.',
            ]);
        }
        if (($data['min_balance'] ?? 0) > 0 && ($data['min_opening_deposit'] ?? 0) > 0 && $data['min_balance'] > $data['min_opening_deposit']) {
            throw ValidationException::withMessages([
                'min_balance' => 'The minimum balance cannot exceed the minimum opening deposit.',
            ]);
        }

        return $data;
    }
}
