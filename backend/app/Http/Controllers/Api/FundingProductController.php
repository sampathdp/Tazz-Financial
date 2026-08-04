<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FundingProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FundingProductController extends Controller
{
    private const INTEREST_METHODS = ['monthly', 'maturity'];

    public function index()
    {
        return FundingProduct::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = FundingProduct::create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, FundingProduct $fundingProduct)
    {
        $data = $this->validated($request, $fundingProduct);
        $fundingProduct->update($data);

        return $fundingProduct;
    }

    public function destroy(FundingProduct $fundingProduct)
    {
        if ($fundingProduct->fixedDeposits()->exists()) {
            return response()->json(['message' => 'This product has Fixed Deposits issued against it and cannot be deleted.'], 422);
        }
        $fundingProduct->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?FundingProduct $product = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:24', Rule::unique('funding_products', 'code')->ignore($product?->id)],
            'interest_method' => ['required', Rule::in(self::INTEREST_METHODS)],
            'annual_rate_percent' => 'required|numeric|min:0|max:999',
            'monthly_rate_percent' => 'nullable|numeric|min:0|max:999',
            'rate_is_manual' => 'boolean',
            'allowed_terms_months' => 'required|array|min:1',
            'allowed_terms_months.*' => 'integer|min:1',
            'allow_custom_term' => 'boolean',
            'min_deposit_amount' => 'required|numeric|min:0',
            'max_deposit_amount' => 'nullable|numeric|gte:min_deposit_amount',
            'reference_format' => 'nullable|string|max:64',
            'sort_order' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);

        if (!empty($data['reference_format']) && !str_contains($data['reference_format'], '{SEQ')) {
            throw ValidationException::withMessages([
                'reference_format' => 'The reference format must include a sequence placeholder, e.g. {SEQ:6}.',
            ]);
        }

        // The monthly rate always tracks the annual rate unless the operator
        // explicitly wants to configure it independently (rate_is_manual).
        $data['monthly_rate_percent'] = !empty($data['rate_is_manual']) && $data['monthly_rate_percent'] !== null
            ? $data['monthly_rate_percent']
            : round($data['annual_rate_percent'] / 12, 3);

        return $data;
    }
}
