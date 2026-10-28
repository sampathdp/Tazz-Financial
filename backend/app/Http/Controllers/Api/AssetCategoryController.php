<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetCategoryController extends Controller
{
    public function index()
    {
        return AssetCategory::with('assetAccount:id,code,name', 'accumulatedDepreciationAccount:id,code,name', 'expenseAccount:id,code,name')
            ->withCount('assets')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $category = AssetCategory::create($this->validated($request));

        return response()->json($category->load('assetAccount:id,code,name'), 201);
    }

    public function update(Request $request, AssetCategory $assetCategory)
    {
        $data = $this->validated($request, $assetCategory);
        // Re-pointing the accounts of a category that already holds assets would split its history across accounts.
        if ($assetCategory->assets()->exists()) {
            unset($data['asset_account_id'], $data['accumulated_depreciation_account_id'], $data['expense_account_id']);
        }
        $assetCategory->update($data);

        return $assetCategory->fresh('assetAccount:id,code,name');
    }

    private function validated(Request $request, ?AssetCategory $category = null): array
    {
        $postable = fn ($type) => Rule::exists('chart_of_accounts', 'id')->where('account_type', $type)->where('is_postable', true)->where('status', 'Active');

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('asset_categories', 'name')->ignore($category?->id)],
            'asset_account_id' => ['required', $postable('Asset')],
            'accumulated_depreciation_account_id' => ['required', $postable('Asset')],
            'expense_account_id' => ['required', $postable('Expense')],
            'default_useful_life_months' => 'required|integer|min:1|max:1200',
            'default_residual_percent' => 'required|numeric|min:0|max:99',
            'status' => 'nullable|in:Active,Inactive',
        ]);
    }
}
