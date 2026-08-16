<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpeedDraftType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpeedDraftTypeController extends Controller
{
    private const CATEGORIES = ['Loan', 'Mortgage', 'GoldPawning', 'Lease'];
    private const RATE_PERIODS = ['monthly', 'annual', 'daily'];

    public function index()
    {
        return SpeedDraftType::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $type = SpeedDraftType::create($data);

        return response()->json($type, 201);
    }

    public function update(Request $request, SpeedDraftType $speedDraftType)
    {
        $data = $this->validated($request, $speedDraftType);
        $speedDraftType->update($data);

        return $speedDraftType;
    }

    public function destroy(SpeedDraftType $speedDraftType)
    {
        if ($speedDraftType->facilityDetails()->exists()) {
            return response()->json(['message' => 'This Speed Draft Type has drafts issued against it and cannot be deleted.'], 422);
        }
        $speedDraftType->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?SpeedDraftType $type = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:24', Rule::unique('speed_draft_types', 'code')->ignore($type?->id)],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'reference_format' => 'nullable|string|max:64',
            'interest_rate_percent' => 'required|numeric|min:0',
            'rate_period' => ['nullable', Rule::in(self::RATE_PERIODS)],
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|gte:min_amount',
            'min_period_months' => 'nullable|integer|min:1',
            'max_period_months' => 'nullable|integer|gte:min_period_months',
            'grace_period_days' => 'nullable|integer|min:0',
            'requires_collateral' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);

        if (!empty($data['reference_format']) && !str_contains($data['reference_format'], '{SEQ')) {
            throw ValidationException::withMessages([
                'reference_format' => 'The reference format must include a sequence placeholder, e.g. {SEQ:6}.',
            ]);
        }

        return $data;
    }
}
