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
        return ExpenseCategory::with('chartOfAccount:id,code,name', 'parent:id,name')->orderBy('sort_order')->orderBy('name')->get();
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
            'code' => ['required', 'string', 'max:24', Rule::unique('expense_categories', 'code')->ignore($expenseCategory?->id)],
            'description' => 'nullable|string|max:255',
            // Which chart-of-accounts expense account this category's spend posts
            // to (see LedgerPoster) — required before any expense in this
            // category can be paid, but not at category-creation time, since
            // the Masters screen and the Chart of Accounts are managed separately.
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
            // Two levels only: the parent must itself be a main category, and never the row itself.
            'parent_id' => ['nullable', Rule::exists('expense_categories', 'id')->whereNull('parent_id'), Rule::notIn([$expenseCategory?->id])],
        ]);
    }
}
