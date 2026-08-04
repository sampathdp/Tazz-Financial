<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (module, action) cell in App\Support\Permissions'
 * MODULES x ACTIONS grid — the shared lookup role_permissions and
 * user_permission_overrides point at instead of each repeating the
 * module/action pair independently.
 */
class Permission extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['module', 'action'];

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function userPermissionOverrides()
    {
        return $this->hasMany(UserPermissionOverride::class);
    }
}
