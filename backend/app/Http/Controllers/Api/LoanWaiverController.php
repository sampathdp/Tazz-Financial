<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanWaiver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanWaiverController extends Controller
{
    private const WAIVER_TYPES = ['Interest', 'Penalty', 'Charge', 'Principal'];

    public function index(Loan $loan)
    {
        return $loan->waivers()->with(['approver:id,name', 'creator:id,name'])->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'waiver_type' => ['required', Rule::in(self::WAIVER_TYPES)],
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
            'waived_at' => 'nullable|date',
        ]);

        $waiver = $loan->waivers()->create([
            'waiver_type' => $data['waiver_type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'approved_by' => $request->user()->id,
            'waived_at' => $data['waived_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($waiver->load(['approver:id,name', 'creator:id,name']), 201);
    }

    public function destroy(LoanWaiver $waiver)
    {
        $waiver->delete();

        return response()->noContent();
    }
}
