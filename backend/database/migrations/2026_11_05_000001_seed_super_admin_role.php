<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the "Super Admin" system role. Role::isAdmin() treats it like the
 * built-in admin role: implicit full access to every module, cannot be
 * edited or deleted. Idempotent (updateOrCreate by slug).
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::updateOrCreate(['slug' => 'super_admin'], [
            'name' => 'Super Admin',
            'description' => 'Full, implicit access to every module. Cannot be edited or deleted.',
            'is_system' => true,
            'can_view_all_branches' => true,
        ]);
    }

    public function down(): void
    {
        $role = Role::where('slug', 'super_admin')->first();
        if (!$role) {
            return;
        }
        User::where('role_id', $role->id)->update(['role_id' => null]);
        $role->delete();
    }
};
