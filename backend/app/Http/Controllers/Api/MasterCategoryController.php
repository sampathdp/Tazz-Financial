<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MasterCategoryController extends Controller
{
    /**
     * Whitelist of lookup lists this endpoint serves, keyed by the `type`
     * value stored on each row. Add an entry here to expose a new master
     * list — no migration or route change needed, just a new frontend
     * screen instance pointed at the new key.
     */
    private const TYPES = [
        'expense_category' => 'Expense Category',
        'bank_account_type' => 'Bank Account Type',
    ];

    public function index(Request $request)
    {
        $type = $this->validatedType($request);

        return MasterCategory::where('type', $type)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = MasterCategory::create($data);

        return response()->json($category, 201);
    }

    public function update(Request $request, MasterCategory $masterCategory)
    {
        $data = $this->validated($request, $masterCategory);
        $masterCategory->update($data);

        return $masterCategory;
    }

    public function destroy(MasterCategory $masterCategory)
    {
        $masterCategory->delete();

        return response()->noContent();
    }

    private function validatedType(Request $request): string
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(self::TYPES))],
        ])['type'];
    }

    private function validated(Request $request, ?MasterCategory $masterCategory = null): array
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(self::TYPES))],
            'name' => [
                'required', 'string', 'max:128',
                Rule::unique('master_categories', 'name')
                    ->where('type', $request->input('type'))
                    ->ignore($masterCategory?->id),
            ],
            'code' => 'nullable|string|max:32',
            'description' => 'nullable|string|max:255',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
        ]);
    }
}
