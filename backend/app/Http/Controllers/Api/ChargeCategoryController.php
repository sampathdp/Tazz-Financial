<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargeCategory;
use Illuminate\Http\Request;

class ChargeCategoryController extends Controller
{
    public function index()
    {
        return ChargeCategory::with('chartOfAccount:id,code,name')->orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:charge_categories',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
            'status' => 'required|in:Active,Inactive',
            'sort_order' => 'integer'
        ]);

        $category = ChargeCategory::create($data);
        return response()->json($category->load('chartOfAccount:id,code,name'), 201);
    }

    public function update(Request $request, ChargeCategory $chargeCategory)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:charge_categories,name,' . $chargeCategory->id,
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
            'status' => 'required|in:Active,Inactive',
            'sort_order' => 'integer'
        ]);

        $chargeCategory->update($data);
        return response()->json($chargeCategory->load('chartOfAccount:id,code,name'));
    }

    public function destroy(ChargeCategory $chargeCategory)
    {
        $chargeCategory->delete();
        return response()->noContent();
    }
}
