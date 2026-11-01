<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Permissions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return User::with(['role', 'branch'])->orderBy('id')->get()
            ->map(fn (User $u) => $this->present($u));
    }

    /**
     * Lightweight roster of active Marketing Executives — the "sales
     * executive" picklist used by Loan/Pawning "assign officer" dropdowns.
     * Deliberately not gated behind `perm:User Management,View`: roles that
     * can create a loan application (e.g. Staff) may not be allowed to
     * browse the full user list, but still need to pick an officer.
     */
    public function marketingExecutives()
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', 'marketing_executive'))
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Lightweight roster of every active user — the "custodian" picklist
     * used by Petty Cash Funds. Same reasoning as marketingExecutives():
     * a role that can open a petty cash float may not have `perm:User
     * Management,View`, but still needs to pick who's accountable for it.
     */
    public function active()
    {
        return User::where('status', 'Active')->orderBy('name')->get(['id', 'name']);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $this->guardAdminAssignment($request, $data['role_id']);
        $this->guardRoleAssignable($request, $data['role_id']);
        $this->guardBranchAssignable($request, $data['branch_id'] ?? null);
        $this->guardBranchRequirement($data);

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => $data['role_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'status' => $data['status'] ?? 'Active',
            ]);

            // Every login belongs to a staff member: link the one with this email, else register a new one.
            $staff = \App\Models\Staff::where('email', $user->email)->whereNull('user_id')->first();
            if ($staff) {
                $staff->update(['user_id' => $user->id]);
            } else {
                \App\Models\Staff::createWithCode([
                    'name' => $user->name, 'email' => $user->email, 'branch_id' => $user->branch_id,
                    'user_id' => $user->id, 'status' => $user->status,
                ]);
            }

            return $user;
        });

        return response()->json($this->present($user->load(['role', 'branch'])), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        $this->guardManageable($request, $user);
        if (array_key_exists('branch_id', $data)) {
            $this->guardBranchAssignable($request, $data['branch_id']);
        }
        if (array_key_exists('role_id', $data)) {
            $this->guardRoleAssignable($request, $data['role_id']);
            $this->guardAdminAssignment($request, $data['role_id']);
            $this->guardSelfRoleChange($request, $user, $data['role_id']);
            $this->guardLastAdminDemotion($user, $data['role_id']);
            $user->role_id = $data['role_id'];
        }
        if (array_key_exists('branch_id', $data)) {
            $user->branch_id = $data['branch_id'];
        }
        $this->guardBranchRequirement(['role_id' => $user->role_id, 'branch_id' => $user->branch_id]);

        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['email'])) $user->email = $data['email'];
        if (array_key_exists('status', $data)) $user->status = $data['status'] ?? $user->status;
        if (!empty($data['password'])) $user->password = Hash::make($data['password']);
        $revokeSessions = $user->isDirty(['password', 'status', 'role_id', 'branch_id']);
        $user->save();
        // A disabled, re-roled, moved or re-passworded account must not keep working on a token issued before the change.
        if ($revokeSessions) {
            $user->tokens()->delete();
        }

        return $this->present($user->load(['role', 'branch']));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        $this->guardManageable($request, $user);
        $this->guardLastAdmin($user, 'delete');
        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $required = $user ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:255",
            'email' => [$required, 'email', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', new \App\Rules\StrongPassword($request->input('email', $user?->email), $request->input('name', $user?->name))],
            'role_id' => "{$required}|integer|exists:roles,id",
            'branch_id' => 'nullable|integer|exists:branches,id',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);
    }

    /**
     * A non-admin may only manage ordinary users of their own branch (and its centers): never an administrator,
     * a user whose role sees every branch, or a user of another branch. Without this a branch manager with
     * User Management,Edit could reset an administrator's password and take over the system.
     */
    private function guardManageable(Request $request, User $target): void
    {
        $actor = $request->user();
        if (!$actor || $actor->isAdmin() || $actor->id === $target->id) {
            return;
        }
        $target->loadMissing('role');
        $reachable = $actor->role && $actor->role->can_view_all_branches
            || ($actor->branch_id && $target->branch_id && in_array((int) $target->branch_id, \App\Support\CurrentBranch::withCenters((int) $actor->branch_id), true));
        $stronger = $target->isAdmin() || ($target->role && $target->role->can_view_all_branches && !($actor->role && $actor->role->can_view_all_branches));
        abort_if($stronger || !$reachable, 403, 'You can only manage ordinary users of your own branch.');
    }

    /** A non-admin may only hand out a role whose permissions they all hold themselves, and not an all-branches role they lack. */
    private function guardRoleAssignable(Request $request, int $roleId): void
    {
        $actor = $request->user();
        if (!$actor || $actor->isAdmin()) {
            return;
        }
        $role = Role::find($roleId);
        if (!$role || ($actor->role_id === $role->id)) {
            return;
        }
        abort_if($role->can_view_all_branches && !($actor->role && $actor->role->can_view_all_branches), 403, 'You cannot assign a role that sees every branch.');
        $held = Permissions::forUser($actor);
        foreach (Permissions::gridForRole($role) as $module => $actions) {
            foreach ($actions as $action => $enabled) {
                abort_if($enabled && !in_array("{$module}:{$action}", $held, true), 403, 'You cannot assign a role with permissions you do not hold.');
            }
        }
    }

    /** A branch-confined user can only place people in their own branch or its centers. */
    private function guardBranchAssignable(Request $request, $branchId): void
    {
        $actor = $request->user();
        if (!$actor || $actor->isAdmin() || ($actor->role && $actor->role->can_view_all_branches) || $branchId === null) {
            return;
        }
        abort_unless($actor->branch_id && in_array((int) $branchId, \App\Support\CurrentBranch::withCenters((int) $actor->branch_id), true), 403, 'You can only assign users to your own branch.');
    }

    /** A non-admin cannot re-role themselves into a role that holds permissions they lack. */
    private function guardSelfRoleChange(Request $request, User $user, int $roleId): void
    {
        $actor = $request->user();
        if ($actor?->isAdmin() || $actor?->id !== $user->id || $roleId === $user->role_id) {
            return;
        }
        $role = Role::find($roleId);
        $held = Permissions::forUser($actor);
        $grid = $role ? Permissions::gridForRole($role) : [];
        foreach ($grid as $module => $actions) {
            foreach ($actions as $action => $enabled) {
                abort_if($enabled && !in_array("{$module}:{$action}", $held, true), 403, 'You cannot move yourself to a role with permissions you do not hold.');
            }
        }
    }

    /** Only an existing admin may hand out the admin role. */
    private function guardAdminAssignment(Request $request, int $roleId): void
    {
        $role = Role::find($roleId);
        if ($role && $role->isAdmin() && !$request->user()?->isAdmin()) {
            throw ValidationException::withMessages([
                'role_id' => 'Only an administrator can assign the administrator role.',
            ]);
        }
    }

    /** Non-branch-scoped roles (can_view_all_branches) don't require a branch; everyone else does. */
    private function guardBranchRequirement(array $data): void
    {
        if (empty($data['role_id'])) {
            return;
        }
        $role = Role::find($data['role_id']);
        if ($role && !$role->can_view_all_branches && empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => 'A branch is required for this role.',
            ]);
        }
    }

    private function guardLastAdminDemotion(User $user, int $newRoleId): void
    {
        if ($user->isAdmin() && $newRoleId !== $user->role_id) {
            $this->guardLastAdmin($user, 'change the role of');
        }
    }

    private function guardLastAdmin(User $user, string $action): void
    {
        if (!$user->isAdmin()) {
            return;
        }
        $adminRoleId = $user->role_id;
        $remaining = User::where('role_id', $adminRoleId)->where('id', '!=', $user->id)->count();
        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role_id' => "Cannot {$action} the last remaining administrator.",
            ]);
        }
    }

    private function present(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'roleId' => $u->role_id, 'role' => $u->role?->slug, 'roleName' => $u->role?->name,
            'branchId' => $u->branch_id, 'branch' => $u->branch ? [
                'id' => $u->branch->id, 'name' => $u->branch->name, 'code' => $u->branch->code,
            ] : null,
            'status' => $u->status, 'createdAt' => $u->created_at,
        ];
    }
}
