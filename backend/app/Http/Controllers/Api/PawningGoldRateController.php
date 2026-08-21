<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PawningGoldRate;
use Illuminate\Http\Request;

class PawningGoldRateController extends Controller
{
    /** Most recent rates first — optionally narrowed to one karat, since that's how the appraisal screen looks one up. */
    public function index(Request $request)
    {
        return PawningGoldRate::with('branch:id,name,code')
            ->when($request->query('karat'), fn ($q, $karat) => $q->where('karat', $karat))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /** The latest rate on/before today for a karat — organisation-wide (branch_id null) unless a branch-specific rate exists for that date. Used by the appraisal screen to prefill rate_per_gram. */
    public function latest(Request $request)
    {
        $data = $request->validate([
            'karat' => 'required|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
            'as_of' => 'nullable|date',
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        $rate = PawningGoldRate::where('karat', $data['karat'])
            ->where('effective_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('branch_id', $data['branch_id'] ?? null)->when(!empty($data['branch_id']), fn ($q2) => $q2->orWhereNull('branch_id')))
            ->orderByDesc('branch_id')
            ->orderByDesc('effective_date')
            ->first();

        return response()->json(['rate' => $rate]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'effective_date' => 'required|date',
            'karat' => 'required|numeric|min:0.01|max:99.99',
            'rate_per_gram' => 'required|numeric|min:0.01',
            'branch_id' => 'nullable|exists:branches,id',
        ]);
        $data['created_by'] = $request->user()->id;
        $rate = PawningGoldRate::create($data);

        return response()->json($rate->load('branch:id,name,code'), 201);
    }

    public function destroy(PawningGoldRate $pawningGoldRate)
    {
        $pawningGoldRate->delete();

        return response()->noContent();
    }
}
