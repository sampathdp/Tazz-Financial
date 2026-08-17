<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only CRUD for the approval_types registry — see the migration's
 * docblock and ApprovalType::isAuthorized(). Gated to the 'admin' role only
 * (see routes/api.php), same as the Approvals inbox.
 */
class ApprovalTypeController extends Controller
{
    public function index()
    {
        return ApprovalType::with('approvers:id,name')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('approval_types', 'code')],
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'required_approvals' => ['nullable', Rule::in([1, 2])],
        ]);
        $type = ApprovalType::create($data + ['is_system' => false]);

        return response()->json($type->load('approvers:id,name'), 201);
    }

    /** Code is deliberately not editable here — it's the string a controller
     *  checks against (ApprovalType::isAuthorized('loan_cancel', ...)), so
     *  changing it out from under that check would silently break the gate. */
    public function update(Request $request, ApprovalType $approvalType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'required_approvals' => ['nullable', Rule::in([1, 2])],
        ]);
        $approvalType->update($data);

        return $approvalType->load('approvers:id,name');
    }

    public function destroy(ApprovalType $approvalType)
    {
        if ($approvalType->is_system) {
            return response()->json(['message' => 'This approval type is built into the system and cannot be deleted.'], 422);
        }
        $approvalType->delete();

        return response()->noContent();
    }

    /** Replaces the full approver list for this type. Body: { user_ids: number[] } */
    public function updateApprovers(Request $request, ApprovalType $approvalType)
    {
        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $approvalType->approvers()->sync($data['user_ids'] ?? []);

        return $approvalType->load('approvers:id,name');
    }
}
