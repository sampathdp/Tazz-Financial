<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return ExpenseCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = ExpenseCategory::create($data);

        return response()->json($category, 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $this->validated($request, $expenseCategory);
        $expenseCategory->update($data);

        return $expenseCategory;
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $expenseCategory->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?ExpenseCategory $expenseCategory = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:128'],
            // `code` is the table's unique key (not `name`) — it predates this
            // controller (see schema.sql, spec 3.9) and already enforces this.
            'code' => ['required', 'string', 'max:32', Rule::unique('expense_categories', 'code')->ignore($expenseCategory?->id)],
            'description' => 'nullable|string|max:255',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
        ]);
    }
}
