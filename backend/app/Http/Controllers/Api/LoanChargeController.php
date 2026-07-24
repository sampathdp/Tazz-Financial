<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanCharge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanChargeController extends Controller
{
    private const CHARGE_TYPES = ['Document', 'Legal', 'Valuation', 'Other'];

    public function index(Loan $loan)
    {
        return $loan->charges()->with('creator:id,name')->orderByDesc('charged_at')->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'charge_type' => ['required', Rule::in(self::CHARGE_TYPES)],
            'due_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'charged_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $charge = $loan->charges()->create([
            'branch_id' => $loan->branch_id,
            'charge_type' => $data['charge_type'],
            'due_amount' => $data['due_amount'],
            'paid_amount' => $data['paid_amount'] ?? 0,
            'charged_at' => $data['charged_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($charge->load('creator:id,name'), 201);
    }

    public function update(Request $request, LoanCharge $charge)
    {
        $data = $request->validate([
            'charge_type' => ['sometimes', Rule::in(self::CHARGE_TYPES)],
            'due_amount' => 'sometimes|numeric|min:0',
            'paid_amount' => 'sometimes|numeric|min:0',
            'charged_at' => 'sometimes|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $charge->update($data);

        return $charge->load('creator:id,name');
    }

    public function destroy(LoanCharge $charge)
    {
        $charge->delete();

        return response()->noContent();
    }
}
