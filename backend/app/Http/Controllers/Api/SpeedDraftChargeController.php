<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChargeCategory;
use App\Models\SpeedDraft;
use Illuminate\Http\Request;

/**
 * Other charges (Document / Visit / Legal ...) on a Speed Draft — the shared facility_charges table,
 * same as loans. Editing and deleting a charge reuse LoanChargeController::update()/destroy()
 * (they key on the FacilityCharge row, not on the facility type); see routes/api.php.
 * Payments against charges go through SpeedDraftPaymentController (payment_type 'Charges').
 */
class SpeedDraftChargeController extends Controller
{
    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->charges()->with(['creator:id,name', 'category:id,name'])->orderByDesc('charged_at')->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate([
            'charge_category_id' => ['required', 'exists:charge_categories,id'],
            'due_amount' => ['required', 'numeric', 'min:0', new \App\Rules\Money],
            'charged_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
        ]);

        // A Document charge gates disbursement, so it must carry a real amount.
        if ((float) $data['due_amount'] <= 0 && ChargeCategory::whereKey($data['charge_category_id'])->where('name', 'Document')->exists()) {
            return response()->json(['message' => 'A document charge must be greater than zero.'], 422);
        }
        if (in_array($speedDraft->status, ['Closed', 'Cancelled', 'Rejected'], true)) {
            return response()->json(['message' => "A charge cannot be added to a {$speedDraft->status} Speed Draft."], 422);
        }

        // paid_amount is only ever raised by a real payment - a charge can never be created already "paid".
        $charge = $speedDraft->charges()->create([
            'branch_id' => $speedDraft->branch_id,
            'charge_category_id' => $data['charge_category_id'],
            'due_amount' => $data['due_amount'],
            'paid_amount' => 0,
            'charged_at' => $data['charged_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($charge->load(['creator:id,name', 'category:id,name']), 201);
    }
}
