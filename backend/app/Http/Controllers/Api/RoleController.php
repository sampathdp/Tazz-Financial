<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index()
    {
        return Role::withCount('users')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'description' => 'nullable|string|max:255',
            'can_view_all_branches' => 'boolean',
        ]);

        $slug = Str::slug($data['name'], '_');
        $role = Role::create($data + [
            'slug' => $slug,
            'is_system' => false,
            'can_view_all_branches' => $data['can_view_all_branches'] ?? false,
        ]);

        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be modified.'], 422);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:64',
            'description' => 'nullable|string|max:255',
            'can_view_all_branches' => 'boolean',
        ]);

        $role->update($data);

        return $role;
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'This role has users assigned and cannot be deleted.'], 422);
        }
        $role->delete();

        return response()->noContent();
    }
}
