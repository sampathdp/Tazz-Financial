<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityCharge;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanChargeController extends Controller
{
    public function index(Loan $loan)
    {
        return $loan->charges()->with(['creator:id,name', 'category:id,name'])->orderByDesc('charged_at')->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'charge_category_id' => ['required', 'exists:charge_categories,id'],
            'due_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'charged_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $charge = $loan->charges()->create([
            'branch_id' => $loan->branch_id,
            'charge_category_id' => $data['charge_category_id'],
            'due_amount' => $data['due_amount'],
            'paid_amount' => $data['paid_amount'] ?? 0,
            'charged_at' => $data['charged_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($charge->load(['creator:id,name', 'category:id,name']), 201);
    }

    public function update(Request $request, FacilityCharge $charge)
    {
        $data = $request->validate([
            'charge_category_id' => ['sometimes', 'exists:charge_categories,id'],
            'due_amount' => 'sometimes|numeric|min:0',
            'paid_amount' => 'sometimes|numeric|min:0',
            'charged_at' => 'sometimes|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $hasAllocations = $charge->allocations()->exists();
        // paid_amount is meant to be driven only by real payment allocations
        // (see LoanPaymentAllocator) — editable by hand only before any
        // payment has actually been applied, so hand-editing can never
        // desync it from the allocation history a payment already created.
        if (array_key_exists('paid_amount', $data) && $hasAllocations) {
            return response()->json(['message' => 'This charge already has payments applied — its paid amount can no longer be edited directly.'], 422);
        }
        if (array_key_exists('due_amount', $data) && $data['due_amount'] < (float) $charge->paid_amount) {
            return response()->json(['message' => "Due amount can't be less than the amount already paid ({$charge->paid_amount})."], 422);
        }

        $charge->update($data);

        return $charge->load(['creator:id,name', 'category:id,name']);
    }

    public function destroy(FacilityCharge $charge)
    {
        if ($charge->allocations()->exists()) {
            return response()->json(['message' => 'This charge already has payments applied to it and can no longer be deleted — void the payment(s) first.'], 422);
        }
        $charge->delete();

        return response()->noContent();
    }
}
