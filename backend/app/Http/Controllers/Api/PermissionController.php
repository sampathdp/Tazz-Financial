<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PermissionController extends Controller
{
    /** Per-user override matrix (effective grid + which cells are overridden). */
    public function index()
    {
        $users = User::with('role')->orderBy('id')->get(['id', 'name', 'email', 'role_id']);

        $matrix = [];
        $overrides = [];
        foreach ($users as $user) {
            $matrix[$user->id] = Permissions::gridForUser($user);
            $overrides[$user->id] = Permissions::overridesForUser($user);
        }

        return [
            'users' => $users->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $u->role?->slug, 'roleName' => $u->role?->name,
            ]),
            'modules' => Permissions::MODULES,
            'actions' => Permissions::ACTIONS,
            'matrix' => $matrix,
            'overrides' => $overrides,
        ];
    }

    /**
     * Replace permission overrides for a single user.
     * Body: { modules: { "Module Name": { "View": true|false|null, ... } } }
     */
    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'modules' => 'required|array',
        ]);
        Permissions::saveForUser($user, $data['modules']);

        Log::channel('audit')->info('user_permissions_updated', [
            'user_id' => $user->id,
            'by' => optional($request->user())->id,
        ]);

        return ['ok' => true, 'matrix' => Permissions::gridForUser($user), 'overrides' => Permissions::overridesForUser($user)];
    }

    /** Grid for a single role, for the Role Management matrix editor. */
    public function role(Role $role)
    {
        return [
            'role' => $role,
            'modules' => Permissions::MODULES,
            'actions' => Permissions::ACTIONS,
            'matrix' => Permissions::gridForRole($role),
        ];
    }

    /**
     * Replace the permission grid for a role.
     * Body: { modules: { "Module Name": { "View": true, ... } } }
     */
    public function updateRole(Request $request, Role $role)
    {
        $data = $request->validate([
            'modules' => 'required|array',
        ]);
        Permissions::saveForRole($role, $data['modules']);

        Log::channel('audit')->info('role_permissions_updated', [
            'role_id' => $role->id,
            'by' => optional($request->user())->id,
        ]);

        return ['ok' => true, 'matrix' => Permissions::gridForRole($role)];
    }
}
