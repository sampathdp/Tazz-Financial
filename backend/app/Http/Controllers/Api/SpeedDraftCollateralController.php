<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityCollateral;
use App\Models\FacilityCollateralJewelryDetail;
use App\Models\SpeedDraft;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SpeedDraftCollateralController extends Controller
{
    private const COLLATERAL_TYPES = ['Vehicle', 'Jewelry', 'Property', 'Other'];

    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->collateral()->with(['jewelryDetail', 'documents'])->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        [$data, $jewelry] = $this->validated($request);
        $row = $speedDraft->collateral()->create($data);
        if ($row->collateral_type === 'Jewelry' && $jewelry) {
            $row->jewelryDetail()->create($jewelry);
        }

        return response()->json($row->load('jewelryDetail'), 201);
    }

    public function update(Request $request, FacilityCollateral $collateral)
    {
        [$data, $jewelry] = $this->validated($request);
        $collateral->update($data);
        if ($collateral->collateral_type === 'Jewelry' && $jewelry) {
            FacilityCollateralJewelryDetail::updateOrCreate(['facility_collateral_id' => $collateral->id], $jewelry);
        }

        return $collateral->fresh()->load('jewelryDetail');
    }

    public function destroy(FacilityCollateral $collateral)
    {
        $collateral->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'collateral_type' => ['nullable', Rule::in(self::COLLATERAL_TYPES)],
            'collateral_category' => 'nullable|string|max:64',
            'description' => 'required|string|max:255',
            'weight_grams' => 'nullable|numeric|min:0',
            'purity' => 'nullable|string|max:16',
            'appraised_value' => 'nullable|numeric|min:0',
        ]);

        $type = $validated['collateral_type'] ?? 'Jewelry';

        $data = [
            'collateral_type' => $type,
            'collateral_category' => $validated['collateral_category'] ?? null,
            'description' => $validated['description'],
            'estimated_value' => $validated['appraised_value'] ?? null,
        ];

        $jewelry = $type === 'Jewelry' ? [
            'weight_grams' => $validated['weight_grams'] ?? null,
            'purity' => $validated['purity'] ?? null,
            'appraised_value' => $validated['appraised_value'] ?? null,
        ] : null;

        return [$data, $jewelry];
    }
}
