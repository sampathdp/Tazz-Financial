<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PawningType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PawningTypeController extends Controller
{
    public function index()
    {
        return PawningType::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $pawningType = PawningType::create($data);

        return response()->json($pawningType, 201);
    }

    public function update(Request $request, PawningType $pawningType)
    {
        $data = $this->validated($request, $pawningType);
        $pawningType->update($data);

        return $pawningType;
    }

    public function destroy(PawningType $pawningType)
    {
        $pawningType->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?PawningType $pawningType = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:24', Rule::unique('pawning_types', 'code')->ignore($pawningType?->id)],
            'normal_rate_percent' => 'required|numeric|min:0|max:999',
            'special_rate_percent' => 'nullable|numeric|min:0|max:999',
            'special_rate_days' => 'nullable|integer|min:1',
            'cycle_days' => 'required|integer|min:1',
            'ltv_percent' => 'required|numeric|min:0.01|max:100',
            'max_renewal_count' => 'nullable|integer|min:1',
            'grace_period_days' => 'nullable|integer|min:0',
            'auction_eligible_days' => 'required|integer|min:0',
            'auction_notice_days' => 'required|integer|min:0',
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
        ]);

        if (isset($data['special_rate_percent']) && $data['special_rate_percent'] > $data['normal_rate_percent']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'special_rate_percent' => 'The special rate must not be higher than the normal rate.',
            ]);
        }

        return $data;
    }
}
