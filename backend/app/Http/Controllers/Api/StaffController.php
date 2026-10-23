<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        return Staff::with(['branch:id,name,code', 'user:id,role_id', 'user.role:id,name'])
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('staff_code')
            ->get()
            ->map(fn (Staff $s) => $this->present($s));
    }

    /** Lightweight active roster for payee / commission pickers. */
    public function options(Request $request)
    {
        return Staff::where('status', 'Active')
            ->search($request->query('search'))
            ->orderBy('name')
            ->get(['id', 'staff_code', 'name', 'phone', 'nic_no']);
    }

    public function store(Request $request)
    {
        $staff = Staff::createWithCode($this->validated($request));

        return response()->json($this->present($staff->load(['branch:id,name,code', 'user:id,role_id', 'user.role:id,name'])), 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $staff->update($this->validated($request, $staff));

        return $this->present($staff->load(['branch:id,name,code', 'user:id,role_id', 'user.role:id,name']));
    }

    public function destroy(Staff $staff)
    {
        if ($staff->user_id || $staff->commissions()->exists() || $staff->expenses()->exists()) {
            return response()->json(['message' => 'This staff member has a login or payments recorded against them. Mark them Inactive instead.'], 422);
        }
        $staff->delete();

        return response()->noContent();
    }

    /** Creates the login (user) for this staff member and links it. Same rules as User Management. */
    public function createLogin(Request $request, Staff $staff)
    {
        if (!$request->user()->hasPermission('User Management', 'Create')) {
            return response()->json(['message' => 'You do not have permission to create user logins.'], 403);
        }
        if ($staff->user_id) {
            return response()->json(['message' => 'This staff member already has a login.'], 422);
        }
        if (!$staff->email) {
            return response()->json(['message' => 'Add an email to the staff record first - it is the login name.'], 422);
        }
        $request->merge(['name' => $staff->name, 'email' => $staff->email, 'branch_id' => $request->input('branch_id', $staff->branch_id)]);
        $response = app(UserController::class)->store($request);
        $staff->update(['user_id' => $response->getData()->id]);

        return response()->json($this->present($staff->load(['branch:id,name,code', 'user:id,role_id', 'user.role:id,name'])), 201);
    }

    private function validated(Request $request, ?Staff $staff = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'nic_no' => ['nullable', 'string', 'max:20', Rule::unique('staff', 'nic_no')->ignore($staff?->id)],
            'phone' => 'nullable|string|max:20',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('staff', 'email')->ignore($staff?->id)],
            'designation' => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }

    private function present(Staff $s): array
    {
        return [
            'id' => $s->id,
            'staff_code' => $s->staff_code,
            'name' => $s->name,
            'nic_no' => $s->nic_no,
            'phone' => $s->phone,
            'email' => $s->email,
            'designation' => $s->designation,
            'branch_id' => $s->branch_id,
            'branch' => $s->branch,
            'user_id' => $s->user_id,
            'has_login' => (bool) $s->user_id,
            'role_name' => $s->user?->role?->name,
            'status' => $s->status,
        ];
    }
}
