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
            'due_amount' => ['required', 'numeric', 'min:0', new \App\Rules\Money],
            'charged_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
        ]);

        // A Document charge gates disbursement, so it must carry a real amount.
        if ((float) $data['due_amount'] <= 0 && \App\Models\ChargeCategory::whereKey($data['charge_category_id'])->where('name', 'Document')->exists()) {
            return response()->json(['message' => 'A document charge must be greater than zero.'], 422);
        }

        if (in_array($loan->status, ['Closed', 'Cancelled', 'Rejected'], true)) {
            return response()->json(['message' => "A charge cannot be added to a {$loan->status} loan."], 422);
        }

        // paid_amount is only ever raised by a real payment (LoanPaymentAllocator) - a charge can never be created already "paid".
        $charge = $loan->charges()->create([
            'branch_id' => $loan->branch_id,
            'charge_category_id' => $data['charge_category_id'],
            'due_amount' => $data['due_amount'],
            'paid_amount' => 0,
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
            'due_amount' => ['sometimes', 'numeric', 'min:0', new \App\Rules\Money],
            'paid_amount' => ['sometimes', 'numeric', 'min:0', new \App\Rules\Money],
            'charged_at' => 'sometimes|date',
            'notes' => 'nullable|string|max:255',
        ]);

        // paid_amount is driven only by real payment allocations (LoanPaymentAllocator): setting it by hand would
        // mark a charge paid with no receipt, no cash and no ledger entry, so any value sent is ignored.
        unset($data['paid_amount']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($charge, &$data) {
            // Same lock a payment takes, so a charge is never edited while a receipt is being allocated to it.
            Loan::whereKey($charge->credit_facility_id)->lockForUpdate()->first();
            $charge->refresh();
            if (array_key_exists('due_amount', $data) && $data['due_amount'] < (float) $charge->paid_amount) {
                throw \Illuminate\Validation\ValidationException::withMessages(['due_amount' => "Due amount can't be less than the amount already paid ({$charge->paid_amount})."]);
            }
            $charge->update($data);
        });

        return $charge->load(['creator:id,name', 'category:id,name']);
    }

    public function destroy(FacilityCharge $charge)
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($charge) {
            Loan::whereKey($charge->credit_facility_id)->lockForUpdate()->first();
            if ($charge->allocations()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['charge' => 'This charge already has payments applied to it and can no longer be deleted — void the payment(s) first.']);
            }
            $charge->delete();
        });

        return response()->noContent();
    }
}
