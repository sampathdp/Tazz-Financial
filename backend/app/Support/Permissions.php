<?php

namespace App\Support;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Cache;

class Permissions
{
    /**
     * Canonical list of pages/screens the system exposes.
     * Add new entries here when new modules ship (Loan, Pawning, Cashbook, ...).
     */
    public const MODULES = [
        'Dashboard',
        'Branch Management', 'Role Management', 'User Management', 'User Permission',
        'Company Profile',
        'Customer Management',
        'Loan Types',
        'Loan Applications',
        'Active Loans',
        'Loan Payments',
        'Document Tracking',
    ];

    public const ACTIONS = ['View', 'Create', 'Edit', 'Delete', 'Approve', 'Export'];

    /** Cache TTL (seconds) for a user's resolved permission list. */
    private const CACHE_TTL = 300;

    /** Empty {module: {action: false}} grid. */
    private static function emptyGrid(): array
    {
        $grid = [];
        foreach (self::MODULES as $m) {
            foreach (self::ACTIONS as $a) {
                $grid[$m][$a] = false;
            }
        }

        return $grid;
    }

    /** {module: {action: bool}} grid built from a role's stored role_permissions rows. */
    public static function gridForRole(Role $role): array
    {
        $grid = self::emptyGrid();
        if ($role->isAdmin()) {
            foreach (self::MODULES as $m) {
                foreach (self::ACTIONS as $a) {
                    $grid[$m][$a] = true;
                }
            }

            return $grid;
        }
        foreach (RolePermission::where('role_id', $role->id)->get() as $p) {
            if (isset($grid[$p->module][$p->action])) {
                $grid[$p->module][$p->action] = true;
            }
        }

        return $grid;
    }

    /**
     * Replace all permissions for a role with the given matrix slice:
     *   {module: {action: bool}}
     * No-op for the system admin role (its access is implicit and not stored).
     */
    public static function saveForRole(Role $role, array $modules): void
    {
        if ($role->isAdmin()) {
            return;
        }
        RolePermission::where('role_id', $role->id)->delete();
        $rows = [];
        foreach ($modules as $module => $actions) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }
            foreach ($actions as $action => $enabled) {
                if (!in_array($action, self::ACTIONS, true)) {
                    continue;
                }
                if ($enabled) {
                    $rows[] = [
                        'role_id' => $role->id, 'module' => $module, 'action' => $action,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        if ($rows) {
            RolePermission::insert($rows);
        }
        // Role changes ripple to every member — bust each of their cached grids.
        foreach (User::where('role_id', $role->id)->pluck('id') as $userId) {
            Cache::forget("perm:user:{$userId}");
        }
    }

    /**
     * Effective {module: {action: bool}} matrix for a single user: the
     * user's role grid with per-user overrides (user_permissions) applied on
     * top. Admin users get every permission.
     */
    public static function gridForUser(User $user): array
    {
        if ($user->isAdmin()) {
            $grid = self::emptyGrid();
            foreach (self::MODULES as $m) {
                foreach (self::ACTIONS as $a) {
                    $grid[$m][$a] = true;
                }
            }

            return $grid;
        }

        $grid = $user->role ? self::gridForRole($user->role) : self::emptyGrid();

        foreach (UserPermission::where('user_id', $user->id)->get() as $p) {
            if (isset($grid[$p->module][$p->action])) {
                $grid[$p->module][$p->action] = (bool) $p->allowed;
            }
        }

        return $grid;
    }

    /**
     * Which cells in a user's effective grid are explicit overrides (as
     * opposed to inherited from their role) — {module: {action: bool}}.
     * Used by the UI to visually distinguish overridden cells.
     */
    public static function overridesForUser(User $user): array
    {
        $overrides = [];
        if ($user->isAdmin()) {
            return $overrides;
        }
        foreach (UserPermission::where('user_id', $user->id)->get() as $p) {
            $overrides[$p->module][$p->action] = true;
        }

        return $overrides;
    }

    /**
     * Replace a user's permission overrides with the given slice:
     *   {module: {action: true|false|null}}
     * true/false upserts an explicit override, null/missing clears it back
     * to "inherit from role". No-op for admin users.
     */
    public static function saveForUser(User $user, array $modules): void
    {
        if ($user->isAdmin()) {
            return;
        }
        foreach ($modules as $module => $actions) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }
            foreach ($actions as $action => $value) {
                if (!in_array($action, self::ACTIONS, true)) {
                    continue;
                }
                if ($value === null) {
                    UserPermission::where('user_id', $user->id)
                        ->where('module', $module)->where('action', $action)->delete();
                } else {
                    UserPermission::updateOrCreate(
                        ['user_id' => $user->id, 'module' => $module, 'action' => $action],
                        ['allowed' => (bool) $value]
                    );
                }
            }
        }
        Cache::forget("perm:user:{$user->id}");
    }

    /**
     * Flat list of "Module:Action" strings the user has access to.
     */
    public static function forUser(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return Cache::remember("perm:user:{$user->id}", self::CACHE_TTL, function () use ($user) {
            $flat = [];
            foreach (self::gridForUser($user) as $module => $actions) {
                foreach ($actions as $action => $enabled) {
                    if ($enabled) {
                        $flat[] = "{$module}:{$action}";
                    }
                }
            }

            return $flat;
        });
    }

    public static function userCan(?User $user, string $module, string $action): bool
    {
        if (!$user) {
            return false;
        }

        return in_array("{$module}:{$action}", self::forUser($user), true);
    }
}
